from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import Message
from bot.handlers.common import linked_message, selected_message
from bot.services.container import Services
from bot.utils.formatting import plain, timestamp
router = Router(name=__name__)
@router.message(Command("events"))
@router.message(F.text.in_({"События", "Журнал событий"}))
async def events(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    parts = (message.text or "").split()
    event_filter = parts[1].lower() if len(parts) > 1 else "all"
    if event_filter not in {"all", "alerts", "errors", "control"}:
        await message.answer("Фильтры: /events all|alerts|errors|control")
        return
    rows = await services.alerts.events(
        int(user["id"]), str(device["device_id"]), event_filter
    )
    if not rows:
        await message.answer("Событий по выбранному фильтру нет.")
        return
    lines = ["Журнал событий", f"Устройство: {plain(device['device_name'], 120)}"]
    for row in rows:
        lines.append(
            f"{timestamp(row.get('created_at'))} — {plain(row.get('event_description'), 500)}"
        )
    await message.answer("\n\n".join(lines))