from pydantic import BaseModel


class RecognizeResponse(BaseModel):
    matched: bool
    employee_id: str | None = None
    confidence: float = 0.0
    distance: float | None = None
    margin: float | None = None
    face_count: int
    message: str
    quality: dict | None = None
    spoofing_score: float | None = None
    spoofing_passed: bool | None = None
    spoofing_checked: bool = False


class FaceSessionResponse(BaseModel):
    decision: str
    employee_id: str | None = None
    candidate_employee_id: str | None = None
    confidence: float = 0.0
    match_score: float = 0.0
    liveness_score: float = 0.0
    quality_score: float = 0.0
    risk_score: float = 1.0
    reason_code: str
    frame_count: int
    usable_frame_count: int = 0
    matched_frame_count: int = 0
    face_count: int = 0
    distance: float | None = None
    margin: float | None = None
    message: str
    evidence_image_base64: str | None = None
    frames: list[dict] = []


class DetectResponse(BaseModel):
    face_count: int
    message: str


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


class CacheRebuildResponse(BaseModel):
    rebuilt: bool
    embedding_count: int
    employee_count: int
    generated_at: str | None = None


class EmployeeStatusResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    required_count: int
    ready: bool
    last_enrolled_at: str | None = None


class DeleteEmployeeResponse(BaseModel):
    employee_id: str
    deleted: int
