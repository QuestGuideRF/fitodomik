from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import CallbackQuery, Message
from bot.handlers.common import linked_callback, linked_message, selected_message
from bot.keyboards.control import command_keyboard
from bot.services.container import Services
router = Router(name=__name__)
@router.message(Command("photo"))
@router.message(F.text.in_({"Фото", "Фото растения"}))
async def photo(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    row = await services.photo.latest(int(user["id"]), str(device["device_id"]))
    photo_file = services.photo.local_photo((row or {}).get("photo"))
    if photo_file and row is not None:
        await message.answer_photo(
            photo_file,
            caption=f"Последнее фото: {device['device_name']}\n{row.get('created_at')}",
            reply_markup=command_keyboard("take_photo", "Сделать новое фото"),
        )
    else:
        await message.answer(
            f"Фото для устройства «{device['device_name']}» пока нет.",
            reply_markup=command_keyboard("take_photo", "Сделать фото"),
        )
@router.message(Command("take_photo"))
@router.message(F.text == "Сделать фото сейчас")
async def take_photo(message: Message, services: Services) -> None:
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
        "take_photo",
    )
    await message.answer(
        f"Команда сделать фото отправлена устройству. Номер: #{command_id}. "
        "Результат придёт автоматически."
    )
@router.callback_query(F.data == "command:take_photo")
async def take_photo_callback(callback: CallbackQuery, services: Services) -> None:
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
        "take_photo",
    )
    await callback.answer("Команда отправлена")
    if callback.message:
        await callback.message.answer(f"Фото запрошено. Команда #{command_id}.")
