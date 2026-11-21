from __future__ import annotations
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import CallbackQuery, Message
from bot.handlers.common import linked_callback, linked_message, selected_message
from bot.keyboards.control import confirmation_keyboard, control_keyboard
from bot.services.container import Services
router = Router(name=__name__)
SIMPLE_COMMANDS = {
    "ping_device": "ping",
    "reconnect_arduino": "reconnect_arduino",
    "refresh_thresholds": "refresh_thresholds",
    "refresh_schedule": "refresh_schedule",
    "upload_thresholds_to_arduino": "upload_thresholds_to_arduino",
}
NO_ACTIVE_DEVICE_MESSAGE = "Нет активного устройства."
async def _selected_for_message(message: Message, services: Services):
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return None, None
    return user, await selected_message(message, services, user)
async def _confirm_or_enqueue(
    message: Message,
    services: Services,
    user: dict,
    device: dict,
    command: str,
    payload: dict,
    confirmation_text: str | None,
) -> None:
    telegram_id = int(message.from_user.id) if message.from_user else 0
    if confirmation_text:
        token = await services.command.request_confirmation(
            int(user["id"]), str(device["device_id"]), telegram_id, command, payload
        )
        await message.answer(
            confirmation_text, reply_markup=confirmation_keyboard(token)
        )
        return
    command_id = await services.command.enqueue(
        int(user["id"]), str(device["device_id"]), telegram_id, command, payload
    )
    await message.answer(f"Команда отправлена устройству. Номер: #{command_id}.")
@router.message(Command("control"))
@router.message(F.text == "Управление")
async def control(message: Message, services: Services) -> None:
    user, device = await _selected_for_message(message, services)
    if user is None or device is None:
        return
    await message.answer(
        f"Управление устройством «{device['device_name']}». "
        "Все действия выполняются через очередь команд.",
        reply_markup=control_keyboard(),
    )
@router.message(Command(*SIMPLE_COMMANDS.keys()))
async def simple_command(message: Message, services: Services) -> None:
    user, device = await _selected_for_message(message, services)
    if user is None or device is None or message.from_user is None:
        return
    command_name = (
        (message.text or "").split(maxsplit=1)[0].lstrip("/").split("@", 1)[0]
    )
    queue_name = SIMPLE_COMMANDS[command_name]
    command_id = await services.command.enqueue(
        int(user["id"]), str(device["device_id"]), int(message.from_user.id), queue_name
    )
    await message.answer(f"Команда «{queue_name}» поставлена в очередь: #{command_id}.")
@router.message(Command("device_test"))
async def device_test(message: Message, services: Services) -> None:
    user, device = await _selected_for_message(message, services)
    if user is None or device is None:
        return
    parts = (message.text or "").split()
    target = parts[1].lower() if len(parts) > 1 else "all"
    if target not in {"all", "lamp", "curtains", "pump", "fan"}:
        await message.answer("Формат: /device_test lamp|fan|pump|curtains|all")
        return
    confirmation = (
        "Подтвердите краткий тест насоса. Убедитесь, что полив безопасен."
        if target == "pump"
        else None
    )
    await _confirm_or_enqueue(
        message, services, user, device, "device_test", {"device": target}, confirmation
    )
@router.message(Command("pump_fault_reset"))
async def pump_fault_reset(message: Message, services: Services) -> None:
    user, device = await _selected_for_message(message, services)
    if user is None or device is None:
        return
    await _confirm_or_enqueue(
        message, services, user, device, "pump_fault_reset", {},
        "Подтвердите сброс блокировки насоса после проверки датчика и причины аварии. Насос останется выключенным.",
    )
@router.message(Command("relay_on", "relay_off", "relay_pulse"))
async def relay_command(message: Message, services: Services) -> None:
    user, device = await _selected_for_message(message, services)
    if user is None or device is None:
        return
    parts = (message.text or "").split()
    command_name = parts[0].lstrip("/").split("@", 1)[0]
    expected = 3 if command_name == "relay_pulse" else 2
    if len(parts) < expected:
        await message.answer("Формат: /relay_on 1, /relay_off 1 или /relay_pulse 3 1")
        return
    try:
        payload: dict[str, int | float] = {"relay": int(parts[1])}
        if command_name == "relay_pulse":
            payload["seconds"] = float(parts[2])
    except ValueError:
        await message.answer("Номер реле и длительность должны быть числами.")
        return
    await _confirm_or_enqueue(
        message,
        services,
        user,
        device,
        command_name,
        payload,
        f"Подтвердите команду {command_name} для реле {payload['relay']}.",
    )
@router.callback_query(F.data.regexp(r"^control:(on|off|pulse):[1-4]$"))
async def control_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer(NO_ACTIVE_DEVICE_MESSAGE, show_alert=True)
        return
    _, mode, relay_text = str(callback.data).split(":")
    command = {
        "on": "relay_on",
        "off": "relay_off",
        "pulse": "relay_pulse",
    }[mode]
    payload = {"relay": int(relay_text)}
    if mode == "pulse":
        payload["seconds"] = 3
    token = await services.command.request_confirmation(
        int(user["id"]),
        str(device["device_id"]),
        int(callback.from_user.id),
        command,
        payload,
    )
    await callback.answer()
    if callback.message:
        await callback.message.answer(
            f"Подтвердите переключение реле {relay_text}.",
            reply_markup=confirmation_keyboard(token),
        )
@router.callback_query(F.data.regexp(r"^test:(lamp|fan|pump|curtains)$"))
async def test_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer(NO_ACTIVE_DEVICE_MESSAGE, show_alert=True)
        return
    target = str(callback.data).split(":", 1)[1]
    if target == "pump":
        token = await services.command.request_confirmation(
            int(user["id"]),
            str(device["device_id"]),
            int(callback.from_user.id),
            "device_test",
            {"device": "pump"},
        )
        await callback.answer()
        if callback.message:
            await callback.message.answer(
                "Подтвердите тест насоса.", reply_markup=confirmation_keyboard(token)
            )
        return
    command_id = await services.command.enqueue(
        int(user["id"]),
        str(device["device_id"]),
        int(callback.from_user.id),
        "device_test",
        {"device": target},
    )
    await callback.answer(f"Команда #{command_id} отправлена")
@router.callback_query(F.data == "control:pump_reset")
async def pump_reset_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer(NO_ACTIVE_DEVICE_MESSAGE, show_alert=True)
        return
    token = await services.command.request_confirmation(
        int(user["id"]), str(device["device_id"]), int(callback.from_user.id),
        "pump_fault_reset", {},
    )
    await callback.answer()
    if callback.message:
        await callback.message.answer(
            "Проверьте причину аварии и датчик перед сбросом. Насос останется выключенным.",
            reply_markup=confirmation_keyboard(token),
        )
@router.callback_query(
    F.data.regexp(
        r"^command:(ping|refresh_thresholds|refresh_schedule|upload_thresholds_to_arduino|reconnect_arduino)$"
    )
)
async def queued_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer(NO_ACTIVE_DEVICE_MESSAGE, show_alert=True)
        return
    command = str(callback.data).split(":", 1)[1]
    command_id = await services.command.enqueue(
        int(user["id"]), str(device["device_id"]), int(callback.from_user.id), command
    )
    await callback.answer(f"Команда #{command_id} отправлена")
@router.callback_query(F.data.regexp(r"^(confirm|cancel):[a-f0-9]{32}$"))
async def confirmation_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    mode, token = str(callback.data).split(":", 1)
    ok, text, command_id = await services.command.consume_confirmation(
        token, int(callback.from_user.id), mode == "cancel"
    )
    await callback.answer(text, show_alert=not ok)
    if isinstance(callback.message, Message):
        suffix = f" Номер команды: #{command_id}." if command_id else ""
        await callback.message.edit_text(text + suffix)
