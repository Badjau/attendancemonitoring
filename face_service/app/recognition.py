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

from .config import Settings
from .database import FaceStore

logger = logging.getLogger(__name__)


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


def frame_diagnostics(rgb: np.ndarray) -> dict:
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    return {
        "width": int(rgb.shape[1]),
        "height": int(rgb.shape[0]),
        "brightness": round(float(np.mean(gray)), 2),
        "blur_score": round(float(cv2.Laplacian(gray, cv2.CV_64F).var()), 2),
    }


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


def attach_spoofing_result(
    rgb: np.ndarray,
    face: dict,
    detector_backend: str,
    settings: Settings,
    enabled: bool | None = None,
) -> None:
    should_check = settings.anti_spoofing if enabled is None else enabled

    if not should_check:
        face["spoofing_checked"] = False
        face["spoofing_error"] = "disabled"
        return

    if not deepface_supports_anti_spoofing():
        face["spoofing_checked"] = False
        face["spoofing_error"] = "unsupported"
        return

    started_at = time.perf_counter()
    try:
        spoof_faces = extract_faces_once(
            rgb=rgb,
            detector_backend=detector_backend,
            anti_spoofing=True,
        )
    except RuntimeError as exc:
        if is_known_anti_spoofing_error(exc):
            face["spoofing_checked"] = False
            face["spoofing_error"] = str(exc)
            logger.warning("face_spoofing_unavailable detector=%s error=%s", detector_backend, exc)
            return
        raise

    elapsed_ms = round((time.perf_counter() - started_at) * 1000, 2)

    if len(spoof_faces) != 1:
        face["spoofing_checked"] = False
        face["spoofing_error"] = f"anti_spoofing_returned_{len(spoof_faces)}_faces"
        logger.warning(
            "face_spoofing_unconfirmed detector=%s face_count=%s elapsed_ms=%s required=%s",
            detector_backend,
            len(spoof_faces),
            elapsed_ms,
            settings.require_anti_spoofing,
        )
        return

    spoof_face = spoof_faces[0]
    face["spoofing_checked"] = True
    face["is_real"] = bool(spoof_face.get("is_real", True))
    face["antispoof_score"] = spoof_face.get("antispoof_score")
    logger.warning(
        "face_spoofing_result detector=%s is_real=%s score=%s elapsed_ms=%s",
        detector_backend,
        face["is_real"],
        face["antispoof_score"],
        elapsed_ms,
    )


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
        attach_spoofing_result(
            rgb=rgb,
            face=face,
            detector_backend=detector_backend,
            settings=settings,
            enabled=context != "enroll",
        )

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
            "spoofing_passed": False,
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

    try:
        probe = embedding_for_single_face(
            rgb,
            settings,
            detector_backend=face.get("detector_backend", settings.detector_backend),
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
