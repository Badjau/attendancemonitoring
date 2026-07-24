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
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": embedding}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": True,
        "error": None,
        "score": 0.98,
        "detector_backend": "minifasnet_onnx",
    })


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
        }]),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": False,
        "error": None,
        "score": 0.12,
        "detector_backend": "minifasnet_onnx",
    })

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is False
    assert result["spoofing_passed"] is False


def test_yunet_detection_passes_detected_face_crop_to_anti_spoofing(monkeypatch):
    from app import recognition

    calls = []
    liveness_calls = []

    def fake_extract_faces(**kwargs):
        calls.append(kwargs)
        assert kwargs["detector_backend"] == "yunet"
        return [{
            "facial_area": {"x": 10, "y": 10, "w": 200, "h": 200},
            "confidence": 0.99,
        }]

    def fake_liveness(rgb, facial_area, settings):
        liveness_calls.append((rgb, facial_area, settings))
        return {
            "checked": True,
            "required": bool(settings.require_anti_spoofing),
            "is_real": True,
            "error": None,
            "score": 0.96,
            "detector_backend": "minifasnet_onnx",
        }

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(lambda **kwargs: [{"embedding": np.array([1.0, 0.0, 0.0])}]),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", fake_liveness)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(min_brightness=0, min_blur_score=0),
    )

    assert result["matched"] is True
    assert result["spoofing_checked"] is True
    assert len(liveness_calls) == 1
    assert liveness_calls[0][1] == {"x": 10, "y": 10, "w": 200, "h": 200, "confidence": 0.99}
    assert not any(call.get("anti_spoofing") for call in calls)


def test_crop_liveness_classifier_uses_expanded_yunet_box(monkeypatch):
    from app import recognition

    inputs = []

    class FakeInput:
        name = "input"

    class FakeSession:
        def get_inputs(self):
            return [FakeInput()]

        def run(self, _outputs, feed):
            inputs.append(feed["input"])
            return [np.array([[2.0, 0.0]], dtype=np.float32)]

    monkeypatch.setattr(recognition, "anti_spoof_session", lambda settings: FakeSession())

    result = recognition.classify_face_liveness(
        np.zeros((240, 320, 3), dtype=np.uint8),
        {"x": 100, "y": 50, "w": 80, "h": 100},
        Settings(
            anti_spoofing_input_size=128,
            anti_spoofing_crop_scale=2.0,
            anti_spoofing_real_threshold=0.8,
        ),
    )

    assert result["checked"] is True
    assert result["is_real"] is True
    assert result["score"] == 0.8808
    assert result["detector_backend"] == "facenox_minifasnet_onnx"
    assert inputs[0].shape == (1, 3, 128, 128)


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
        }]

    def fake_represent(**kwargs):
        represent_calls.append(kwargs)
        return [{"embedding": np.array([1.0, 0.0, 0.0])}]

    monkeypatch.setattr(recognition, "DeepFace", type("Fake", (), {
        "extract_faces": staticmethod(fake_extract_faces),
        "represent": staticmethod(fake_represent),
    }))
    monkeypatch.setattr(recognition, "classify_face_liveness", lambda rgb, facial_area, settings: {
        "checked": True,
        "required": bool(settings.require_anti_spoofing),
        "is_real": False,
        "error": None,
        "score": 0.12,
        "detector_backend": "minifasnet_onnx",
    })

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
