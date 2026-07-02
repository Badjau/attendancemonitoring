import numpy as np

from app.config import Settings
from app.recognition import recognize, verify_employee_face


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
        Settings(),
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
        Settings(),
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
        Settings(),
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
        Settings(),
    )

    assert result["matched"] is False
    assert "no enrolled face embeddings" in result["message"].lower()


def test_unknown_employee_is_rejected(monkeypatch):
    patch_recognizer(monkeypatch, np.array([0.0, 1.0, 0.0]))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.array([1.0, 0.0, 0.0])}]),
        Settings(),
    )

    assert result["matched"] is False
    assert result["employee_id"] is None


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
        Settings(),
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
        Settings(),
    )

    assert result["matched"] is False
    assert result["spoofing_passed"] is False


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
        Settings(require_anti_spoofing=False),
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
        Settings(require_anti_spoofing=True),
    )

    assert result["matched"] is False
    assert result["spoofing_checked"] is False
