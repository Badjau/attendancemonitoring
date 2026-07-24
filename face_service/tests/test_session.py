from types import SimpleNamespace

from app.recognition import aggregate_face_session


def settings(**overrides):
    values = {
        "session_accept_score": 0.68,
        "session_retry_score": 0.45,
        "session_min_usable_frames": 2,
        "session_match_weight": 0.5,
        "session_liveness_weight": 0.25,
        "session_quality_weight": 0.2,
        "session_motion_weight": 0.05,
        "require_anti_spoofing": False,
    }
    values.update(overrides)
    return SimpleNamespace(**values)


def frame(employee_id="EMP-001", confidence=0.92, x=100, liveness=0.9, quality=0.9):
    return {
        "matched": True,
        "employee_id": employee_id,
        "confidence": confidence,
        "distance": 0.12,
        "margin": 0.2,
        "face_count": 1,
        "quality": {"width": 160, "height": 160},
        "quality_score": quality,
        "liveness_score": liveness,
        "facial_area": {"x": x, "y": 100, "w": 160, "h": 160},
    }


def test_face_session_accepts_consistent_low_risk_frames():
    result = aggregate_face_session(
        [frame(x=100), frame(x=112), frame(x=122)],
        settings(),
        evidence_image_base64="abc",
    )

    assert result["decision"] == "accept"
    assert result["employee_id"] == "EMP-001"
    assert result["reason_code"] == "session_accepted"
    assert result["risk_score"] < 0.32
    assert result["evidence_image_base64"] == "abc"


def test_face_session_requires_confirmed_liveness_only_when_configured():
    result = aggregate_face_session(
        [frame(x=100), frame(x=112), frame(x=122)],
        settings(require_anti_spoofing=True),
    )

    assert result["decision"] == "retry"
    assert result["reason_code"] == "liveness_unconfirmed"


def test_face_session_fallbacks_on_multiple_faces():
    result = aggregate_face_session(
        [
            frame(),
            {
                "matched": False,
                "face_count": 2,
                "quality": None,
                "quality_score": 0.0,
                "liveness_score": 0.55,
            },
        ],
        settings(),
    )

    assert result["decision"] == "fallback"
    assert result["reason_code"] == "multiple_faces"


def test_face_session_retries_when_identity_changes():
    result = aggregate_face_session(
        [
            frame(employee_id="EMP-001", confidence=0.7),
            frame(employee_id="EMP-002", confidence=0.7),
            frame(employee_id="EMP-003", confidence=0.7),
        ],
        settings(),
    )

    assert result["decision"] == "retry"
    assert result["reason_code"] == "session_uncertain"
