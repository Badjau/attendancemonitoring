import numpy as np
import pytest
from fastapi import HTTPException

from app.config import Settings
from app.recognition import analyze_single_face


def test_rejects_no_face(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "face_recognition", type("Fake", (), {
        "face_locations": staticmethod(lambda image, model="hog": []),
    }))

    with pytest.raises(HTTPException) as exc:
        analyze_single_face(b"image", np.zeros((240, 240, 3), dtype=np.uint8), Settings())

    assert "No face" in exc.value.detail


def test_rejects_multiple_faces(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "face_recognition", type("Fake", (), {
        "face_locations": staticmethod(lambda image, model="hog": [(0, 160, 160, 0), (0, 220, 220, 80)]),
    }))

    with pytest.raises(HTTPException) as exc:
        analyze_single_face(b"image", np.full((260, 260, 3), 128, dtype=np.uint8), Settings())

    assert "Only one face" in exc.value.detail


def test_accepts_valid_quality(monkeypatch):
    from app import recognition

    image = np.indices((260, 260)).sum(axis=0).astype(np.uint8)
    rgb = np.stack([image, image, image], axis=2)

    monkeypatch.setattr(recognition, "face_recognition", type("Fake", (), {
        "face_locations": staticmethod(lambda image, model="hog": [(20, 220, 220, 20)]),
        "face_encodings": staticmethod(lambda image, known_face_locations=None: [np.ones(128)]),
    }))

    analysis = analyze_single_face(b"image", rgb, Settings(min_blur_score=1))

    assert analysis.embedding.shape == (128,)
    assert analysis.quality["width"] == 200
