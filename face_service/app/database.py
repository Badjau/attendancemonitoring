import json
import sqlite3
from contextlib import contextmanager
from pathlib import Path
from typing import TYPE_CHECKING, Iterator

if TYPE_CHECKING:
    import numpy as np


class FaceStore:
    def __init__(self, database_path: Path):
        self.database_path = database_path
        self.database_path.parent.mkdir(parents=True, exist_ok=True)
        self.initialize()

    @contextmanager
    def connect(self) -> Iterator[sqlite3.Connection]:
        connection = sqlite3.connect(self.database_path)
        connection.row_factory = sqlite3.Row
        try:
            yield connection
            connection.commit()
        finally:
            connection.close()

    def initialize(self) -> None:
        with self.connect() as connection:
            connection.execute(
                """
                create table if not exists face_embeddings (
                    id integer primary key autoincrement,
                    server_id integer,
                    employee_id text not null,
                    embedding text not null,
                    image_sha256 text not null,
                    pose_label text,
                    model_name text not null default 'SFace',
                    detector_backend text not null default 'yunet',
                    quality_json text not null,
                    server_updated_at text,
                    cached_at text not null default current_timestamp,
                    created_at text not null default current_timestamp
                )
                """
            )
            self._ensure_column(connection, "server_id", "integer")
            self._ensure_column(connection, "model_name", "text not null default 'SFace'")
            self._ensure_column(connection, "detector_backend", "text not null default 'yunet'")
            self._ensure_column(connection, "server_updated_at", "text")
            self._ensure_column(connection, "cached_at", "text")
            connection.execute(
                "update face_embeddings set cached_at = current_timestamp where cached_at is null"
            )
            connection.execute(
                "create index if not exists idx_face_embeddings_employee on face_embeddings(employee_id)"
            )
            connection.execute(
                "create unique index if not exists idx_face_embeddings_server on face_embeddings(server_id) where server_id is not null"
            )

    def _ensure_column(self, connection: sqlite3.Connection, name: str, definition: str) -> None:
        columns = {
            row["name"]
            for row in connection.execute("pragma table_info(face_embeddings)").fetchall()
        }

        if name not in columns:
            connection.execute(f"alter table face_embeddings add column {name} {definition}")

    def add_embedding(
        self,
        employee_id: str,
        embedding: "np.ndarray",
        image_sha256: str,
        pose_label: str | None,
        quality: dict,
        model_name: str = "SFace",
        detector_backend: str = "yunet",
    ) -> None:
        with self.connect() as connection:
            connection.execute(
                """
                insert into face_embeddings
                    (employee_id, embedding, image_sha256, pose_label, model_name, detector_backend, quality_json)
                values (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    employee_id,
                    json.dumps([float(value) for value in embedding]),
                    image_sha256,
                    pose_label,
                    model_name,
                    detector_backend,
                    json.dumps(quality),
                ),
            )

    def embeddings(self) -> list[dict]:
        import numpy as np

        with self.connect() as connection:
            rows = connection.execute(
                "select employee_id, embedding, model_name, detector_backend, created_at from face_embeddings"
            ).fetchall()

        return [
            {
                "employee_id": row["employee_id"],
                "embedding": np.array(json.loads(row["embedding"]), dtype=np.float64),
                "model_name": row["model_name"],
                "detector_backend": row["detector_backend"],
                "created_at": row["created_at"],
            }
            for row in rows
        ]

    def employee_embeddings(self, employee_id: str) -> list[dict]:
        import numpy as np

        with self.connect() as connection:
            rows = connection.execute(
                """
                select employee_id, embedding, model_name, detector_backend, created_at, server_updated_at, cached_at
                from face_embeddings
                where employee_id = ?
                """,
                (employee_id,),
            ).fetchall()

        return [
            {
                "employee_id": row["employee_id"],
                "embedding": np.array(json.loads(row["embedding"]), dtype=np.float64),
                "model_name": row["model_name"],
                "detector_backend": row["detector_backend"],
                "created_at": row["created_at"],
                "server_updated_at": row["server_updated_at"],
                "cached_at": row["cached_at"],
            }
            for row in rows
        ]

    def employee_cache_refreshed_at(self, employee_id: str) -> str | None:
        with self.connect() as connection:
            row = connection.execute(
                "select max(cached_at) as cached_at from face_embeddings where employee_id = ?",
                (employee_id,),
            ).fetchone()

        return row["cached_at"] if row else None

    def replace_employee_embeddings(self, employee_id: str, embeddings: list[dict]) -> None:
        with self.connect() as connection:
            connection.execute("delete from face_embeddings where employee_id = ?", (employee_id,))
            for item in embeddings:
                connection.execute(
                    """
                    insert into face_embeddings
                        (server_id, employee_id, embedding, image_sha256, pose_label, model_name, detector_backend, quality_json, server_updated_at, cached_at, created_at)
                    values (?, ?, ?, ?, ?, ?, ?, ?, ?, current_timestamp, coalesce(?, current_timestamp))
                    """,
                    (
                        item.get("id"),
                        employee_id,
                        json.dumps([float(value) for value in item["embedding"]]),
                        item.get("image_hash") or item.get("image_sha256") or "",
                        item.get("pose_label"),
                        item.get("model_name") or "SFace",
                        item.get("detector_backend") or "yunet",
                        json.dumps(item.get("quality") or {}),
                        item.get("updated_at"),
                        item.get("created_at"),
                    ),
                )

    def employee_status(self, employee_id: str) -> dict:
        with self.connect() as connection:
            row = connection.execute(
                """
                select count(*) as enrollment_count, max(created_at) as last_enrolled_at
                from face_embeddings
                where employee_id = ?
                """,
                (employee_id,),
            ).fetchone()

        return {
            "employee_id": employee_id,
            "enrollment_count": int(row["enrollment_count"] or 0),
            "last_enrolled_at": row["last_enrolled_at"],
        }

    def delete_employee(self, employee_id: str) -> int:
        with self.connect() as connection:
            cursor = connection.execute(
                "delete from face_embeddings where employee_id = ?",
                (employee_id,),
            )
            return cursor.rowcount
