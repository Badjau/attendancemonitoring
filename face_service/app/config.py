from functools import lru_cache
from pathlib import Path

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


project_root = Path(__file__).resolve().parents[2]
service_root = Path(__file__).resolve().parents[1]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=project_root / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    database_path: Path = Path(__file__).resolve().parents[1] / "data" / "faces.sqlite"
    debug_frame_path: Path = Path(__file__).resolve().parents[1] / "data" / "debug"
    allowed_origins: str = "https://attendancemonitoring.test,https://20.20.52.71,http://127.0.0.1:8000,http://localhost:8000,https://127.0.0.1,https://localhost"
    laravel_base_url: str = "http://127.0.0.1:8000"
    face_embeddings_token: str = ""
    laravel_face_embeddings_token: str = ""
    face_cache_ttl_seconds: int = 300
    model_name: str = "SFace"
    detector_backend: str = "yunet"
    fallback_detector_backend: str = "opencv"
    diagnostic_detector_backends: str = "yunet,opencv,ssd"
    anti_spoofing: bool = True
    anti_spoofing_model_path: Path | None = service_root / "models" / "best_model_quantized.onnx"
    anti_spoofing_input_size: int = 128
    anti_spoofing_crop_scale: float = 2.7
    anti_spoofing_real_threshold: float = 0.8
    anti_spoofing_fake_threshold: float = 0.8
    require_anti_spoofing: bool = False
    save_failed_detection_frames: bool = True
    match_threshold: float = 0.34
    ambiguous_margin: float = 0.06
    session_accept_score: float = 0.68
    session_retry_score: float = 0.45
    session_min_usable_frames: int = 2
    session_max_frames: int = 8
    session_match_weight: float = 0.5
    session_liveness_weight: float = 0.25
    session_quality_weight: float = 0.2
    session_motion_weight: float = 0.05
    min_enrollments: int = 3
    min_face_size: int = 120
    min_blur_score: float = 10.0
    min_brightness: float = 45.0
    max_brightness: float = 215.0

    @field_validator("anti_spoofing_model_path")
    @classmethod
    def resolve_anti_spoofing_model_path(cls, value: Path | None) -> Path | None:
        if value is None or value.is_absolute():
            return value

        return service_root / value


@lru_cache
def get_settings() -> Settings:
    return Settings()
