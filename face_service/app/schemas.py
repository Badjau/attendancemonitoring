from pydantic import BaseModel


class RecognizeResponse(BaseModel):
    matched: bool
    employee_id: str | None = None
    confidence: float = 0.0
    distance: float | None = None
    margin: float | None = None
    face_count: int
    message: str
    spoofing_score: float | None = None
    spoofing_passed: bool | None = None
    spoofing_checked: bool = False


class EnrollmentResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    required_count: int
    ready: bool
    quality: dict
    message: str
    embedding: list[float] | None = None
    image_hash: str | None = None
    pose_label: str | None = None
    model_name: str | None = None
    detector_backend: str | None = None


class CacheRefreshResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    refreshed: bool
    last_enrolled_at: str | None = None


class EmployeeStatusResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    required_count: int
    ready: bool
    last_enrolled_at: str | None = None


class DeleteEmployeeResponse(BaseModel):
    employee_id: str
    deleted: int
