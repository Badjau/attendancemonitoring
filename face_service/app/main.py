import base64
import json
import ssl
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, UploadFile
from fastapi.middleware.cors import CORSMiddleware

from .config import Settings, get_settings
from .database import FaceStore
from .recognition import (
    DEEPFACE_IMPORT_ERROR,
    DeepFace,
    analyze_single_face,
    read_upload_image,
    recognize,
    verify_employee_face,
)
from .schemas import (
    DeleteEmployeeResponse,
    EmployeeStatusResponse,
    EnrollmentResponse,
    RecognizeResponse,
    CacheRefreshResponse,
)

settings = get_settings()
store = FaceStore(settings.database_path)

app = FastAPI(title="Attendance Face Recognition Service")
app.add_middleware(
    CORSMiddleware,
    allow_origins=[origin.strip() for origin in settings.allowed_origins.split(",") if origin.strip()],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)


def get_store() -> FaceStore:
    return store


def laravel_headers(settings: Settings) -> dict[str, str]:
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
    }

    token = settings.laravel_face_embeddings_token or settings.face_embeddings_token
    if token:
        headers["Authorization"] = f"Bearer {token}"

    return headers


def laravel_json_request(
    path: str,
    settings: Settings,
    payload: dict | None = None,
    method: str | None = None,
) -> dict:
    base_url = settings.laravel_base_url.rstrip("/")
    if not base_url:
        raise HTTPException(status_code=503, detail="Laravel face vector API is not configured.")

    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = Request(
        f"{base_url}{path}",
        data=data,
        headers=laravel_headers(settings),
        method=method or ("POST" if payload is not None else "GET"),
    )

    try:
        context = ssl._create_unverified_context()
        with urlopen(request, timeout=10, context=context) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="ignore") or str(exc)
        raise HTTPException(status_code=502, detail=f"Laravel face vector API rejected the request: {detail}") from exc
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        raise HTTPException(status_code=502, detail=f"Laravel face vector API is unavailable: {exc}") from exc


def refresh_employee_cache(employee_id: str, face_store: FaceStore, settings: Settings) -> dict:
    payload = laravel_json_request(
        f"/api/face/employees/{employee_id}/embeddings",
        settings,
    )
    embeddings = payload.get("embeddings") or []
    face_store.replace_employee_embeddings(employee_id, embeddings)

    return {
        "employee_id": employee_id,
        "enrollment_count": len(embeddings),
        "last_enrolled_at": payload.get("last_enrolled_at"),
        "refreshed": True,
    }


def cache_is_stale(employee_id: str, face_store: FaceStore, settings: Settings) -> bool:
    cached_at = face_store.employee_cache_refreshed_at(employee_id)
    if not cached_at:
        return True

    try:
        cached_time = datetime.fromisoformat(cached_at.replace("Z", "+00:00"))
        if cached_time.tzinfo is None:
            cached_time = cached_time.replace(tzinfo=timezone.utc)
    except ValueError:
        return True

    return (datetime.now(timezone.utc) - cached_time).total_seconds() > settings.face_cache_ttl_seconds


@app.get("/health")
def health(settings: Settings = Depends(get_settings)) -> dict:
    return {
        "ok": True,
        "database_path": str(settings.database_path),
        "debug_frame_path": str(settings.debug_frame_path),
        "model_name": settings.model_name,
        "detector_backend": settings.detector_backend,
        "fallback_detector_backend": settings.fallback_detector_backend,
        "diagnostic_detector_backends": settings.diagnostic_detector_backends,
        "anti_spoofing": settings.anti_spoofing,
        "require_anti_spoofing": settings.require_anti_spoofing,
        "save_failed_detection_frames": settings.save_failed_detection_frames,
        "deepface_available": DeepFace is not None,
        "deepface_import_error": str(DEEPFACE_IMPORT_ERROR) if DEEPFACE_IMPORT_ERROR else None,
    }


@app.post("/api/recognize", response_model=RecognizeResponse)
async def recognize_face(
    image: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    content, rgb = await read_upload_image(image)
    return recognize(content, rgb, face_store, settings)


@app.post("/api/employees/{employee_id}/verify", response_model=RecognizeResponse)
async def verify_employee(
    employee_id: str,
    image: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    if not face_store.employee_embeddings(employee_id) or cache_is_stale(employee_id, face_store, settings):
        refresh_employee_cache(employee_id, face_store, settings)

    content, rgb = await read_upload_image(image)
    return verify_employee_face(content, rgb, employee_id, face_store, settings)


@app.post("/api/employees/{employee_id}/refresh-cache", response_model=CacheRefreshResponse)
def refresh_employee_face_cache(
    employee_id: str,
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    return refresh_employee_cache(employee_id, face_store, settings)


@app.post("/api/employees/{employee_id}/enroll", response_model=EnrollmentResponse)
async def enroll_employee_face(
    employee_id: str,
    image: UploadFile = File(...),
    pose_label: str | None = Form(default=None),
    reset_existing_form: bool = Form(default=False, alias="reset_existing"),
    reset_existing_query: bool = Query(default=False, alias="reset_existing"),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    content, rgb = await read_upload_image(image)
    analysis = analyze_single_face(content, rgb, settings)
    reset_existing = reset_existing_form or reset_existing_query
    if not reset_existing:
        refresh_employee_cache(employee_id, face_store, settings)
        reset_existing = face_store.employee_status(employee_id)["enrollment_count"] >= settings.min_enrollments

    if reset_existing:
        laravel_json_request(
            f"/api/face/employees/{employee_id}/embeddings",
            settings,
            method="DELETE",
        )
        face_store.delete_employee(employee_id)

    laravel_json_request(
        f"/api/face/employees/{employee_id}/embeddings",
        settings,
        {
            "embedding": [float(value) for value in analysis.embedding],
            "image_hash": analysis.image_sha256,
            "pose_label": pose_label,
            "model_name": settings.model_name,
            "detector_backend": settings.detector_backend,
            "quality": analysis.quality,
            "profile_image_base64": base64.b64encode(content).decode("ascii"),
            "reset_existing": reset_existing,
        },
    )
    refresh_employee_cache(employee_id, face_store, settings)
    status = face_store.employee_status(employee_id)
    ready = status["enrollment_count"] >= settings.min_enrollments

    return {
        "employee_id": employee_id,
        "enrollment_count": status["enrollment_count"],
        "required_count": settings.min_enrollments,
        "ready": ready,
        "quality": analysis.quality,
        "message": "Enrollment capture saved." if ready else "Capture saved. Add more poses.",
        "embedding": [float(value) for value in analysis.embedding],
        "image_hash": analysis.image_sha256,
        "pose_label": pose_label,
        "model_name": settings.model_name,
        "detector_backend": settings.detector_backend,
        "reset_existing": reset_existing,
    }


@app.get("/api/employees/{employee_id}/status", response_model=EmployeeStatusResponse)
def employee_face_status(
    employee_id: str,
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    refresh_employee_cache(employee_id, face_store, settings)
    status = face_store.employee_status(employee_id)
    return {
        **status,
        "required_count": settings.min_enrollments,
        "ready": status["enrollment_count"] >= settings.min_enrollments,
    }


@app.delete("/api/employees/{employee_id}", response_model=DeleteEmployeeResponse)
def delete_employee_faces(
    employee_id: str,
    face_store: FaceStore = Depends(get_store),
) -> dict:
    return {
        "employee_id": employee_id,
        "deleted": face_store.delete_employee(employee_id),
    }
