from __future__ import annotations
from typing import Any
from bot.database import Database
class PhotosRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def latest(self, user_id: int, device_id: str) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT photo,photo_analysis,comment,created_at
            FROM farm_status WHERE user_id=%s AND device_id=%s
            ORDER BY created_at DESC LIMIT 1
            """,
            (user_id, device_id),
        )
    async def latest_analysis(
        self, user_id: int, device_id: str
    ) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT photo,photo_analysis,comment,created_at
            FROM farm_status
            WHERE user_id=%s AND device_id=%s
              AND photo_analysis IS NOT NULL AND photo_analysis<>''
            ORDER BY created_at DESC LIMIT 1
            """,
            (user_id, device_id),
        )
    async def daily_counts(self, user_id: int, device_id: str) -> dict[str, Any]:
        return (
            await self.database.fetch_one(
                """
                SELECT SUM(photo IS NOT NULL AND photo<>'') photo_count,
                       SUM(photo_analysis IS NOT NULL AND photo_analysis<>'') analysis_count
                FROM farm_status WHERE user_id=%s AND device_id=%s AND DATE(created_at)=CURDATE()
                """,
                (user_id, device_id),
            )
            or {}
        )