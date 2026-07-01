import json
import sqlite3
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

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
                    employee_id text not null,
                    embedding text not null,
                    image_sha256 text not null,
                    pose_label text,
                    quality_json text not null,
                    created_at text not null default current_timestamp
                )
                """
            )
            connection.execute(
                "create index if not exists idx_face_embeddings_employee on face_embeddings(employee_id)"
            )

    def add_embedding(
        self,
        employee_id: str,
        embedding: np.ndarray,
        image_sha256: str,
        pose_label: str | None,
        quality: dict,
    ) -> None:
        with self.connect() as connection:
            connection.execute(
                """
                insert into face_embeddings
                    (employee_id, embedding, image_sha256, pose_label, quality_json)
                values (?, ?, ?, ?, ?)
                """,
                (
                    employee_id,
                    json.dumps([float(value) for value in embedding]),
                    image_sha256,
                    pose_label,
                    json.dumps(quality),
                ),
            )

    def embeddings(self) -> list[dict]:
        with self.connect() as connection:
            rows = connection.execute(
                "select employee_id, embedding, created_at from face_embeddings"
            ).fetchall()

        return [
            {
                "employee_id": row["employee_id"],
                "embedding": np.array(json.loads(row["embedding"]), dtype=np.float64),
                "created_at": row["created_at"],
            }
            for row in rows
        ]

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
