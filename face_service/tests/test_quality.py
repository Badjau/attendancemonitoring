import numpy as np
import pytest
from fastapi import HTTPException

from app.config import Settings
from app.recognition import analyze_single_face, recognize


class MemoryStore:
    def embeddings(self):
        return [{"employee_id": "EMP-001", "embedding": np.ones(128)}]


def test_rejects_no_face(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: []),
    }))

    with pytest.raises(HTTPException) as exc:
        analyze_single_face(b"image", np.zeros((240, 240, 3), dtype=np.uint8), Settings())

    assert "No face" in exc.value.detail


def test_rejects_multiple_faces(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [
            {"facial_area": {"x": 0, "y": 0, "w": 160, "h": 160}, "confidence": 0.99},
            {"facial_area": {"x": 80, "y": 0, "w": 140, "h": 220}, "confidence": 0.99},
        ]),
    }))

    with pytest.raises(HTTPException) as exc:
        analyze_single_face(b"image", np.full((260, 260, 3), 128, dtype=np.uint8), Settings())

    assert "Only one face" in exc.value.detail


def test_accepts_valid_quality(monkeypatch):
    from app import recognition

    image = np.indices((260, 260)).sum(axis=0).astype(np.uint8)
    rgb = np.stack([image, image, image], axis=2)

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [{
            "facial_area": {"x": 20, "y": 20, "w": 200, "h": 200},
            "confidence": 0.99,
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.ones(128)}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": True,
        "error": None,
        "score": 0.98,
        "detector_backend": "minifasnet_onnx",
    })

    analysis = analyze_single_face(b"image", rgb, Settings(min_blur_score=1))

    assert analysis.embedding.shape == (128,)
    assert analysis.quality["width"] == 200


def test_enrollment_allows_unconfirmed_liveness_when_optional(monkeypatch):
    from app import recognition

    image = np.indices((260, 260)).sum(axis=0).astype(np.uint8)
    rgb = np.stack([image, image, image], axis=2)

    def fake_extract_faces(**kwargs):
        return [{
            "facial_area": {"x": 20, "y": 20, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.ones(128)}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": False,
        "required": bool(settings.require_anti_spoofing),
        "is_real": True,
        "error": "uncertain",
        "score": 0.55,
        "detector_backend": "minifasnet_onnx",
    })

    analysis = analyze_single_face(b"image", rgb, Settings(min_blur_score=1))

    assert analysis.embedding.shape == (128,)
    assert analysis.spoofing["checked"] is False


def test_enrollment_rejects_confirmed_spoof(monkeypatch):
    from app import recognition

    image = np.indices((260, 260)).sum(axis=0).astype(np.uint8)
    rgb = np.stack([image, image, image], axis=2)

    def fake_extract_faces(**kwargs):
        return [{
            "facial_area": {"x": 20, "y": 20, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.ones(128)}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": False,
        "error": None,
        "score": 0.14,
        "detector_backend": "minifasnet_onnx",
    })

    with pytest.raises(HTTPException) as exc:
        analyze_single_face(b"image", rgb, Settings(min_blur_score=1))

    assert "Spoofed face" in exc.value.detail


def test_recognition_rejects_dark_frames_before_matching(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [{
            "facial_area": {"x": 20, "y": 20, "w": 200, "h": 200},
            "confidence": 0.99,
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.ones(128)}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": True,
        "error": None,
        "score": 0.98,
        "detector_backend": "minifasnet_onnx",
    })

    result = recognize(
        b"image",
        np.zeros((260, 260, 3), dtype=np.uint8),
        MemoryStore(),
        Settings(min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["message"] == "Too dark."
    assert result["quality"]["brightness"] == 0.0
