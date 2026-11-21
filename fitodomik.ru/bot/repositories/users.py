from __future__ import annotations
from typing import Any
from bot.database import Database
class UsersRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def get(self, user_id: int) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            "SELECT id, username, first_name, last_name FROM users WHERE id = %s AND is_locked = 0 LIMIT 1",
            (user_id,),
        )
    async def linked_by_telegram(self, telegram_user_id: int) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT u.id, u.username, u.first_name, u.last_name,
                   l.telegram_chat_id, l.telegram_user_id, l.id AS telegram_link_id
            FROM telegram_links l
            INNER JOIN users u ON u.id = l.user_id
            WHERE l.telegram_user_id = %s AND l.status = 'active' AND u.is_locked = 0
            ORDER BY l.linked_at DESC LIMIT 1
            """,
            (telegram_user_id,),
        )
    async def active_links(self) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT u.id, u.username, u.first_name, u.last_name,
                   l.telegram_chat_id, l.telegram_user_id
            FROM telegram_links l
            INNER JOIN users u ON u.id = l.user_id
            WHERE l.status = 'active' AND u.is_locked = 0
              AND CONVERT(l.telegram_chat_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =CONVERT(CAST(l.telegram_user_id AS CHAR) USING utf8mb4)
                    COLLATE utf8mb4_unicode_ci
              AND l.id=(
                  SELECT l2.id FROM telegram_links l2
                  WHERE l2.user_id=l.user_id AND l2.status='active'
                  ORDER BY l2.linked_at DESC,l2.id DESC LIMIT 1
              )
              AND l.id=(
                  SELECT l3.id FROM telegram_links l3
                  WHERE l3.telegram_user_id=l.telegram_user_id AND l3.status='active'
                  ORDER BY l3.linked_at DESC,l3.id DESC LIMIT 1
              )
            """
        )
