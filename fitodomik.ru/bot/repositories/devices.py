from __future__ import annotations
from typing import Any
from bot.database import Database
class DevicesRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def list_active(self, user_id: int) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT id, device_id, device_name, status, linked_at, last_seen_at
            FROM user_devices WHERE user_id=%s AND status='active'
            ORDER BY linked_at ASC, id ASC
            """,
            (user_id,),
        )
    async def owned(self, user_id: int, device_id: str) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT id, device_id, device_name, status, linked_at, last_seen_at
            FROM user_devices
            WHERE user_id=%s AND device_id=%s AND status='active' LIMIT 1
            """,
            (user_id, device_id),
        )
    async def owned_by_row_id(self, user_id: int, row_id: int) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT id, device_id, device_name, status, linked_at, last_seen_at
            FROM user_devices WHERE user_id=%s AND id=%s AND status='active' LIMIT 1
            """,
            (user_id, row_id),
        )
    async def rename(self, user_id: int, device_id: str, name: str) -> bool:
        changed = await self.database.execute(
            "UPDATE user_devices SET device_name=%s WHERE user_id=%s AND device_id=%s AND status='active'",
            (name, user_id, device_id),
        )
        return changed == 1
    async def relays(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT id, relay_uid, name, relay_type, config_json
            FROM device_relay_definitions
            WHERE user_id=%s AND device_id=%s AND enabled=1 AND show_in_control=1
            ORDER BY id ASC LIMIT 64
            """,
            (user_id, device_id),
        )