from __future__ import annotations
from typing import Any
from bot.repositories.devices import DevicesRepository
from bot.repositories.telegram import TelegramRepository
from bot.repositories.users import UsersRepository
from bot.utils.security import clean_text
class DeviceService:
    def __init__(
        self,
        users: UsersRepository,
        devices: DevicesRepository,
        telegram: TelegramRepository,
    ) -> None:
        self.users = users
        self.devices = devices
        self.telegram = telegram
    async def linked_user(self, telegram_user_id: int) -> dict[str, Any] | None:
        user = await self.users.linked_by_telegram(telegram_user_id)
        if user:
            await self.telegram.touch_session(telegram_user_id, int(user["id"]))
        return user
    async def selected(
        self, user: dict[str, Any], telegram_user_id: int
    ) -> dict[str, Any] | None:
        user_id = int(user["id"])
        await self.telegram.touch_session(telegram_user_id, user_id)
        devices = await self.devices.list_active(user_id)
        if not devices:
            return None
        session = await self.telegram.session(telegram_user_id, user_id)
        selected_id = str((session or {}).get("selected_device_id") or "")
        if selected_id:
            selected = await self.devices.owned(user_id, selected_id)
            if selected:
                return selected
        selected = devices[0]
        await self.telegram.select_device(
            telegram_user_id, user_id, str(selected["device_id"])
        )
        return selected
    async def choose(
        self, user: dict[str, Any], telegram_user_id: int, row_id: int
    ) -> dict[str, Any]:
        device = await self.devices.owned_by_row_id(int(user["id"]), row_id)
        if device is None:
            raise PermissionError("Устройство не найдено или не принадлежит аккаунту.")
        await self.telegram.select_device(
            telegram_user_id, int(user["id"]), str(device["device_id"])
        )
        return device
    async def rename(self, user: dict[str, Any], device_id: str, name: str) -> None:
        normalized = clean_text(name, 120)
        if len(normalized) < 1:
            raise ValueError("Название не может быть пустым.")
        if not await self.devices.rename(int(user["id"]), device_id, normalized):
            raise PermissionError("Устройство не найдено или не принадлежит аккаунту.")