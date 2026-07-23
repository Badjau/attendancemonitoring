import base64
import json
import logging
import ssl
import sys
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
    detect_faces,
    read_upload_image,
    recognize,
    recognize_face_session,
    verify_employee_face,
)
from .schemas import (
    CacheRefreshResponse,
    CacheRebuildResponse,
    DeleteEmployeeResponse,
    DetectResponse,
    EmployeeStatusResponse,
    EnrollmentResponse,
    FaceSessionResponse,
    RecognizeResponse,
)


def configure_logging() -> logging.Logger:
    package_logger = logging.getLogger("app")
    if not package_logger.handlers:
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(
            logging.Formatter("%(asctime)s %(levelname)s [%(name)s] %(message)s")
        )
        package_logger.addHandler(handler)

    package_logger.setLevel(logging.INFO)
    package_logger.propagate = False

    return logging.getLogger(__name__)


logger = configure_logging()
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


@app.on_event("startup")
def log_startup() -> None:
    logger.info(
        "face_service_started database=%s debug_frames=%s laravel_base_url=%s model=%s detector=%s fallback_detector=%s anti_spoofing_detector=%s anti_spoofing=%s require_anti_spoofing=%s deepface_available=%s",
        settings.database_path,
        settings.debug_frame_path,
        settings.laravel_base_url,
        settings.model_name,
        settings.detector_backend,
        settings.fallback_detector_backend,
        settings.anti_spoofing_detector_backend,
        settings.anti_spoofing,
        settings.require_anti_spoofing,
        DeepFace is not None,
    )
    if DEEPFACE_IMPORT_ERROR is not None:
        logger.warning("deepface_import_error error=%s", DEEPFACE_IMPORT_ERROR)


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
    request_method = method or ("POST" if payload is not None else "GET")
    logger.info(
        "laravel_api_request method=%s path=%s payload_bytes=%s",
        request_method,
        path,
        len(data) if data is not None else 0,
    )
    request = Request(
        f"{base_url}{path}",
        data=data,
        headers=laravel_headers(settings),
        method=request_method,
    )

    try:
        context = ssl._create_unverified_context()
        with urlopen(request, timeout=10, context=context) as response:
            response_body = response.read().decode("utf-8")
            logger.info(
                "laravel_api_response method=%s path=%s status=%s bytes=%s",
                request_method,
                path,
                response.status,
                len(response_body),
            )
            return json.loads(response_body)
    except HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="ignore") or str(exc)
        logger.warning(
            "laravel_api_error method=%s path=%s status=%s detail=%s",
            request_method,
            path,
            exc.code,
            detail,
        )
        raise HTTPException(status_code=502, detail=f"Laravel face vector API rejected the request: {detail}") from exc
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        logger.warning(
            "laravel_api_unavailable method=%s path=%s error=%s",
            request_method,
            path,
            exc,
        )
        raise HTTPException(status_code=502, detail=f"Laravel face vector API is unavailable: {exc}") from exc


def refresh_employee_cache(employee_id: str, face_store: FaceStore, settings: Settings) -> dict:
    logger.info("face_cache_refresh_started employee_id=%s", employee_id)
    payload = laravel_json_request(
        f"/api/face/employees/{employee_id}/embeddings",
        settings,
    )
    embeddings = payload.get("embeddings") or []
    face_store.replace_employee_embeddings(employee_id, embeddings)
    logger.info(
        "face_cache_refresh_completed employee_id=%s enrollment_count=%s last_enrolled_at=%s",
        employee_id,
        len(embeddings),
        payload.get("last_enrolled_at"),
    )

    return {
        "employee_id": employee_id,
        "enrollment_count": len(embeddings),
        "last_enrolled_at": payload.get("last_enrolled_at"),
        "refreshed": True,
    }


def rebuild_face_cache(face_store: FaceStore, settings: Settings) -> dict:
    logger.info("face_cache_rebuild_started")
    payload = laravel_json_request("/api/face/embeddings", settings)
    embeddings = payload.get("embeddings") or []
    counts = face_store.replace_all_embeddings(embeddings)
    logger.info(
        "face_cache_rebuild_completed embedding_count=%s employee_count=%s generated_at=%s",
        counts["embedding_count"],
        counts["employee_count"],
        payload.get("generated_at"),
    )

    return {
        "rebuilt": True,
        "embedding_count": counts["embedding_count"],
        "employee_count": counts["employee_count"],
        "generated_at": payload.get("generated_at"),
    }


def cache_is_stale(employee_id: str, face_store: FaceStore, settings: Settings) -> bool:
    cached_at = face_store.employee_cache_refreshed_at(employee_id)
    if not cached_at:
        logger.info("face_cache_stale employee_id=%s reason=missing_cache", employee_id)
        return True

    try:
        cached_time = datetime.fromisoformat(cached_at.replace("Z", "+00:00"))
        if cached_time.tzinfo is None:
            cached_time = cached_time.replace(tzinfo=timezone.utc)
    except ValueError:
        logger.info(
            "face_cache_stale employee_id=%s reason=invalid_cached_at cached_at=%s",
            employee_id,
            cached_at,
        )
        return True

    age_seconds = (datetime.now(timezone.utc) - cached_time).total_seconds()
    stale = age_seconds > settings.face_cache_ttl_seconds
    logger.info(
        "face_cache_checked employee_id=%s stale=%s age_seconds=%s ttl_seconds=%s",
        employee_id,
        stale,
        round(age_seconds, 2),
        settings.face_cache_ttl_seconds,
    )
    return stale


@app.get("/health")
def health(settings: Settings = Depends(get_settings)) -> dict:
    return {
        "ok": True,
        "database_path": str(settings.database_path),
        "debug_frame_path": str(settings.debug_frame_path),
        "laravel_base_url": settings.laravel_base_url,
        "model_name": settings.model_name,
        "detector_backend": settings.detector_backend,
        "fallback_detector_backend": settings.fallback_detector_backend,
        "anti_spoofing_detector_backend": settings.anti_spoofing_detector_backend,
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
    logger.info("recognize_request_started filename=%s content_type=%s", image.filename, image.content_type)
    content, rgb = await read_upload_image(image)
    result = recognize(content, rgb, face_store, settings)
    logger.info(
        "recognize_request_completed matched=%s employee_id=%s message=%s",
        result.get("matched"),
        result.get("employee_id"),
        result.get("message"),
    )
    return result


@app.post("/api/face-session/recognize", response_model=FaceSessionResponse)
async def recognize_face_session_endpoint(
    images: list[UploadFile] = File(...),
    device_id: str | None = Form(default=None),
    session_id: str | None = Form(default=None),
    user_agent: str | None = Form(default=None),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    logger.info(
        "face_session_recognize_started frame_count=%s device_id=%s session_id=%s user_agent=%s",
        len(images),
        device_id,
        session_id,
        user_agent,
    )
    frames = [await read_upload_image(image) for image in images[: settings.session_max_frames]]
    result = recognize_face_session(frames, face_store, settings)
    logger.info(
        "face_session_recognize_completed decision=%s employee_id=%s reason=%s frame_count=%s",
        result.get("decision"),
        result.get("employee_id"),
        result.get("reason_code"),
        result.get("frame_count"),
    )
    return result


@app.post("/api/employees/{employee_id}/face-session/verify", response_model=FaceSessionResponse)
async def verify_employee_face_session_endpoint(
    employee_id: str,
    images: list[UploadFile] = File(...),
    device_id: str | None = Form(default=None),
    session_id: str | None = Form(default=None),
    user_agent: str | None = Form(default=None),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    logger.info(
        "face_session_verify_started employee_id=%s frame_count=%s device_id=%s session_id=%s user_agent=%s",
        employee_id,
        len(images),
        device_id,
        session_id,
        user_agent,
    )
    if not face_store.employee_embeddings(employee_id) or cache_is_stale(employee_id, face_store, settings):
        refresh_employee_cache(employee_id, face_store, settings)

    frames = [await read_upload_image(image) for image in images[: settings.session_max_frames]]
    result = recognize_face_session(frames, face_store, settings, expected_employee_id=employee_id)
    logger.info(
        "face_session_verify_completed employee_id=%s decision=%s matched_employee_id=%s reason=%s frame_count=%s",
        employee_id,
        result.get("decision"),
        result.get("employee_id"),
        result.get("reason_code"),
        result.get("frame_count"),
    )
    return result


@app.post("/api/detect", response_model=DetectResponse)
async def detect_face_count(
    image: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
) -> dict:
    logger.info("detect_request_started filename=%s content_type=%s", image.filename, image.content_type)
    _, rgb = await read_upload_image(image)
    result = detect_faces(rgb, settings)
    logger.info(
        "detect_request_completed face_count=%s message=%s",
        result.get("face_count"),
        result.get("message"),
    )
    return result


@app.post("/api/employees/{employee_id}/verify", response_model=RecognizeResponse)
async def verify_employee(
    employee_id: str,
    image: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    logger.info(
        "verify_request_started employee_id=%s filename=%s content_type=%s",
        employee_id,
        image.filename,
        image.content_type,
    )
    if not face_store.employee_embeddings(employee_id) or cache_is_stale(employee_id, face_store, settings):
        refresh_employee_cache(employee_id, face_store, settings)

    content, rgb = await read_upload_image(image)
    result = verify_employee_face(content, rgb, employee_id, face_store, settings)
    logger.info(
        "verify_request_completed employee_id=%s matched=%s message=%s",
        employee_id,
        result.get("matched"),
        result.get("message"),
    )
    return result


@app.post("/api/employees/{employee_id}/refresh-cache", response_model=CacheRefreshResponse)
def refresh_employee_face_cache(
    employee_id: str,
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    logger.info("manual_cache_refresh_requested employee_id=%s", employee_id)
    return refresh_employee_cache(employee_id, face_store, settings)


@app.post("/api/cache/rebuild", response_model=CacheRebuildResponse)
def rebuild_face_cache_endpoint(
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    logger.info("manual_cache_rebuild_requested")
    return rebuild_face_cache(face_store, settings)


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
    logger.info(
        "enroll_request_started employee_id=%s pose_label=%s reset_existing_form=%s reset_existing_query=%s filename=%s content_type=%s",
        employee_id,
        pose_label,
        reset_existing_form,
        reset_existing_query,
        image.filename,
        image.content_type,
    )
    content, rgb = await read_upload_image(image)
    analysis = analyze_single_face(content, rgb, settings)
    reset_existing = reset_existing_form or reset_existing_query
    if not reset_existing:
        refresh_employee_cache(employee_id, face_store, settings)
        reset_existing = face_store.employee_status(employee_id)["enrollment_count"] >= settings.min_enrollments

    if reset_existing:
        logger.info("enroll_reset_existing employee_id=%s", employee_id)
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
    logger.info(
        "enroll_request_completed employee_id=%s enrollment_count=%s required_count=%s ready=%s reset_existing=%s",
        employee_id,
        status["enrollment_count"],
        settings.min_enrollments,
        ready,
        reset_existing,
    )

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
    logger.info("face_status_requested employee_id=%s", employee_id)
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
    logger.info("face_delete_requested employee_id=%s", employee_id)
    return {
        "employee_id": employee_id,
        "deleted": face_store.delete_employee(employee_id),
    }
