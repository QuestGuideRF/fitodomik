from __future__ import annotations
from typing import Any
from aiogram.types import CallbackQuery, Message, User
from bot.keyboards.main_menu import main_menu, unlinked_menu
from bot.services.container import Services
def telegram_identity(user: User, chat_id: int | str) -> dict[str, Any]:
    return {
        "id": int(user.id),
        "chat_id": str(chat_id),
        "username": user.username or "",
        "first_name": user.first_name or "",
        "last_name": user.last_name or "",
    }
async def linked_message(message: Message, services: Services) -> dict[str, Any] | None:
    if message.from_user is None:
        return None
    user = await services.device.linked_user(int(message.from_user.id))
    if user is None:
        await message.answer(
            "Сначала привяжите Telegram к профилю в настройках сайта ФитоДомик.",
            reply_markup=unlinked_menu(),
        )
    return user
async def linked_callback(
    callback: CallbackQuery, services: Services
) -> dict[str, Any] | None:
    user = await services.device.linked_user(int(callback.from_user.id))
    if user is None:
        await callback.answer("Сначала привяжите Telegram к профилю.", show_alert=True)
    return user
async def selected_message(
    message: Message, services: Services, user: dict[str, Any]
) -> dict[str, Any] | None:
    if message.from_user is None:
        return None
    device = await services.device.selected(user, int(message.from_user.id))
    if device is None:
        await message.answer(
            "Активных устройств нет. Привяжите Raspberry Pi через сайт.",
            reply_markup=main_menu(),
        )
    return device