from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message
from bot.handlers.common import linked_callback, linked_message
from bot.keyboards.devices import devices_keyboard
from bot.keyboards.main_menu import main_menu
from bot.services.container import Services
from bot.states.forms import RenameForm
router = Router(name=__name__)
async def _show_devices(
    message: Message, services: Services, rename: bool = False
) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    devices = await services.devices.list_active(int(user["id"]))
    if not devices:
        await message.answer("Активных устройств пока нет.")
        return
    selected = await services.device.selected(user, int(message.from_user.id))
    await message.answer(
        "Выберите устройство для переименования:"
        if rename
        else "Выберите активное устройство:",
        reply_markup=devices_keyboard(
            devices,
            str((selected or {}).get("device_id") or ""),
            "rename" if rename else "select",
        ),
    )
@router.message(Command("devices"))
@router.message(F.text == "Устройства")
async def devices(message: Message, services: Services) -> None:
    await _show_devices(message, services)
@router.message(Command("rename"))
async def rename_start(message: Message, services: Services) -> None:
    await _show_devices(message, services, rename=True)
@router.callback_query(F.data.regexp(r"^device:(select|rename):\d{1,20}$"))
async def device_callback(
    callback: CallbackQuery, services: Services, state: FSMContext
) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    _, mode, row_id_text = str(callback.data).split(":", 2)
    device = await services.devices.owned_by_row_id(int(user["id"]), int(row_id_text))
    if device is None:
        await callback.answer("Устройство недоступно.", show_alert=True)
        return
    if mode == "select":
        await services.device.choose(user, int(callback.from_user.id), int(row_id_text))
        await callback.answer("Устройство выбрано")
        if isinstance(callback.message, Message):
            await callback.message.edit_text(
                f"Активное устройство: {device['device_name']}"
            )
        return
    await state.set_state(RenameForm.name)
    await state.update_data(device_id=str(device["device_id"]))
    await callback.answer()
    if callback.message:
        await callback.message.answer(
            f"Введите новое название для «{device['device_name']}» (до 120 символов)."
        )
@router.message(RenameForm.name)
async def rename_value(message: Message, services: Services, state: FSMContext) -> None:
    user = await linked_message(message, services)
    if user is None:
        await state.clear()
        return
    data = await state.get_data()
    try:
        await services.device.rename(
            user, str(data.get("device_id") or ""), message.text or ""
        )
    except (ValueError, PermissionError) as error:
        await message.answer(str(error))
        return
    await state.clear()
    await message.answer("Название устройства обновлено.", reply_markup=main_menu())