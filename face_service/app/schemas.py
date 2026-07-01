from pydantic import BaseModel


class RecognizeResponse(BaseModel):
    matched: bool
    employee_id: str | None = None
    confidence: float = 0.0
    distance: float | None = None
    margin: float | None = None
    face_count: int
    message: str


class EnrollmentResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    required_count: int
    ready: bool
    quality: dict
    message: str


class EmployeeStatusResponse(BaseModel):
    employee_id: str
    enrollment_count: int
    required_count: int
    ready: bool
    last_enrolled_at: str | None = None


class DeleteEmployeeResponse(BaseModel):
    employee_id: str
    deleted: int
