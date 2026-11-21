from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import CallbackQuery, Message
from bot.handlers.common import linked_callback, linked_message, selected_message
from bot.keyboards.control import command_keyboard
from bot.services.container import Services
from bot.utils.formatting import plain
router = Router(name=__name__)
@router.message(Command("analysis"))
@router.message(F.text.in_({"Анализ", "Анализ растения"}))
async def analysis(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    row = await services.photo.latest_analysis(
        int(user["id"]), str(device["device_id"])
    )
    if not row:
        await message.answer(
            "Анализ для выбранного устройства пока не выполнялся.",
            reply_markup=command_keyboard("analyze_plant", "Запустить анализ"),
        )
        return
    original = services.photo.local_photo(row.get("photo"))
    result = services.photo.local_photo(row.get("photo_analysis"))
    if original:
        await message.answer_photo(
            original, caption=f"Исходное фото: {device['device_name']}"
        )
    if result:
        await message.answer_photo(
            result, caption=f"Результат анализа: {device['device_name']}"
        )
    report = (
        plain(row.get("comment"), 3500) or "Текстовый отчёт анализа пока отсутствует."
    )
    await message.answer(
        "Отчёт анализа\n\n" + report,
        reply_markup=command_keyboard("analyze_plant", "Запустить новый анализ"),
    )
@router.message(Command("analyze_now"))
@router.message(F.text == "Анализ сейчас")
async def analyze_now(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    command_id = await services.command.enqueue(
        int(user["id"]),
        str(device["device_id"]),
        int(message.from_user.id),
        "analyze_plant",
    )
    await message.answer(
        f"Анализ изображения поставлен в очередь. Команда #{command_id}. "
        "Результат придёт автоматически."
    )
@router.callback_query(F.data == "command:analyze_plant")
async def analyze_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer("Нет активного устройства.", show_alert=True)
        return
    command_id = await services.command.enqueue(
        int(user["id"]),
        str(device["device_id"]),
        int(callback.from_user.id),
        "analyze_plant",
    )
    await callback.answer("Анализ поставлен в очередь")
    if callback.message:
        await callback.message.answer(f"Запущена команда анализа #{command_id}.")
