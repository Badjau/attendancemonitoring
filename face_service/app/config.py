from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


project_root = Path(__file__).resolve().parents[2]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=project_root / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    database_path: Path = Path(__file__).resolve().parents[1] / "data" / "faces.sqlite"
    debug_frame_path: Path = Path(__file__).resolve().parents[1] / "data" / "debug"
    allowed_origins: str = "https://attendancemonitoring.test,https://20.20.52.71,https://127.0.0.1,https://localhost"
    laravel_base_url: str = "https://attendancemonitoring.test"
    face_embeddings_token: str = ""
    laravel_face_embeddings_token: str = ""
    face_cache_ttl_seconds: int = 300
    model_name: str = "SFace"
    detector_backend: str = "yunet"
    fallback_detector_backend: str = "opencv"
    diagnostic_detector_backends: str = "yunet,opencv,ssd"
    anti_spoofing: bool = True
    require_anti_spoofing: bool = False
    save_failed_detection_frames: bool = True
    match_threshold: float = 0.50
    ambiguous_margin: float = 0.04
    min_enrollments: int = 3
    min_face_size: int = 120
    min_blur_score: float = 10.0
    min_brightness: float = 45.0
    max_brightness: float = 215.0


@lru_cache
def get_settings() -> Settings:
    return Settings()
