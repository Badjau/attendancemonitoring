import sqlite3
from pathlib import Path

from app.database import FaceStore


def test_initialize_adds_cached_at_to_existing_database(tmp_path: Path):
    database_path = tmp_path / "faces.sqlite"
    with sqlite3.connect(database_path) as connection:
        connection.execute(
            """
            create table face_embeddings (
                id integer primary key autoincrement,
                employee_id text not null,
                embedding text not null,
                image_sha256 text not null,
                pose_label text,
                model_name text not null default 'SFace',
                detector_backend text not null default 'yunet',
                quality_json text not null,
                created_at text not null default current_timestamp
            )
            """
        )
        connection.execute(
            """
            insert into face_embeddings
                (employee_id, embedding, image_sha256, quality_json)
            values ('EMP-001', '[1.0, 0.0, 0.0]', 'hash-a', '{}')
            """
        )

    store = FaceStore(database_path)
    with sqlite3.connect(store.database_path) as connection:
        connection.row_factory = sqlite3.Row
        row = connection.execute(
            "select cached_at from face_embeddings where employee_id = 'EMP-001'"
        ).fetchone()

    assert row["cached_at"]


def test_cache_refresh_replaces_employee_vectors(tmp_path: Path):
    store = FaceStore(tmp_path / "faces.sqlite")

    store.replace_employee_embeddings("EMP-001", [
        {
            "id": 1,
            "embedding": [1.0, 0.0, 0.0],
            "image_hash": "hash-a",
            "pose_label": "front",
            "model_name": "SFace",
            "detector_backend": "yunet",
            "quality": {"brightness": 100},
            "created_at": "2026-07-01T00:00:00Z",
            "updated_at": "2026-07-01T00:00:00Z",
        }
    ])
    store.replace_employee_embeddings("EMP-001", [
        {
            "id": 2,
            "embedding": [0.0, 1.0, 0.0],
            "image_hash": "hash-b",
            "pose_label": "left",
            "model_name": "SFace",
            "detector_backend": "yunet",
            "quality": {"brightness": 120},
            "created_at": "2026-07-01T01:00:00Z",
            "updated_at": "2026-07-01T01:00:00Z",
        }
    ])

    rows = store.employee_embeddings("EMP-001")

    assert len(rows) == 1
    assert rows[0]["embedding"].tolist() == [0.0, 1.0, 0.0]
    assert rows[0]["server_updated_at"] == "2026-07-01T01:00:00Z"
