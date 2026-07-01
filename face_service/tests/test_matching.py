import numpy as np

from app.config import Settings
from app.recognition import recognize


class MemoryStore:
    def __init__(self, rows):
        self.rows = rows

    def embeddings(self):
        return self.rows


def patch_recognizer(monkeypatch, embedding):
    from app import recognition

    monkeypatch.setattr(recognition, "face_recognition", type("Fake", (), {
        "face_locations": staticmethod(lambda image, model="hog": [(10, 210, 210, 10)]),
        "face_encodings": staticmethod(lambda image, known_face_locations=None: [embedding]),
    }))


def test_known_employee_matches(monkeypatch):
    probe = np.zeros(128)
    patch_recognizer(monkeypatch, probe)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.zeros(128)},
            {"employee_id": "EMP-002", "embedding": np.ones(128)},
        ]),
        Settings(),
    )

    assert result["matched"] is True
    assert result["employee_id"] == "EMP-001"


def test_unknown_employee_is_rejected(monkeypatch):
    patch_recognizer(monkeypatch, np.ones(128))

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([{"employee_id": "EMP-001", "embedding": np.zeros(128)}]),
        Settings(),
    )

    assert result["matched"] is False
    assert result["employee_id"] is None


def test_ambiguous_match_is_rejected(monkeypatch):
    probe = np.zeros(128)
    patch_recognizer(monkeypatch, probe)

    result = recognize(
        b"image",
        np.zeros((240, 240, 3), dtype=np.uint8),
        MemoryStore([
            {"employee_id": "EMP-001", "embedding": np.zeros(128)},
            {"employee_id": "EMP-002", "embedding": np.full(128, 0.001)},
        ]),
        Settings(),
    )

    assert result["matched"] is False
    assert "ambiguous" in result["message"]
