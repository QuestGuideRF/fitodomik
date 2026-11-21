from dataclasses import dataclass
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup, Message
from bot.handlers.common import linked_message, selected_message
from bot.keyboards.control import command_keyboard
from bot.services.container import Services
from bot.states.forms import ScheduleForm
router = Router(name=__name__)
@dataclass(frozen=True)
class ScheduleChange:
    schedule_type: str
    start_text: str
    end_text: str
    mode: str
async def _save(
    message: Message,
    services: Services,
    user: dict,
    device: dict,
    change: ScheduleChange,
) -> bool:
    schedule_type = change.schedule_type
    if schedule_type not in {"lighting", "curtains"}:
        await message.answer("Тип должен быть lighting или curtains.")
        return False
    try:
        start_hour, end_hour = int(change.start_text), int(change.end_text)
    except ValueError:
        await message.answer("Часы должны быть целыми числами.")
        return False
    if not 0 <= start_hour < end_hour <= 24:
        await message.answer("Требуется интервал 0–24, начальный час меньше конечного.")
        return False
    lowered = change.mode.lower()
    if lowered not in {"on", "off", "1", "0", "вкл", "выкл"}:
        await message.answer("Режим должен быть on или off.")
        return False
    enabled = lowered in {"on", "1", "вкл"}
    await services.sensors.set_schedule(
        int(user["id"]), str(device["device_id"]), schedule_type, start_hour, end_hour, enabled
    )
    await message.answer(
        f"Расписание обновлено: {schedule_type} {start_hour:02d}:00–{end_hour:02d}:00 "
        f"{'on' if enabled else 'off'}. Устройство: {device['device_name']}.",
        reply_markup=command_keyboard("refresh_schedule", "Отправить устройству"),
    )
    return True
@router.message(Command("schedule"))
@router.message(F.text == "Расписание")
async def schedule(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    rows = await services.sensors.schedule(int(user["id"]), str(device["device_id"]))
    lighting = [str(row["time"]) for row in rows if int(row["lighting_schedule"] or 0)]
    curtains = [str(row["time"]) for row in rows if int(row["curtains_schedule"] or 0)]
    text = (
        f"Расписание\nУстройство для синхронизации: {device['device_name']}\n\n"
        f"Лампа: {', '.join(lighting[:12]) or 'выключена'}\n"
        f"Шторы: {', '.join(curtains[:12]) or 'выключены'}"
    )
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="Изменить лампу", callback_data="schedule:lighting"
                )
            ],
            [
                InlineKeyboardButton(
                    text="Изменить шторы", callback_data="schedule:curtains"
                )
            ],
        ]
    )
    await message.answer(text, reply_markup=keyboard)
@router.message(Command("set_schedule"))
async def set_schedule(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    parts = (message.text or "").split()
    if len(parts) != 5:
        await message.answer("Формат: /set_schedule lighting 8 20 on")
        return
    await _save(
        message,
        services,
        user,
        device,
        ScheduleChange(parts[1], parts[2], parts[3], parts[4]),
    )
@router.callback_query(F.data.regexp(r"^schedule:(lighting|curtains)$"))
async def schedule_choice(callback, services: Services, state: FSMContext) -> None:
    user = await services.device.linked_user(int(callback.from_user.id))
    if user is None:
        await callback.answer("Сначала привяжите Telegram.", show_alert=True)
        return
    schedule_type = str(callback.data).split(":", 1)[1]
    await state.set_state(ScheduleForm.interval)
    await state.update_data(schedule_type=schedule_type)
    await callback.answer()
    if callback.message:
        await callback.message.answer(
            "Введите начальный час, конечный час и on/off, например: 8 20 on"
        )
@router.message(ScheduleForm.interval)
async def schedule_values(
    message: Message, services: Services, state: FSMContext
) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        await state.clear()
        return
    device = await selected_message(message, services, user)
    if device is None:
        await state.clear()
        return
    parts = (message.text or "").split()
    if len(parts) != 3:
        await message.answer("Введите три значения, например: 8 20 on")
        return
    data = await state.get_data()
    if await _save(
        message,
        services,
        user,
        device,
        ScheduleChange(str(data.get("schedule_type")), parts[0], parts[1], parts[2]),
    ):
        await state.clear()
