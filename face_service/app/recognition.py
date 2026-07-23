import base64
import hashlib
import inspect
import logging
import time
from dataclasses import dataclass

import cv2
import numpy as np
from fastapi import HTTPException, UploadFile
from PIL import Image, UnidentifiedImageError

try:
    from deepface import DeepFace
except Exception as exc:  # pragma: no cover - startup will explain the missing dependency.
    DeepFace = None
    DEEPFACE_IMPORT_ERROR = exc
else:
    DEEPFACE_IMPORT_ERROR = None

try:
    from deepface.modules.exceptions import FaceNotDetected
except ImportError:  # pragma: no cover - older DeepFace versions may not expose this class.
    FaceNotDetected = ValueError

try:
    import onnxruntime as ort
except Exception as exc:  # pragma: no cover - optional until a model is configured.
    ort = None
    ONNXRUNTIME_IMPORT_ERROR = exc
else:
    ONNXRUNTIME_IMPORT_ERROR = None

from .config import Settings
from .database import FaceStore

logger = logging.getLogger(__name__)
_ANTI_SPOOF_SESSION: tuple[str, object] | None = None


@dataclass
class FaceAnalysis:
    image_sha256: str
    rgb: np.ndarray
    facial_area: dict
    embedding: np.ndarray
    quality: dict
    spoofing: dict


@dataclass
class FaceExtraction:
    faces: list[dict]
    diagnostics: dict


async def read_upload_image(upload: UploadFile) -> tuple[bytes, np.ndarray]:
    content = await upload.read()
    if not content:
        logger.warning(
            "face_upload_rejected reason=empty filename=%s content_type=%s",
            upload.filename,
            upload.content_type,
        )
        raise HTTPException(status_code=422, detail="Upload an image.")

    try:
        from io import BytesIO

        with Image.open(BytesIO(content)) as opened:
            rgb = np.array(opened.convert("RGB"))
    except UnidentifiedImageError as exc:
        logger.warning(
            "face_upload_rejected reason=invalid_image filename=%s content_type=%s bytes=%s",
            upload.filename,
            upload.content_type,
            len(content),
        )
        raise HTTPException(status_code=422, detail="Upload a valid image file.") from exc

    logger.info(
        "face_upload_loaded filename=%s content_type=%s bytes=%s width=%s height=%s",
        upload.filename,
        upload.content_type,
        len(content),
        int(rgb.shape[1]),
        int(rgb.shape[0]),
    )
    return content, rgb


def ensure_recognizer_available() -> None:
    if DeepFace is None:
        detail = "DeepFace is not installed. Install service requirements first."
        if DEEPFACE_IMPORT_ERROR is not None:
            detail = f"{detail} Import error: {DEEPFACE_IMPORT_ERROR}"

        logger.error("deepface_unavailable error=%s", DEEPFACE_IMPORT_ERROR)
        raise HTTPException(
            status_code=503,
            detail=detail,
        )


def face_quality(rgb: np.ndarray, facial_area: dict) -> dict:
    x = int(facial_area.get("x", 0))
    y = int(facial_area.get("y", 0))
    w = int(facial_area.get("w", 0))
    h = int(facial_area.get("h", 0))
    top, right, bottom, left = y, x + w, y + h, x
    face = rgb[max(top, 0) : max(bottom, 0), max(left, 0) : max(right, 0)]
    gray = cv2.cvtColor(face, cv2.COLOR_RGB2GRAY) if face.size else np.array([])
    blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var()) if gray.size else 0.0
    brightness = float(np.mean(gray)) if gray.size else 0.0

    return {
        "width": int(max(0, w)),
        "height": int(max(0, h)),
        "brightness": round(brightness, 2),
        "blur_score": round(blur_score, 2),
        "face_confidence": float(facial_area.get("confidence", 0.0) or 0.0),
    }


def rgb_to_bgr(rgb: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)


def cosine_distance(left: np.ndarray, right: np.ndarray) -> float:
    left_norm = float(np.linalg.norm(left))
    right_norm = float(np.linalg.norm(right))

    if left_norm == 0.0 or right_norm == 0.0:
        return 1.0

    similarity = float(np.dot(left, right) / (left_norm * right_norm))
    return 1.0 - max(-1.0, min(1.0, similarity))


def confidence_from_distance(distance: float, threshold: float) -> float:
    if distance >= threshold:
        return 0.0

    return round(max(0.0, min(1.0, 1.0 - (distance / threshold))), 4)


def quality_score(quality: dict, settings: Settings) -> float:
    size_score = min(
        1.0,
        min(float(quality.get("width", 0)), float(quality.get("height", 0)))
        / max(1.0, float(settings.min_face_size)),
    )
    brightness = float(quality.get("brightness", 0.0))
    if settings.min_brightness <= brightness <= settings.max_brightness:
        brightness_score = 1.0
    else:
        target = settings.min_brightness if brightness < settings.min_brightness else settings.max_brightness
        brightness_score = max(0.0, 1.0 - (abs(brightness - target) / max(1.0, target)))

    blur_score = min(1.0, float(quality.get("blur_score", 0.0)) / max(1.0, float(settings.min_blur_score)))

    return round((size_score * 0.35) + (brightness_score * 0.3) + (blur_score * 0.35), 4)


def liveness_score_from_spoofing(spoofing: dict) -> float:
    if not spoofing.get("checked"):
        return 0.55

    if spoofing.get("is_real"):
        score = spoofing.get("score")
        return round(max(0.65, min(1.0, float(score))) if score is not None else 0.85, 4)

    score = spoofing.get("score")
    return round(max(0.0, min(0.35, 1.0 - float(score))) if score is not None else 0.15, 4)


def compare_probe_to_rows(probe: np.ndarray, rows: list[dict], settings: Settings) -> dict:
    if not rows:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "message": "No enrolled face embeddings are available.",
        }

    best_by_employee: dict[str, float] = {}
    for row in rows:
        distance = cosine_distance(row["embedding"], probe)
        current = best_by_employee.get(row["employee_id"])
        if current is None or distance < current:
            best_by_employee[row["employee_id"]] = distance

    ranked = sorted(best_by_employee.items(), key=lambda item: item[1])
    best_employee_id, best_distance = ranked[0]
    next_distance = ranked[1][1] if len(ranked) > 1 else None
    margin = None if next_distance is None else round(next_distance - best_distance, 4)
    confidence = confidence_from_distance(best_distance, settings.match_threshold)

    if best_distance > settings.match_threshold:
        return {
            "matched": False,
            "employee_id": None,
            "candidate_employee_id": best_employee_id,
            "confidence": 0.0,
            "distance": round(best_distance, 4),
            "margin": margin,
            "message": "Face not recognized.",
        }

    if margin is not None and margin < settings.ambiguous_margin:
        return {
            "matched": False,
            "employee_id": None,
            "candidate_employee_id": best_employee_id,
            "confidence": confidence,
            "distance": round(best_distance, 4),
            "margin": margin,
            "message": "Face match is ambiguous. Please try again.",
        }

    return {
        "matched": True,
        "employee_id": best_employee_id,
        "confidence": confidence,
        "distance": round(best_distance, 4),
        "margin": margin,
        "message": "Face matched.",
    }


def frame_diagnostics(rgb: np.ndarray) -> dict:
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    return {
        "width": int(rgb.shape[1]),
        "height": int(rgb.shape[0]),
        "brightness": round(float(np.mean(gray)), 2),
        "blur_score": round(float(cv2.Laplacian(gray, cv2.CV_64F).var()), 2),
    }


def quality_rejection_message(quality: dict, settings: Settings) -> str | None:
    if quality["width"] < settings.min_face_size or quality["height"] < settings.min_face_size:
        return "Move closer."

    if quality["brightness"] < settings.min_brightness:
        return "Too dark."

    if quality["brightness"] > settings.max_brightness:
        return "Reduce glare."

    if quality["blur_score"] < settings.min_blur_score:
        return "Hold still."

    return None


def detector_backends(settings: Settings) -> list[str]:
    backends = [settings.detector_backend]

    if settings.fallback_detector_backend.strip():
        backends.append(settings.fallback_detector_backend.strip())

    for backend in settings.diagnostic_detector_backends.split(","):
        backend = backend.strip()
        if backend:
            backends.append(backend)

    return list(dict.fromkeys(backends))


def save_failed_detection_frame(
    rgb: np.ndarray,
    settings: Settings,
    image_sha256: str | None,
    context: str,
) -> str | None:
    if not settings.save_failed_detection_frames:
        return None

    settings.debug_frame_path.mkdir(parents=True, exist_ok=True)
    prefix = image_sha256[:12] if image_sha256 else str(int(time.time() * 1000))
    filename = f"{context}_{prefix}.jpg"
    path = settings.debug_frame_path / filename
    cv2.imwrite(str(path), rgb_to_bgr(rgb))
    return str(path)


def deepface_supports_anti_spoofing() -> bool:
    if DeepFace is None:
        return False

    try:
        signature = inspect.signature(DeepFace.extract_faces)
    except (TypeError, ValueError):
        return True

    return (
        "anti_spoofing" in signature.parameters
        or any(
            parameter.kind == inspect.Parameter.VAR_KEYWORD
            for parameter in signature.parameters.values()
        )
    )


def is_known_anti_spoofing_error(exc: Exception) -> bool:
    message = str(exc).lower()
    return (
        "anti_spoof" in message
        or "anti spoof" in message
        or "unexpected keyword argument 'anti_spoofing'" in message
        or "got an unexpected keyword argument" in message
    )


def extract_faces_once(rgb: np.ndarray, detector_backend: str, anti_spoofing: bool) -> list[dict]:
    kwargs = {
        "img_path": rgb_to_bgr(rgb),
        "detector_backend": detector_backend,
        "enforce_detection": True,
        "align": True,
        "normalize_face": False,
    }

    if anti_spoofing and deepface_supports_anti_spoofing():
        kwargs["anti_spoofing"] = True

    try:
        return DeepFace.extract_faces(**kwargs)
    except FaceNotDetected:
        return []
    except (RuntimeError, TypeError) as exc:
        if anti_spoofing and is_known_anti_spoofing_error(exc):
            raise RuntimeError(f"anti_spoofing_unavailable: {exc}") from exc
        raise
    except ValueError as exc:
        if "could not be detected" in str(exc).lower():
            return []
        if anti_spoofing and is_known_anti_spoofing_error(exc):
            raise RuntimeError(f"anti_spoofing_unavailable: {exc}") from exc
        raise HTTPException(status_code=422, detail=str(exc)) from exc


def expanded_face_crop(rgb: np.ndarray, facial_area: dict, scale: float) -> np.ndarray | None:
    frame_height, frame_width = rgb.shape[:2]
    x = float(facial_area.get("x", 0) or 0)
    y = float(facial_area.get("y", 0) or 0)
    width = float(facial_area.get("w", 0) or 0)
    height = float(facial_area.get("h", 0) or 0)

    if width <= 0 or height <= 0:
        return None

    scale = max(1.0, float(scale or 1.0))
    center_x = x + (width / 2.0)
    center_y = y + (height / 2.0)
    crop_size = max(width, height) * scale

    left = max(0, int(round(center_x - (crop_size / 2.0))))
    top = max(0, int(round(center_y - (crop_size / 2.0))))
    right = min(frame_width, int(round(center_x + (crop_size / 2.0))))
    bottom = min(frame_height, int(round(center_y + (crop_size / 2.0))))

    if right <= left or bottom <= top:
        return None

    return rgb[top:bottom, left:right]


def anti_spoof_session(settings: Settings):
    global _ANTI_SPOOF_SESSION

    model_path = settings.anti_spoofing_model_path
    if model_path is None:
        raise RuntimeError("anti_spoofing_unconfigured: set ANTI_SPOOFING_MODEL_PATH to a MiniFASNet ONNX file")

    resolved = str(model_path.expanduser().resolve())
    if _ANTI_SPOOF_SESSION is not None and _ANTI_SPOOF_SESSION[0] == resolved:
        return _ANTI_SPOOF_SESSION[1]

    if ort is None:
        raise RuntimeError(f"anti_spoofing_unavailable: onnxruntime import failed: {ONNXRUNTIME_IMPORT_ERROR}")

    if not model_path.exists():
        raise RuntimeError(f"anti_spoofing_unavailable: model not found: {model_path}")

    session = ort.InferenceSession(resolved, providers=["CPUExecutionProvider"])
    _ANTI_SPOOF_SESSION = (resolved, session)
    return session


def softmax(values: np.ndarray) -> np.ndarray:
    shifted = values - np.max(values)
    exp = np.exp(shifted)
    return exp / np.sum(exp)


def anti_spoofing_probabilities(output: np.ndarray) -> np.ndarray:
    scores = np.asarray(output, dtype=np.float32).reshape(-1)
    if scores.size == 0:
        raise RuntimeError("anti_spoofing_unavailable: model returned no scores")

    if np.any(scores < 0) or np.any(scores > 1) or not np.isclose(np.sum(scores), 1.0, atol=0.05):
        scores = softmax(scores)

    return scores


def facenox_preprocess_face(face_rgb: np.ndarray, input_size: int) -> np.ndarray:
    new_size = max(1, int(input_size))
    old_height, old_width = face_rgb.shape[:2]
    ratio = float(new_size) / max(old_height, old_width)
    scaled_height = max(1, int(old_height * ratio))
    scaled_width = max(1, int(old_width * ratio))
    interpolation = cv2.INTER_LANCZOS4 if ratio > 1.0 else cv2.INTER_AREA

    image = cv2.resize(
        face_rgb,
        (scaled_width, scaled_height),
        interpolation=interpolation,
    )

    delta_width = new_size - scaled_width
    delta_height = new_size - scaled_height
    top = delta_height // 2
    bottom = delta_height - top
    left = delta_width // 2
    right = delta_width - left

    image = cv2.copyMakeBorder(
        image,
        top,
        bottom,
        left,
        right,
        cv2.BORDER_REFLECT_101,
    )

    tensor = image.astype(np.float32) / 255.0
    return np.transpose(tensor, (2, 0, 1))[np.newaxis, ...]


def facenox_liveness_from_logits(logits: np.ndarray, threshold: float) -> dict:
    scores = np.asarray(logits, dtype=np.float32).reshape(-1)
    if scores.size < 2:
        raise RuntimeError("anti_spoofing_unavailable: facenox model returned fewer than 2 logits")

    real_logit = float(scores[0])
    spoof_logit = float(scores[1])
    threshold = min(0.999, max(0.001, float(threshold)))
    logit_threshold = float(np.log(threshold / (1.0 - threshold)))
    logit_diff = real_logit - spoof_logit

    probabilities = softmax(np.array([real_logit, spoof_logit], dtype=np.float32))

    return {
        "is_real": bool(logit_diff >= logit_threshold),
        "real_score": float(probabilities[0]),
        "fake_score": float(probabilities[1]),
        "real_logit": real_logit,
        "fake_logit": spoof_logit,
        "logit_diff": float(logit_diff),
        "logit_threshold": logit_threshold,
    }


def classify_face_liveness(rgb: np.ndarray, facial_area: dict, settings: Settings) -> dict:
    if not settings.anti_spoofing:
        logger.info("face_liveness_skipped reason=disabled")
        return {
            "checked": False,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": "disabled",
            "score": None,
            "detector_backend": None,
        }

    crop = expanded_face_crop(rgb, facial_area, settings.anti_spoofing_crop_scale)
    if crop is None or crop.size == 0:
        return {
            "checked": False,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": "invalid_face_crop",
            "score": None,
            "detector_backend": "yunet_crop",
        }

    started_at = time.perf_counter()
    try:
        session = anti_spoof_session(settings)
        input_info = session.get_inputs()[0]
        tensor = facenox_preprocess_face(crop, settings.anti_spoofing_input_size)
        outputs = session.run(None, {input_info.name: tensor})
        liveness = facenox_liveness_from_logits(
            outputs[0],
            settings.anti_spoofing_real_threshold,
        )
    except Exception as exc:
        logger.warning("face_liveness_unavailable source=yunet_crop error=%s", exc)
        return {
            "checked": False,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": str(exc),
            "score": None,
            "detector_backend": "yunet_crop",
        }

    real_score = liveness["real_score"]
    fake_score = liveness["fake_score"]
    elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)

    if liveness["is_real"]:
        result = {
            "checked": True,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": None,
            "score": round(real_score, 4),
            "detector_backend": "facenox_minifasnet_onnx",
        }
    elif fake_score >= settings.anti_spoofing_fake_threshold:
        result = {
            "checked": True,
            "required": bool(settings.require_anti_spoofing),
            "is_real": False,
            "error": None,
            "score": round(real_score, 4),
            "detector_backend": "facenox_minifasnet_onnx",
        }
    else:
        result = {
            "checked": False,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": "uncertain",
            "score": round(real_score, 4),
            "detector_backend": "facenox_minifasnet_onnx",
        }

    logger.warning(
        "face_liveness_crop_result source=yunet_crop model=facenox_minifasnet_onnx checked=%s is_real=%s real_score=%s fake_score=%s logit_diff=%s crop_width=%s crop_height=%s elapsed_ms=%s",
        result["checked"],
        result["is_real"],
        result["score"],
        round(fake_score, 4),
        round(liveness["logit_diff"], 4),
        int(crop.shape[1]),
        int(crop.shape[0]),
        elapsed_ms,
    )
    return result


def attach_spoofing_result(
    rgb: np.ndarray,
    face: dict,
    detector_backend: str,
    settings: Settings,
    enabled: bool | None = None,
) -> None:
    if enabled is False:
        spoofing = {
            "checked": False,
            "is_real": True,
            "score": None,
            "error": "disabled",
            "detector_backend": None,
        }
    else:
        spoofing = classify_face_liveness(rgb, face.get("facial_area") or {}, settings)

    face["spoofing_checked"] = bool(spoofing["checked"])
    face["is_real"] = bool(spoofing["is_real"])
    face["antispoof_score"] = spoofing["score"]
    face["spoofing_error"] = spoofing["error"]
    face["spoofing_detector_backend"] = spoofing["detector_backend"]

def extract_faces_with_spoofing(
    rgb: np.ndarray,
    settings: Settings,
    image_sha256: str | None = None,
    context: str = "face",
) -> FaceExtraction:
    ensure_recognizer_available()
    diagnostics = frame_diagnostics(rgb)
    logger.warning(
        "face_frame width=%s height=%s brightness=%s blur_score=%s",
        diagnostics["width"],
        diagnostics["height"],
        diagnostics["brightness"],
        diagnostics["blur_score"],
    )

    started_at = time.perf_counter()
    faces = extract_faces_once(
        rgb=rgb,
        detector_backend=settings.detector_backend,
        anti_spoofing=False,
    )
    primary_elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)
    logger.warning(
        "face_detection_attempt detector=%s anti_spoofing=%s face_count=%s elapsed_ms=%s",
        settings.detector_backend,
        False,
        len(faces),
        primary_elapsed_ms,
    )

    detector_backend = settings.detector_backend
    fallback_backend = settings.fallback_detector_backend.strip()
    if not faces and fallback_backend and fallback_backend != settings.detector_backend:
        started_at = time.perf_counter()
        faces = extract_faces_once(
            rgb=rgb,
            detector_backend=fallback_backend,
            anti_spoofing=False,
        )
        fallback_elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)
        logger.warning(
            "face_detection_fallback primary=%s fallback=%s anti_spoofing=%s face_count=%s elapsed_ms=%s",
            settings.detector_backend,
            fallback_backend,
            False,
            len(faces),
            fallback_elapsed_ms,
        )
        if faces:
            detector_backend = fallback_backend

    non_spoof_faces: list[dict] = []
    non_spoof_detector: str | None = None
    if not faces:
        for backend in detector_backends(settings):
            started_at = time.perf_counter()
            try:
                non_spoof_faces = extract_faces_once(
                    rgb=rgb,
                    detector_backend=backend,
                    anti_spoofing=False,
                )
            except HTTPException as exc:
                logger.warning(
                    "face_detection_diagnostic_error detector=%s anti_spoofing=False detail=%s",
                    backend,
                    exc.detail,
                )
                continue

            diagnostic_elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)
            logger.warning(
                "face_detection_diagnostic detector=%s anti_spoofing=False face_count=%s elapsed_ms=%s",
                backend,
                len(non_spoof_faces),
                diagnostic_elapsed_ms,
            )

            if non_spoof_faces:
                non_spoof_detector = backend
                break

        debug_frame = save_failed_detection_frame(
            rgb=rgb,
            settings=settings,
            image_sha256=image_sha256,
            context=context,
        )
        diagnostics["debug_frame"] = debug_frame
        diagnostics["non_spoof_face_count"] = len(non_spoof_faces)
        diagnostics["non_spoof_detector"] = non_spoof_detector
        logger.warning(
            "face_detection_failed primary=%s fallback=%s non_spoof_detector=%s non_spoof_face_count=%s debug_frame=%s width=%s height=%s brightness=%s blur_score=%s",
            settings.detector_backend,
            fallback_backend or None,
            non_spoof_detector,
            len(non_spoof_faces),
            debug_frame,
            diagnostics["width"],
            diagnostics["height"],
            diagnostics["brightness"],
            diagnostics["blur_score"],
        )

    for face in faces:
        area = dict(face.get("facial_area") or {})
        area["confidence"] = float(face.get("confidence", 0.0) or 0.0)
        face["facial_area"] = area
        face["detector_backend"] = detector_backend

    for face in faces:
        attach_spoofing_result(rgb, face, detector_backend, settings)

    return FaceExtraction(faces=faces, diagnostics=diagnostics)


def embedding_for_single_face(
    rgb: np.ndarray,
    settings: Settings,
    detector_backend: str | None = None,
) -> np.ndarray:
    ensure_recognizer_available()
    started_at = time.perf_counter()
    detector_backend = detector_backend or settings.detector_backend

    try:
        representations = DeepFace.represent(
            img_path=rgb_to_bgr(rgb),
            model_name=settings.model_name,
            detector_backend=detector_backend,
            enforce_detection=True,
            align=True,
            anti_spoofing=False,
            max_faces=1,
        )
    except FaceNotDetected as exc:
        raise HTTPException(status_code=422, detail="Face could not be encoded. Retake the image.") from exc
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    if not representations:
        raise HTTPException(status_code=422, detail="Face could not be encoded. Retake the image.")

    elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)
    embedding = np.array(representations[0]["embedding"], dtype=np.float64)
    logger.info(
        "face_embedding model=%s detector=%s dimensions=%s elapsed_ms=%s",
        settings.model_name,
        detector_backend,
        embedding.shape[0],
        elapsed_ms,
    )

    return embedding


def spoofing_metadata(face: dict, settings: Settings) -> dict:
    return {
        "checked": bool(face.get("spoofing_checked", False)),
        "required": bool(settings.require_anti_spoofing),
        "is_real": bool(face.get("is_real", True)),
        "error": face.get("spoofing_error"),
        "score": (
            round(float(face["antispoof_score"]), 4)
            if face.get("antispoof_score") is not None
            else None
        ),
    }


def analyze_single_face(content: bytes, rgb: np.ndarray, settings: Settings) -> FaceAnalysis:
    image_sha256 = hashlib.sha256(content).hexdigest()
    extraction = extract_faces_with_spoofing(
        rgb,
        settings,
        image_sha256=image_sha256,
        context="enroll",
    )
    faces = extraction.faces
    face_count = len(faces)

    if face_count == 0:
        if extraction.diagnostics.get("non_spoof_face_count", 0) > 0:
            detector = extraction.diagnostics.get("non_spoof_detector")
            logger.warning(
                "face_enrollment_rejected reason=spoofing_unconfirmed detector=%s",
                detector,
            )
            raise HTTPException(
                status_code=422,
                detail=(
                    "Face was detected without liveness checking, but anti-spoofing could not confirm it. "
                    f"Detector: {detector}. Improve lighting, face the camera directly, and avoid screen glare."
                ),
            )

        logger.warning("face_enrollment_rejected reason=no_face")
        raise HTTPException(status_code=422, detail="No face detected. Look straight at the camera.")

    if face_count > 1:
        logger.warning("face_enrollment_rejected reason=multiple_faces face_count=%s", face_count)
        raise HTTPException(status_code=422, detail="Only one face is allowed in the frame.")

    face = faces[0]
    spoofing = spoofing_metadata(face, settings)
    logger.info(
        "face_enrollment_spoofing checked=%s required=%s is_real=%s score=%s error=%s",
        spoofing["checked"],
        spoofing["required"],
        spoofing["is_real"],
        spoofing["score"],
        spoofing["error"],
    )

    if spoofing["required"] and not spoofing["checked"]:
        logger.warning(
            "face_enrollment_rejected reason=spoofing_unconfirmed error=%s",
            spoofing["error"],
        )
        raise HTTPException(
            status_code=422,
            detail="Face detected, but liveness check could not confirm it. Try brighter front lighting and avoid screen glare.",
        )

    if spoofing["checked"] and not spoofing["is_real"]:
        logger.warning(
            "face_enrollment_rejected reason=spoof score=%s",
            spoofing["score"],
        )
        raise HTTPException(status_code=422, detail="Spoofed face detected. Use a live face.")

    facial_area = face["facial_area"]
    quality = face_quality(rgb, facial_area)
    quality["detector_backend"] = face.get("detector_backend", settings.detector_backend)

    if quality["width"] < settings.min_face_size or quality["height"] < settings.min_face_size:
        logger.warning(
            "face_enrollment_rejected reason=face_too_small width=%s height=%s required=%s",
            quality["width"],
            quality["height"],
            settings.min_face_size,
        )
        raise HTTPException(status_code=422, detail="Move closer to the camera.")

    if quality["brightness"] < settings.min_brightness:
        logger.warning(
            "face_enrollment_rejected reason=too_dark brightness=%s required_min=%s",
            quality["brightness"],
            settings.min_brightness,
        )
        raise HTTPException(status_code=422, detail="The image is too dark. Add more light.")

    if quality["brightness"] > settings.max_brightness:
        logger.warning(
            "face_enrollment_rejected reason=too_bright brightness=%s required_max=%s",
            quality["brightness"],
            settings.max_brightness,
        )
        raise HTTPException(status_code=422, detail="The image is too bright. Reduce glare.")

    if quality["blur_score"] < settings.min_blur_score:
        logger.warning(
            "face_enrollment_rejected reason=too_blurry blur_score=%s required_min=%s",
            quality["blur_score"],
            settings.min_blur_score,
        )
        raise HTTPException(status_code=422, detail="The image is too blurry. Hold still and retake.")

    logger.info(
        "face_enrollment_accepted width=%s height=%s brightness=%s blur_score=%s detector=%s",
        quality["width"],
        quality["height"],
        quality["brightness"],
        quality["blur_score"],
        quality["detector_backend"],
    )

    return FaceAnalysis(
        image_sha256=image_sha256,
        rgb=rgb,
        facial_area=facial_area,
        embedding=embedding_for_single_face(
            rgb,
            settings,
            detector_backend=quality["detector_backend"],
        ),
        quality=quality,
        spoofing=spoofing,
    )


def detect_faces(rgb: np.ndarray, settings: Settings) -> dict:
    ensure_recognizer_available()
    faces = extract_faces_once(
        rgb=rgb,
        detector_backend=settings.detector_backend,
        anti_spoofing=False,
    )

    fallback_backend = settings.fallback_detector_backend.strip()
    if not faces and fallback_backend and fallback_backend != settings.detector_backend:
        faces = extract_faces_once(
            rgb=rgb,
            detector_backend=fallback_backend,
            anti_spoofing=False,
        )

    face_count = len(faces)
    message = "Face detection ready."

    if face_count == 0:
        message = "No face detected."
    elif face_count > 1:
        message = "Multiple faces detected. Please step out of the camera view."

    return {
        "face_count": face_count,
        "message": message,
    }


def recognize(content: bytes, rgb: np.ndarray, store: FaceStore, settings: Settings) -> dict:
    extraction = extract_faces_with_spoofing(
        rgb,
        settings,
        image_sha256=hashlib.sha256(content).hexdigest(),
        context="recognize",
    )
    faces = extraction.faces
    face_count = len(faces)

    if face_count == 0:
        message = "No face detected."
        if extraction.diagnostics.get("non_spoof_face_count", 0) > 0:
            message = "Face detected, but liveness check could not confirm it."

        logger.info(
            "face_recognition_result matched=False reason=no_face message=%s",
            message,
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": 0,
            "message": message,
            "spoofing_checked": False,
        }

    if face_count > 1:
        logger.info(
            "face_recognition_result matched=False reason=multiple_faces face_count=%s",
            face_count,
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Multiple faces detected. Please step out of the camera view.",
            "spoofing_checked": False,
        }

    face = faces[0]
    spoofing = spoofing_metadata(face, settings)
    if spoofing["required"] and not spoofing["checked"]:
        logger.warning(
            "face_deepface_rejected reason=spoofing_unconfirmed error=%s",
            spoofing["error"],
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Face detected, but liveness check could not confirm it.",
            "spoofing_score": spoofing["score"],
            "spoofing_passed": None,
            "spoofing_checked": spoofing["checked"],
        }

    if spoofing["checked"] and not spoofing["is_real"]:
        logger.warning(
            "face_deepface_rejected reason=spoof score=%s",
            spoofing["score"],
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Spoofed face detected.",
            "spoofing_score": spoofing["score"],
            "spoofing_passed": False,
            "spoofing_checked": spoofing["checked"],
        }

    quality = face_quality(rgb, face["facial_area"])
    quality["detector_backend"] = face.get("detector_backend", settings.detector_backend)
    quality_message = quality_rejection_message(quality, settings)

    if quality_message is not None:
        logger.info(
            "face_recognition_result matched=False reason=quality message=%s width=%s height=%s brightness=%s blur_score=%s detector=%s",
            quality_message,
            quality["width"],
            quality["height"],
            quality["brightness"],
            quality["blur_score"],
            quality["detector_backend"],
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": quality_message,
            "quality": quality,
            "spoofing_score": spoofing["score"],
            "spoofing_passed": spoofing["is_real"],
            "spoofing_checked": spoofing["checked"],
        }

    try:
        probe = embedding_for_single_face(
            rgb,
            settings,
            detector_backend=quality["detector_backend"],
        )
    except HTTPException:
        logger.warning("face_recognition_result matched=False reason=encoding_failed")
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Face could not be encoded.",
            "quality": quality,
            "spoofing_score": spoofing["score"],
            "spoofing_passed": spoofing["is_real"],
            "spoofing_checked": spoofing["checked"],
        }

    rows = store.embeddings()
    if not rows:
        logger.info("face_recognition_result matched=False reason=no_enrolled_embeddings")
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "No enrolled face embeddings are available.",
            "quality": quality,
            "spoofing_score": spoofing["score"],
            "spoofing_passed": spoofing["is_real"],
            "spoofing_checked": spoofing["checked"],
        }

    best_by_employee: dict[str, float] = {}
    for row in rows:
        distance = cosine_distance(row["embedding"], probe)
        current = best_by_employee.get(row["employee_id"])
        if current is None or distance < current:
            best_by_employee[row["employee_id"]] = distance

    ranked = sorted(best_by_employee.items(), key=lambda item: item[1])
    best_employee_id, best_distance = ranked[0]
    next_distance = ranked[1][1] if len(ranked) > 1 else None
    margin = None if next_distance is None else round(next_distance - best_distance, 4)
    confidence = confidence_from_distance(best_distance, settings.match_threshold)

    logger.info(
        "face_deepface_result matched_candidate=%s confidence=%s distance=%s margin=%s spoofing_score=%s",
        best_employee_id,
        confidence,
        round(best_distance, 4),
        margin,
        spoofing["score"],
    )

    if best_distance > settings.match_threshold:
        logger.info(
            "face_recognition_result matched=False reason=threshold candidate=%s distance=%s threshold=%s confidence=%s margin=%s",
            best_employee_id,
            round(best_distance, 4),
            settings.match_threshold,
            confidence,
            margin,
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": round(best_distance, 4),
            "margin": margin,
            "face_count": face_count,
            "message": "Face not recognized.",
            "quality": quality,
            "spoofing_score": spoofing["score"],
            "spoofing_passed": spoofing["is_real"],
            "spoofing_checked": spoofing["checked"],
        }

    if margin is not None and margin < settings.ambiguous_margin:
        logger.info(
            "face_recognition_result matched=False reason=ambiguous candidate=%s distance=%s margin=%s required_margin=%s confidence=%s",
            best_employee_id,
            round(best_distance, 4),
            margin,
            settings.ambiguous_margin,
            confidence,
        )
        return {
            "matched": False,
            "employee_id": None,
            "confidence": confidence_from_distance(best_distance, settings.match_threshold),
            "distance": round(best_distance, 4),
            "margin": margin,
            "face_count": face_count,
            "message": "Face match is ambiguous. Please try again.",
            "quality": quality,
            "spoofing_score": spoofing["score"],
            "spoofing_passed": spoofing["is_real"],
            "spoofing_checked": spoofing["checked"],
        }

    logger.info(
        "face_recognition_result matched=True employee_id=%s confidence=%s distance=%s margin=%s",
        best_employee_id,
        confidence,
        round(best_distance, 4),
        margin,
    )
    return {
        "matched": True,
        "employee_id": best_employee_id,
        "confidence": confidence,
        "distance": round(best_distance, 4),
        "margin": margin,
        "face_count": face_count,
        "message": "Face matched.",
        "quality": quality,
        "spoofing_score": spoofing["score"],
        "spoofing_passed": spoofing["is_real"],
        "spoofing_checked": spoofing["checked"],
    }


def verify_employee_face(content: bytes, rgb: np.ndarray, employee_id: str, store: FaceStore, settings: Settings) -> dict:
    logger.info("face_verification_started employee_id=%s", employee_id)
    scoped_store = type(
        "EmployeeScopedStore",
        (),
        {"embeddings": lambda _self: store.employee_embeddings(employee_id)},
    )()

    result = recognize(content, rgb, scoped_store, settings)
    if result["matched"]:
        result["employee_id"] = employee_id
        logger.info(
            "face_verification_result employee_id=%s matched=True confidence=%s distance=%s",
            employee_id,
            result.get("confidence"),
            result.get("distance"),
        )
        return result

    if result["message"] == "No enrolled face embeddings are available.":
        result["message"] = "Employee has no enrolled face embeddings."

    result["employee_id"] = None
    logger.info(
        "face_verification_result employee_id=%s matched=False message=%s",
        employee_id,
        result.get("message"),
    )
    return result


def face_box_motion_score(boxes: list[dict]) -> float:
    if len(boxes) < 2:
        return 0.0

    centers = []
    sizes = []
    for box in boxes:
        width = max(1.0, float(box.get("w", 0) or 0))
        height = max(1.0, float(box.get("h", 0) or 0))
        centers.append((
            float(box.get("x", 0) or 0) + (width / 2.0),
            float(box.get("y", 0) or 0) + (height / 2.0),
        ))
        sizes.append((width + height) / 2.0)

    deltas = []
    for index in range(1, len(centers)):
        base_size = max(1.0, (sizes[index - 1] + sizes[index]) / 2.0)
        deltas.append(
            (
                abs(centers[index][0] - centers[index - 1][0])
                + abs(centers[index][1] - centers[index - 1][1])
            )
            / base_size
        )

    if not deltas:
        return 0.0

    return round(max(0.0, min(1.0, float(np.mean(deltas)) * 4.0)), 4)


def analyze_session_frame(
    content: bytes,
    rgb: np.ndarray,
    rows: list[dict],
    settings: Settings,
    context: str,
) -> dict:
    ensure_recognizer_available()
    image_sha256 = hashlib.sha256(content).hexdigest()
    extraction = extract_faces_with_spoofing(
        rgb,
        settings,
        image_sha256=image_sha256,
        context=context,
    )
    faces = extraction.faces
    spoofing = spoofing_metadata(faces[0], settings) if faces else {
        "checked": False,
        "required": bool(settings.require_anti_spoofing),
        "is_real": True,
        "error": "no_face_detection",
        "score": None,
    }
    frame = {
        "matched": False,
        "employee_id": None,
        "confidence": 0.0,
        "distance": None,
        "margin": None,
        "face_count": len(faces),
        "message": "No face detected.",
        "quality": None,
        "quality_score": 0.0,
        "liveness_score": liveness_score_from_spoofing(spoofing),
        "spoofing_checked": spoofing["checked"],
        "spoofing_passed": spoofing["is_real"] if spoofing["checked"] else None,
        "spoofing_score": spoofing["score"],
        "spoofing_error": spoofing["error"],
        "spoofing_detector_backend": faces[0].get("spoofing_detector_backend") if faces else None,
        "facial_area": None,
    }

    if len(faces) == 0:
        if extraction.diagnostics.get("non_spoof_face_count", 0) > 0:
            frame["message"] = "Face detected, but liveness check could not confirm it."
        return frame

    if len(faces) > 1:
        frame["message"] = "Multiple faces detected. Please step out of the camera view."
        return frame

    face = faces[0]
    if spoofing["required"] and not spoofing["checked"]:
        frame["message"] = "Face detected, but liveness check could not confirm it."
        return frame

    if spoofing["checked"] and not spoofing["is_real"]:
        frame["message"] = "Spoofed face detected."
        return frame

    quality = face_quality(rgb, face["facial_area"])
    quality["detector_backend"] = face.get("detector_backend", settings.detector_backend)

    frame.update({
        "quality": quality,
        "quality_score": quality_score(quality, settings),
        "facial_area": face["facial_area"],
    })

    quality_message = quality_rejection_message(quality, settings)
    if quality_message is not None:
        frame["message"] = quality_message
        return frame

    try:
        probe = embedding_for_single_face(
            rgb,
            settings,
            detector_backend=quality["detector_backend"],
        )
    except HTTPException:
        frame["message"] = "Face could not be encoded."
        return frame

    frame.update(compare_probe_to_rows(probe, rows, settings))
    return frame


def aggregate_face_session(
    frame_results: list[dict],
    settings: Settings,
    evidence_image_base64: str | None = None,
    expected_employee_id: str | None = None,
) -> dict:
    frame_count = len(frame_results)
    usable_frames = [
        frame for frame in frame_results
        if frame.get("face_count") == 1 and frame.get("quality") is not None
    ]
    single_face_frames = [
        frame for frame in frame_results
        if frame.get("face_count") == 1
    ]
    matched_frames = [
        frame for frame in usable_frames
        if frame.get("matched") and (
            expected_employee_id is None or str(frame.get("employee_id")) == str(expected_employee_id)
        )
    ]
    face_count = max([int(frame.get("face_count") or 0) for frame in frame_results], default=0)

    if any(int(frame.get("face_count") or 0) > 1 for frame in frame_results):
        reason_code = "multiple_faces"
        decision = "fallback"
    elif frame_count == 0 or not usable_frames:
        reason_code = "no_face"
        decision = "retry"
    else:
        reason_code = "session_uncertain"
        decision = "retry"

    matches_by_employee: dict[str, list[dict]] = {}
    for frame in matched_frames:
        employee_id = str(frame.get("employee_id") or "")
        if employee_id:
            matches_by_employee.setdefault(employee_id, []).append(frame)

    employee_id = None
    employee_matches: list[dict] = []
    if expected_employee_id and matched_frames:
        employee_id = expected_employee_id
        employee_matches = matched_frames
    elif matches_by_employee:
        employee_id, employee_matches = max(matches_by_employee.items(), key=lambda item: len(item[1]))

    consistency_score = (
        len(employee_matches) / max(1, len(usable_frames))
        if employee_matches
        else 0.0
    )
    best_match = max(employee_matches, key=lambda frame: float(frame.get("confidence") or 0.0), default=None)
    confidence = float(best_match.get("confidence") or 0.0) if best_match else 0.0
    match_score = round((confidence * 0.65) + (consistency_score * 0.35), 4)
    liveness_frames = usable_frames or single_face_frames
    liveness_score = round(float(np.mean([frame.get("liveness_score", 0.55) for frame in liveness_frames])) if liveness_frames else 0.0, 4)
    avg_quality_score = round(float(np.mean([frame.get("quality_score", 0.0) for frame in usable_frames])) if usable_frames else 0.0, 4)
    motion_score = face_box_motion_score([frame["facial_area"] for frame in usable_frames if frame.get("facial_area")])
    liveness_checked_frames = [
        frame for frame in single_face_frames
        if frame.get("spoofing_checked") and frame.get("spoofing_passed") is True
    ]
    has_confirmed_spoof = any(
        frame.get("spoofing_checked") and frame.get("spoofing_passed") is False
        for frame in single_face_frames
    )
    has_unconfirmed_liveness = bool(single_face_frames) and len(liveness_checked_frames) < len(single_face_frames)

    combined_score = round(
        (match_score * settings.session_match_weight)
        + (liveness_score * settings.session_liveness_weight)
        + (avg_quality_score * settings.session_quality_weight)
        + (motion_score * settings.session_motion_weight),
        4,
    )
    risk_score = round(max(0.0, min(1.0, 1.0 - combined_score)), 4)

    if decision != "fallback":
        if has_confirmed_spoof:
            decision = "fallback"
            reason_code = "spoof_detected"
        elif settings.require_anti_spoofing and has_unconfirmed_liveness:
            decision = "retry"
            reason_code = "liveness_unconfirmed"
        elif len(usable_frames) < settings.session_min_usable_frames:
            decision = "retry"
            reason_code = "insufficient_usable_frames"
        elif (
            employee_id
            and len(employee_matches) >= max(2, int(np.ceil(len(usable_frames) / 2.0)))
            and combined_score >= settings.session_accept_score
        ):
            decision = "accept"
            reason_code = "session_accepted"
        elif combined_score < settings.session_retry_score:
            decision = "fallback"
            reason_code = "session_high_risk"
        elif employee_id and len(employee_matches) < max(2, int(np.ceil(len(usable_frames) / 2.0))):
            decision = "retry"
            reason_code = "identity_changed"

    if employee_id is None and expected_employee_id:
        employee_id = expected_employee_id if decision != "accept" else None

    messages = {
        "session_accepted": "Face session accepted.",
        "multiple_faces": "Multiple faces detected. Use RFID, keypad, or fingerprint.",
        "no_face": "No face detected. Look straight at the camera and try again.",
        "insufficient_usable_frames": "Not enough usable face frames. Please try again.",
        "session_high_risk": "Face verification is uncertain. Use RFID, keypad, or fingerprint.",
        "session_uncertain": "Face frames did not agree. Please try again.",
        "identity_changed": "Face identity changed during verification. Please try again.",
        "spoof_detected": "Spoofed face detected. Use RFID, keypad, or fingerprint.",
        "liveness_unconfirmed": "Face detected, but liveness could not confirm it. Please try again.",
    }

    return {
        "decision": decision,
        "employee_id": employee_id if decision == "accept" else None,
        "candidate_employee_id": employee_id,
        "confidence": round(confidence, 4),
        "match_score": match_score,
        "liveness_score": liveness_score,
        "quality_score": avg_quality_score,
        "risk_score": risk_score,
        "reason_code": reason_code,
        "frame_count": frame_count,
        "usable_frame_count": len(usable_frames),
        "matched_frame_count": len(employee_matches),
        "face_count": face_count,
        "distance": best_match.get("distance") if best_match else None,
        "margin": best_match.get("margin") if best_match else None,
        "message": messages.get(reason_code, "Face session could not be accepted."),
        "evidence_image_base64": evidence_image_base64,
        "frames": frame_results,
    }


def recognize_face_session(
    frames: list[tuple[bytes, np.ndarray]],
    store: FaceStore,
    settings: Settings,
    expected_employee_id: str | None = None,
) -> dict:
    limited_frames = frames[: settings.session_max_frames]
    rows = store.employee_embeddings(expected_employee_id) if expected_employee_id else store.embeddings()
    frame_results = [
        analyze_session_frame(content, rgb, rows, settings, context="face-session")
        for content, rgb in limited_frames
    ]
    evidence = base64.b64encode(limited_frames[0][0]).decode("ascii") if limited_frames else None

    return aggregate_face_session(
        frame_results,
        settings,
        evidence_image_base64=evidence,
        expected_employee_id=expected_employee_id,
    )
