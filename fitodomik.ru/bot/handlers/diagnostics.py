from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import Message
from bot.handlers.common import linked_message, selected_message
from bot.services.container import Services
from bot.utils.formatting import timestamp
router = Router(name=__name__)
@router.message(Command("diagnostics"))
@router.message(F.text == "Диагностика")
async def diagnostics(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    sensor = await services.sensors.latest(int(user["id"]), str(device["device_id"]))
    photo = await services.photo.latest(int(user["id"]), str(device["device_id"]))
    try:
        dynamic_count = len(
            await services.sensors.dynamic(int(user["id"]), str(device["device_id"]))
        )
    except Exception:
        dynamic_count = 0
    await services.database.ping()
    await message.answer(
        "\n".join(
            (
                "Диагностика",
                "",
                "Telegram API: доступен",
                "MySQL: доступен",
                f"Устройство: {device['device_name']}",
                f"device_id: {device['device_id']}",
                f"Последняя связь: {timestamp(device.get('last_seen_at'))}",
                f"Динамических датчиков: {dynamic_count}",
                f"Последние датчики: {timestamp((sensor or {}).get('created_at'))}",
                f"Последнее фото/анализ: {timestamp((photo or {}).get('created_at'))}",
                f"Arduino: {'данные поступают' if sensor else 'нет данных'}",
                f"Камера: {'есть последнее фото' if (photo or {}).get('photo') else 'нет фото'}",
            )
        )
    )