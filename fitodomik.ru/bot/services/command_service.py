from __future__ import annotations
from datetime import datetime
from typing import Any
from bot.database import Database
from bot.repositories.commands import CommandsRepository
from bot.repositories.telegram import TelegramRepository
from bot.utils.security import callback_token_hash, new_callback_token, safe_json_object
DANGEROUS_COMMANDS = {"relay_pulse"}
class CommandService:
    def __init__(
        self,
        database: Database,
        commands: CommandsRepository,
        telegram: TelegramRepository,
    ) -> None:
        self.database = database
        self.commands = commands
        self.telegram = telegram
    async def enqueue(
        self,
        user_id: int,
        device_id: str,
        telegram_user_id: int,
        command: str,
        payload: dict[str, Any] | None = None,
    ) -> int:
        async with self.database.transaction() as connection:
            if not await self.telegram.active_link_for_update(user_id, telegram_user_id, connection):
                raise PermissionError("Telegram binding is no longer active")
            return await self.commands.create(
                user_id, device_id, telegram_user_id, command, payload, connection=connection
            )
    async def request_confirmation(
        self,
        user_id: int,
        device_id: str,
        telegram_user_id: int,
        command: str,
        payload: dict[str, Any],
    ) -> str:
        token, token_hash = new_callback_token()
        await self.telegram.create_callback(
            token_hash, user_id, telegram_user_id, device_id, command, payload
        )
        return token
    async def consume_confirmation(
        self, token: str, telegram_user_id: int, cancel: bool
    ) -> tuple[bool, str, int | None]:
        token_hash = callback_token_hash(token)
        async with self.database.transaction() as connection:
            action = await self.telegram.callback_for_update(
                token_hash, telegram_user_id, connection
            )
            if action is None or action.get("status") != "pending":
                return False, "Кнопка недействительна или уже использована.", None
            expires_at = action.get("expires_at")
            if isinstance(expires_at, datetime) and expires_at < datetime.now():
                await self.telegram.finish_callback(
                    int(action["id"]), "expired", connection
                )
                return False, "Срок подтверждения истёк.", None
            if cancel:
                await self.telegram.finish_callback(
                    int(action["id"]), "cancelled", connection
                )
                return True, "Команда отменена.", None
            command_id = await self.commands.create(
                int(action["user_id"]),
                str(action["device_id"]),
                telegram_user_id,
                str(action["action"]),
                safe_json_object(action.get("action_payload")),
                connection=connection,
            )
            if not await self.telegram.finish_callback(
                int(action["id"]), "used", connection
            ):
                raise RuntimeError("Confirmation was processed concurrently")
        return True, "Команда подтверждена и отправлена устройству.", command_id
