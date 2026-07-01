import hashlib
from dataclasses import dataclass

import cv2
import numpy as np
from fastapi import HTTPException, UploadFile
from PIL import Image, UnidentifiedImageError

try:
    import face_recognition
except ImportError:  # pragma: no cover - startup will explain the missing dependency.
    face_recognition = None

from .config import Settings
from .database import FaceStore


@dataclass
class FaceAnalysis:
    image_sha256: str
    rgb: np.ndarray
    location: tuple[int, int, int, int]
    embedding: np.ndarray
    quality: dict


async def read_upload_image(upload: UploadFile) -> tuple[bytes, np.ndarray]:
    content = await upload.read()
    if not content:
        raise HTTPException(status_code=422, detail="Upload an image.")

    try:
        from io import BytesIO

        with Image.open(BytesIO(content)) as opened:
            rgb = np.array(opened.convert("RGB"))
    except UnidentifiedImageError as exc:
        raise HTTPException(status_code=422, detail="Upload a valid image file.") from exc

    return content, rgb


def ensure_recognizer_available() -> None:
    if face_recognition is None:
        raise HTTPException(
            status_code=503,
            detail="face_recognition is not installed. Install service requirements first.",
        )


def face_quality(rgb: np.ndarray, location: tuple[int, int, int, int]) -> dict:
    top, right, bottom, left = location
    face = rgb[max(top, 0) : max(bottom, 0), max(left, 0) : max(right, 0)]
    gray = cv2.cvtColor(face, cv2.COLOR_RGB2GRAY) if face.size else np.array([])
    blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var()) if gray.size else 0.0
    brightness = float(np.mean(gray)) if gray.size else 0.0

    return {
        "width": int(max(0, right - left)),
        "height": int(max(0, bottom - top)),
        "brightness": round(brightness, 2),
        "blur_score": round(blur_score, 2),
    }


def analyze_single_face(content: bytes, rgb: np.ndarray, settings: Settings) -> FaceAnalysis:
    ensure_recognizer_available()
    locations = face_recognition.face_locations(rgb, model="hog")
    face_count = len(locations)

    if face_count == 0:
        raise HTTPException(status_code=422, detail="No face detected. Look straight at the camera.")

    if face_count > 1:
        raise HTTPException(status_code=422, detail="Only one face is allowed in the frame.")

    location = locations[0]
    quality = face_quality(rgb, location)

    if quality["width"] < settings.min_face_size or quality["height"] < settings.min_face_size:
        raise HTTPException(status_code=422, detail="Move closer to the camera.")

    if quality["brightness"] < settings.min_brightness:
        raise HTTPException(status_code=422, detail="The image is too dark. Add more light.")

    if quality["brightness"] > settings.max_brightness:
        raise HTTPException(status_code=422, detail="The image is too bright. Reduce glare.")

    if quality["blur_score"] < settings.min_blur_score:
        raise HTTPException(status_code=422, detail="The image is too blurry. Hold still and retake.")

    encodings = face_recognition.face_encodings(rgb, known_face_locations=[location])
    if not encodings:
        raise HTTPException(status_code=422, detail="Face could not be encoded. Retake the image.")

    return FaceAnalysis(
        image_sha256=hashlib.sha256(content).hexdigest(),
        rgb=rgb,
        location=location,
        embedding=np.array(encodings[0], dtype=np.float64),
        quality=quality,
    )


def confidence_from_distance(distance: float, threshold: float) -> float:
    if distance >= threshold:
        return 0.0

    return round(max(0.0, min(1.0, 1.0 - (distance / threshold))), 4)


def recognize(content: bytes, rgb: np.ndarray, store: FaceStore, settings: Settings) -> dict:
    ensure_recognizer_available()
    locations = face_recognition.face_locations(rgb, model="hog")
    face_count = len(locations)

    if face_count == 0:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": 0,
            "message": "No face detected.",
        }

    if face_count > 1:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Only one face is allowed in the frame.",
        }

    encodings = face_recognition.face_encodings(rgb, known_face_locations=locations)
    if not encodings:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "Face could not be encoded.",
        }

    probe = np.array(encodings[0], dtype=np.float64)
    rows = store.embeddings()
    if not rows:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": None,
            "margin": None,
            "face_count": face_count,
            "message": "No enrolled face embeddings are available.",
        }

    best_by_employee: dict[str, float] = {}
    for row in rows:
        distance = float(np.linalg.norm(row["embedding"] - probe))
        current = best_by_employee.get(row["employee_id"])
        if current is None or distance < current:
            best_by_employee[row["employee_id"]] = distance

    ranked = sorted(best_by_employee.items(), key=lambda item: item[1])
    best_employee_id, best_distance = ranked[0]
    next_distance = ranked[1][1] if len(ranked) > 1 else None
    margin = None if next_distance is None else round(next_distance - best_distance, 4)

    if best_distance > settings.match_threshold:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": 0.0,
            "distance": round(best_distance, 4),
            "margin": margin,
            "face_count": face_count,
            "message": "Face not recognized.",
        }

    if margin is not None and margin < settings.ambiguous_margin:
        return {
            "matched": False,
            "employee_id": None,
            "confidence": confidence_from_distance(best_distance, settings.match_threshold),
            "distance": round(best_distance, 4),
            "margin": margin,
            "face_count": face_count,
            "message": "Face match is ambiguous. Please try again.",
        }

    return {
        "matched": True,
        "employee_id": best_employee_id,
        "confidence": confidence_from_distance(best_distance, settings.match_threshold),
        "distance": round(best_distance, 4),
        "margin": margin,
        "face_count": face_count,
        "message": "Face matched.",
    }
