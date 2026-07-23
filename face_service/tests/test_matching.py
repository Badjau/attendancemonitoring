from io import BytesIO

import numpy as np
from fastapi.testclient import TestClient
from PIL import Image

from app.config import Settings
from app.main import app
from app.recognition import detect_faces, recognize, recognize_face_session, verify_employee_face


class MemoryStore:
    def __init__(self, rows):
        self.rows = rows

    def embeddings(self):
        return self.rows

    def employee_embeddings(self, employee_id):
        return [row for row in self.rows if row["employee_id"] == employee_id]


def patch_recognizer(monkeypatch, embedding):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
            "is_real": True,
            "antispoof_score": 0.98,
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": embedding}]),
    }))


def patch_multi_face_recognizer(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [
            {
                "facial_area": {"x": 10, "y": 10, "w": 100, "h": 100},
                "confidence": 0.99,
            },
            {
                "facial_area": {"x": 130, "y": 10, "w": 100, "h": 100},
                "confidence": 0.98,
            },
        ]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))


def jpeg_bytes() -> bytes:
    buffer = BytesIO()
    Image.fromarray(np.full((240, 240, 3), 128, dtype=np.uint8)).save(buffer, format="JPEG")

    return buffer.getvalue()


def test_known_employee_matches(monkeypatch):
    probe = np.array([1.0, 0.0, 0.0])
    patch_recognizer(monkeypatch, probe)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])},
            {"employee_id": "EMP-002", "embedding": np.array([0.0, 1.0, 0.0])},
        ]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["employee_id"] == "EMP-001"


def test_targeted_verification_only_matches_requested_employee(monkeypatch):
    probe = np.array([1.0, 0.0, 0.0])
    patch_recognizer(monkeypatch, probe)

    result = verify_employee_face(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        "EMP-001",
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])},
            {"employee_id": "EMP-002", "embedding": np.array([1.0, 0.0, 0.0])},
        ]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["employee_id"] == "EMP-001"


def test_targeted_verification_rejects_other_employee_face(monkeypatch):
    patch_recognizer(monkeypatch, np.array([0.0, 1.0, 0.0]))

    result = verify_employee_face(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        "EMP-001",
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])},
            {"employee_id": "EMP-002", "embedding": np.array([0.0, 1.0, 0.0])},
        ]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["employee_id"] is None


def test_targeted_verification_missing_vectors_returns_not_enrolled(monkeypatch):
    patch_recognizer(monkeypatch, np.array([1.0, 0.0, 0.0]))

    result = verify_employee_face(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        "EMP-404",
        MemoryStore([]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert "no enrolled face embeddings" in result["message"].lower()


def test_unknown_employee_is_rejected(monkeypatch):
    patch_recognizer(monkeypatch, np.array([0.0, 1.0, 0.0]))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["employee_id"] is None


def test_multiple_faces_fail_recognition_with_step_out_message(monkeypatch):
    patch_multi_face_recognizer(monkeypatch)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["face_count"] == 2
    assert result["message"] == "Multiple faces detected. Please step out of the camera view."


def test_detect_faces_reports_multiple_faces_with_step_out_message(monkeypatch):
    patch_multi_face_recognizer(monkeypatch)

    result = detect_faces(np.zeros((240, 240, 3), dtype=np.uint8), Settings())

    assert result == {
        "face_count": 2,
        "message": "Multiple faces detected. Please step out of the camera view.",
    }


def test_detect_endpoint_reports_multiple_faces(monkeypatch):
    patch_multi_face_recognizer(monkeypatch)

    response = TestClient(app).post(
        "/api/detect",
        files={"image": ("frame.jpg", jpeg_bytes(), "image/jpeg")},
    )

    assert response.status_code == 200
    assert response.json() == {
        "face_count": 2,
        "message": "Multiple faces detected. Please step out of the camera view.",
    }


def test_ambiguous_match_is_rejected(monkeypatch):
    probe = np.array([1.0, 0.0, 0.0])
    patch_recognizer(monkeypatch, probe)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])},
            {"employee_id": "EMP-002", "embedding": np.array([0.9999, 0.01, 0.0])},
        ]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert "ambiguous" in result["message"]


def test_spoofed_face_is_rejected(monkeypatch):
    from app import recognition

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(lambda **kwargs: [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
            "is_real": False,
            "antispoof_score": 0.12,
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["spoofing_passed"] is False


def test_yunet_detection_invokes_full_frame_opencv_anti_spoofing(monkeypatch):
    from app import recognition

    calls = []

    def fake_extract_faces(**kwargs):
        calls.append(kwargs)
        if kwargs.get("anti_spoofing"):
            assert kwargs["detector_backend"] == "opencv"
            assert kwargs["img_path"].shape == (240, 240, 3)
            return [{
                "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
                "confidence": 0.99,
                "is_real": True,
                "antispoof_score": 0.96,
            }]

        assert kwargs["detector_backend"] == "yunet"
        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["spoofing_checked"] is True
    assert [call["detector_backend"] for call in calls[:2]] == ["yunet", "opencv"]
    assert calls[1]["anti_spoofing"] is True


def test_no_face_detection_does_not_attempt_anti_spoofing(monkeypatch):
    from app import recognition

    calls = []

    def fake_extract_faces(**kwargs):
        calls.append(kwargs)
        return []

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert not any(call.get("anti_spoofing") for call in calls)


def test_face_session_rejects_spoof_before_embedding(monkeypatch):
    from app import recognition

    represent_calls = []

    def fake_extract_faces(**kwargs):
        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
            "is_real": not kwargs.get("anti_spoofing"),
            "antispoof_score": 0.12 if kwargs.get("anti_spoofing") else None,
        }]

    def fake_represent(**kwargs):
        represent_calls.append(kwargs)
        return [{"embedding": np.array([1.0, 0.0, 0.0])}]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(fake_represent),
    }))

    result = recognize_face_session(
        [(b"image", np.zeros((240, 240, 3), dtype=np.uint8))],
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["decision"] == "fallback"
    assert result["reason_code"] == "spoof_detected"
    assert represent_calls == []


def test_anti_spoofing_unsupported_does_not_crash(monkeypatch):
    from app import recognition

    def fake_extract_faces(**kwargs):
        if kwargs.get("anti_spoofing"):
            raise TypeError("got an unexpected keyword argument 'anti_spoofing'")

        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(require_anti_spoofing=False, min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["spoofing_checked"] is False


def test_optional_anti_spoofing_allows_unconfirmed_liveness(monkeypatch):
    from app import recognition

    def fake_extract_faces(**kwargs):
        if kwargs.get("anti_spoofing"):
            return []

        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(require_anti_spoofing=False, min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["spoofing_checked"] is False


def test_required_anti_spoofing_rejects_when_unavailable(monkeypatch):
    from app import recognition

    def fake_extract_faces(**kwargs):
        if kwargs.get("anti_spoofing"):
            raise RuntimeError("anti_spoofing is unavailable")

        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(require_anti_spoofing=True, min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["spoofing_checked"] is False
