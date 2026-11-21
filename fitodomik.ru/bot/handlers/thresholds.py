from __future__ import annotations
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup, Message
from bot.handlers.common import linked_message, selected_message
from bot.keyboards.control import command_keyboard
from bot.services.container import Services
from bot.states.forms import ThresholdForm
router = Router(name=__name__)
LABELS = {
    "temperature": "Температура",
    "humidity_air": "Влажность воздуха",
    "humidity_soil": "Влажность почвы",
    "co2": "CO₂",
}
RANGES = {
    "temperature": (-50.0, 100.0),
    "humidity_air": (0.0, 100.0),
    "humidity_soil": (0.0, 100.0),
    "co2": (0.0, 50000.0),
}
async def _save(
    message: Message,
    services: Services,
    user: dict,
    device: dict,
    parameter: str,
    minimum_text: str,
    maximum_text: str,
) -> bool:
    if parameter not in RANGES:
        await message.answer("Неизвестный параметр порога.")
        return False
    try:
        minimum, maximum = (
            float(minimum_text.replace(",", ".")),
            float(maximum_text.replace(",", ".")),
        )
    except ValueError:
        await message.answer("Минимум и максимум должны быть числами.")
        return False
    low, high = RANGES[parameter]
    if minimum < low or maximum > high or minimum >= maximum:
        await message.answer(
            f"Допустимый диапазон: {low:g}…{high:g}; минимум должен быть меньше максимума."
        )
        return False
    await services.sensors.set_threshold(int(user["id"]), str(device["device_id"]), parameter, minimum, maximum)
    await message.answer(
        f"Порог «{LABELS[parameter]}» обновлён для устройства: "
        f"{minimum:g}–{maximum:g}. Выбранное устройство: {device['device_name']}.",
        reply_markup=command_keyboard("refresh_thresholds", "Отправить устройству"),
    )
    return True
@router.message(Command("thresholds"))
@router.message(F.text == "Пороги")
async def thresholds(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    rows = await services.sensors.thresholds(int(user["id"]), str(device["device_id"]))
    text = ["Пороги", f"Устройство для синхронизации: {device['device_name']}"]
    buttons = []
    for row in rows:
        parameter = str(row["parameter_type"])
        text.append(
            f"{LABELS.get(parameter, parameter)}: {row['min_limit']} – {row['max_limit']}"
        )
        buttons.append(
            [
                InlineKeyboardButton(
                    text=LABELS.get(parameter, parameter),
                    callback_data=f"threshold:{parameter}",
                )
            ]
        )
    await message.answer(
        "\n".join(text), reply_markup=InlineKeyboardMarkup(inline_keyboard=buttons)
    )
@router.message(Command("set_threshold"))
async def set_threshold(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return
    device = await selected_message(message, services, user)
    if device is None:
        return
    parts = (message.text or "").split()
    if len(parts) != 4:
        await message.answer("Формат: /set_threshold temperature 18 30")
        return
    await _save(message, services, user, device, parts[1].lower(), parts[2], parts[3])
@router.callback_query(
    F.data.regexp(r"^threshold:(temperature|humidity_air|humidity_soil|co2)$")
)
async def threshold_choice(callback, services: Services, state: FSMContext) -> None:
    user = await services.device.linked_user(int(callback.from_user.id))
    if user is None:
        await callback.answer("Сначала привяжите Telegram.", show_alert=True)
        return
    parameter = str(callback.data).split(":", 1)[1]
    await state.set_state(ThresholdForm.limits)
    await state.update_data(parameter=parameter)
    await callback.answer()
    if callback.message:
        await callback.message.answer(
            f"Введите минимум и максимум для «{LABELS[parameter]}» через пробел, например: 18 30"
        )
@router.message(ThresholdForm.limits)
async def threshold_values(
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
    if len(parts) != 2:
        await message.answer("Введите два числа через пробел.")
        return
    data = await state.get_data()
    if await _save(
        message, services, user, device, str(data.get("parameter")), parts[0], parts[1]
    ):
        await state.clear()
