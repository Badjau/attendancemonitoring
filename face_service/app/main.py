from fastapi import Depends, FastAPI, File, Form, UploadFile
from fastapi.middleware.cors import CORSMiddleware

from .config import Settings, get_settings
from .database import FaceStore
from .recognition import analyze_single_face, read_upload_image, recognize
from .schemas import (
    DeleteEmployeeResponse,
    EmployeeStatusResponse,
    EnrollmentResponse,
    RecognizeResponse,
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


@app.get("/health")
def health(settings: Settings = Depends(get_settings)) -> dict:
    return {
        "ok": True,
        "database_path": str(settings.database_path),
    }


@app.post("/api/recognize", response_model=RecognizeResponse)
async def recognize_face(
    image: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    content, rgb = await read_upload_image(image)
    return recognize(content, rgb, face_store, settings)


@app.post("/api/employees/{employee_id}/enroll", response_model=EnrollmentResponse)
async def enroll_employee_face(
    employee_id: str,
    image: UploadFile = File(...),
    pose_label: str | None = Form(default=None),
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
    content, rgb = await read_upload_image(image)
    analysis = analyze_single_face(content, rgb, settings)
    face_store.add_embedding(
        employee_id=employee_id,
        embedding=analysis.embedding,
        image_sha256=analysis.image_sha256,
        pose_label=pose_label,
        quality=analysis.quality,
    )
    status = face_store.employee_status(employee_id)
    ready = status["enrollment_count"] >= settings.min_enrollments

    return {
        "employee_id": employee_id,
        "enrollment_count": status["enrollment_count"],
        "required_count": settings.min_enrollments,
        "ready": ready,
        "quality": analysis.quality,
        "message": "Enrollment capture saved." if ready else "Capture saved. Add more poses.",
    }


@app.get("/api/employees/{employee_id}/status", response_model=EmployeeStatusResponse)
def employee_face_status(
    employee_id: str,
    settings: Settings = Depends(get_settings),
    face_store: FaceStore = Depends(get_store),
) -> dict:
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
