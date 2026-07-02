import base64

import numpy as np
from fastapi.testclient import TestClient

from app import main
from app.config import Settings
from app.recognition import FaceAnalysis


class EnrollmentStore:
    def employee_status(self, employee_id):
        return {
            "employee_id": employee_id,
            "enrollment_count": 1,
            "last_enrolled_at": "2026-07-02T00:00:00Z",
        }


def test_enrollment_posts_uploaded_image_base64_to_laravel(monkeypatch):
    uploaded = b"jpeg-bytes"
    payloads = []

    async def fake_read_upload_image(upload):
        return uploaded, np.zeros((2, 2, 3), dtype=np.uint8)

    def fake_analyze_single_face(content, rgb, settings):
        return FaceAnalysis(
            image_sha256="abc123",
            rgb=rgb,
            facial_area={"x": 0, "y": 0, "w": 2, "h": 2},
            embedding=np.array([0.1, 0.2, 0.3]),
            quality={"brightness": 100},
            spoofing={"checked": False},
        )

    def fake_laravel_json_request(path, settings, payload=None):
        payloads.append((path, payload))
        return {}

    monkeypatch.setattr(main, "read_upload_image", fake_read_upload_image)
    monkeypatch.setattr(main, "analyze_single_face", fake_analyze_single_face)
    monkeypatch.setattr(main, "laravel_json_request", fake_laravel_json_request)
    monkeypatch.setattr(main, "refresh_employee_cache", lambda employee_id, face_store, settings: {})

    main.app.dependency_overrides[main.get_settings] = lambda: Settings(min_enrollments=1)
    main.app.dependency_overrides[main.get_store] = lambda: EnrollmentStore()

    try:
        response = TestClient(main.app).post(
            "/api/employees/EMP-001/enroll",
            files={"image": ("face.jpg", uploaded, "image/jpeg")},
            data={"pose_label": "front"},
        )
    finally:
        main.app.dependency_overrides.clear()

    assert response.status_code == 200
    assert payloads[0][0] == "/api/face/employees/EMP-001/embeddings"
    assert payloads[0][1]["profile_image_base64"] == base64.b64encode(uploaded).decode("ascii")
    assert payloads[0][1]["embedding"] == [0.1, 0.2, 0.3]


def test_enrollment_reset_existing_deletes_laravel_and_local_vectors_before_post(monkeypatch):
    uploaded = b"updated-jpeg-bytes"
    requests = []
    deleted = []

    async def fake_read_upload_image(upload):
        return uploaded, np.zeros((2, 2, 3), dtype=np.uint8)

    def fake_analyze_single_face(content, rgb, settings):
        return FaceAnalysis(
            image_sha256="def456",
            rgb=rgb,
            facial_area={"x": 0, "y": 0, "w": 2, "h": 2},
            embedding=np.array([0.4, 0.5, 0.6]),
            quality={"brightness": 110},
            spoofing={"checked": False},
        )

    def fake_laravel_json_request(path, settings, payload=None, method=None):
        requests.append((method or ("POST" if payload is not None else "GET"), path, payload))
        return {}

    class ResetStore(EnrollmentStore):
        def delete_employee(self, employee_id):
            deleted.append(employee_id)
            return 3

    monkeypatch.setattr(main, "read_upload_image", fake_read_upload_image)
    monkeypatch.setattr(main, "analyze_single_face", fake_analyze_single_face)
    monkeypatch.setattr(main, "laravel_json_request", fake_laravel_json_request)
    monkeypatch.setattr(main, "refresh_employee_cache", lambda employee_id, face_store, settings: {})

    main.app.dependency_overrides[main.get_settings] = lambda: Settings(min_enrollments=3)
    main.app.dependency_overrides[main.get_store] = lambda: ResetStore()

    try:
        response = TestClient(main.app).post(
            "/api/employees/EMP-001/enroll?reset_existing=true",
            files={"image": ("face.jpg", uploaded, "image/jpeg")},
            data={"pose_label": "front"},
        )
    finally:
        main.app.dependency_overrides.clear()

    assert response.status_code == 200
    assert requests[0] == ("DELETE", "/api/face/employees/EMP-001/embeddings", None)
    assert requests[1][0] == "POST"
    assert requests[1][1] == "/api/face/employees/EMP-001/embeddings"
    assert requests[1][2]["profile_image_base64"] == base64.b64encode(uploaded).decode("ascii")
    assert deleted == ["EMP-001"]
