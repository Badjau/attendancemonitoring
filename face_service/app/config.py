from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    database_path: Path = Path(__file__).resolve().parents[1] / "data" / "faces.sqlite"
    allowed_origins: str = "https://attendancemonitoring.test,https://127.0.0.1,https://localhost"
    match_threshold: float = 0.50
    ambiguous_margin: float = 0.04
    min_enrollments: int = 3
    min_face_size: int = 120
    min_blur_score: float = 65.0
    min_brightness: float = 45.0
    max_brightness: float = 215.0


@lru_cache
def get_settings() -> Settings:
    return Settings()
