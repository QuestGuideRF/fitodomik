from __future__ import annotations
import hashlib
import re
import secrets
from typing import Any
from bot.database import Database
from bot.repositories.telegram import TelegramRepository
DEEP_LINK_RE = re.compile(r"^link_([a-f0-9]{32})$")
MANUAL_CODE_RE = re.compile(r"^[A-Z0-9]{6,20}$")
class LinkError(ValueError):
    pass
class TelegramLinksService:
    def __init__(self, database: Database, repository: TelegramRepository) -> None:
        self.database = database
        self.repository = repository
    @staticmethod
    def extract_payload(text: str) -> str:
        value = text.strip()
        for pattern in (
            r"^/start\s+(link_[a-f0-9]{32})$",
            r"^/link\s+([A-Za-z0-9]{6,20})$",
            r"^/start\s+([A-Za-z0-9]{6,20})$",
            r"^([A-Fa-f0-9]{8})$",
        ):
            match = re.fullmatch(pattern, value)
            if match:
                return match.group(1)
        return ""
    async def link(self, code: str, telegram: dict[str, Any]) -> dict[str, Any]:
        deep_match = DEEP_LINK_RE.fullmatch(code)
        normalized = code if deep_match else code.upper()
        if not deep_match and not MANUAL_CODE_RE.fullmatch(normalized):
            raise LinkError("Код привязки должен состоять из букв и цифр.")
        deep_hash = (
            hashlib.sha256(deep_match.group(1).encode("ascii")).hexdigest()
            if deep_match
            else None
        )
        async with self.database.transaction() as connection:
            row = await self.repository.link_code_for_update(
                normalized,
                deep_token_hash=deep_hash,
                connection=connection,
            )
            if row is None:
                raise LinkError(
                    "Код привязки не найден, уже использован или истёк. Создайте новый код на сайте."
                )
            user = await self.database.fetch_one(
                "SELECT id,username,first_name,last_name FROM users "
                "WHERE id=%s LIMIT 1 FOR UPDATE",
                (int(row["user_id"]),),
                connection=connection,
            )
            if user is None:
                raise LinkError("Аккаунт для кода привязки не найден.")
            if not await self.repository.bind(
                user, telegram, int(row["id"]), connection
            ):
                raise LinkError(
                    "Код привязки уже использован. Создайте новый код на сайте."
                )
        return user
    async def create_login_token(self, user_id: int, chat_id: str) -> str:
        token = secrets.token_urlsafe(32)
        token_hash = hashlib.sha256(token.encode("ascii")).hexdigest()
        await self.repository.create_login_token(user_id, chat_id, token_hash)
        return token
