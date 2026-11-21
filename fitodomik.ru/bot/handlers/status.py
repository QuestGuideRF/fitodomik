from __future__ import annotations
from datetime import datetime
from typing import Any
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import Message
from bot.handlers.common import linked_message, selected_message
from bot.keyboards.main_menu import main_menu
from bot.services.container import Services
from bot.utils.formatting import NO_DATA, number, plain, timestamp
from bot.utils.security import safe_json_object
router = Router(name=__name__)
def _age_minutes(value: object) -> int | None:
    if isinstance(value, datetime):
        return max(0, int((datetime.now() - value).total_seconds() // 60))
    return None
async def _context(message: Message, services: Services):
    user = await linked_message(message, services)
    if user is None:
        return None, None
    device = await selected_message(message, services, user)
    return user, device
async def _relay_definitions(
    services: Services, user_id: int, device_id: str
) -> list[dict[str, Any]] | tuple[dict[str, str], ...]:
    try:
        definitions = await services.sensors.relays(user_id, device_id)
    except Exception:
        definitions = []
    if definitions:
        return definitions
    return (
        {"name": "Лампа", "config_json": '{"state_field":"lamp_state"}'},
        {"name": "Шторы", "config_json": '{"state_field":"curtains_state"}'},
    )
def _relay_line(relay: dict[str, Any], sensor: dict[str, Any]) -> str | None:
    config = safe_json_object(relay.get("config_json"))
    state_field = str(config.get("state_field") or "")
    if not state_field.replace("_", "").isalnum() or len(state_field) > 64:
        return None
    label = plain(relay.get("name") or relay.get("relay_uid"), 120)
    value = sensor.get(state_field)
    if value is None:
        return f"{label}: {NO_DATA}"
    enabled = bool(int(value))
    state = plain(config.get("on_label") if enabled else config.get("off_label"), 40)
    return f"{label}: {state or ('включено' if enabled else 'выключено')}"
async def _relay_lines(
    services: Services, user_id: int, device_id: str, sensor: dict[str, Any]
) -> list[str]:
    definitions = await _relay_definitions(services, user_id, device_id)
    return [line for relay in definitions if (line := _relay_line(relay, sensor))]
async def _load_dynamic_sensors(
    services: Services, user_id: int, device_id: str
) -> list[dict[str, Any]]:
    try:
        return await services.sensors.dynamic(user_id, device_id)
    except Exception:
        return []
def _dynamic_sensor_lines(sensors: list[dict[str, Any]]) -> list[str]:
    lines: list[str] = []
    for sensor in sensors:
        value = sensor.get("value")
        if value is None or value == "":
            value = sensor.get("raw_value")
        lines.extend(
            (
                f"{plain(sensor.get('name') or sensor.get('sensor_uid'), 160)}: "
                f"{number(value, 2, str(sensor.get('unit') or ''))}",
                f"Обновлено: {timestamp(sensor.get('measured_at'))}",
            )
        )
    return lines
def _legacy_sensor_lines(sensor: dict[str, Any] | None) -> list[str]:
    if sensor is None:
        return [NO_DATA]
    return [
        f"Температура: {number(sensor.get('temperature'), 1, '°C')}",
        f"Влажность воздуха: {number(sensor.get('humidity'), 1, '%')}",
        f"Влажность почвы: {number(sensor.get('soil_moisture'), 1, '%')}",
        f"CO₂: {number(sensor.get('co2'), 0, 'ppm')}",
        f"Давление: {number(sensor.get('pressure'), 1, 'мм рт. ст.')}",
        f"Освещённость: {number(sensor.get('light_level'), 0)}",
    ]
@router.message(Command("status"))
@router.message(F.text.in_({"Статус", "Статус фермы"}))
async def status(message: Message, services: Services) -> None:
    user, device = await _context(message, services)
    if user is None or device is None:
        return
    sensor = await services.sensors.latest(int(user["id"]), str(device["device_id"]))
    photo = await services.photo.latest(int(user["id"]), str(device["device_id"]))
    active = await services.alerts.active_states(
        int(user["id"]), str(device["device_id"])
    )
    if sensor is None:
        await message.answer(
            f"Статус фермы\n\nУстройство: {plain(device['device_name'], 120)}\nДанных датчиков пока нет.",
            reply_markup=main_menu(),
        )
        return
    age = _age_minutes(sensor.get("created_at"))
    notification_settings = await services.telegram.get_settings(
        int(user["id"]), str(device["device_id"])
    )
    offline_after = max(
        5, int(notification_settings.get("offline_after_minutes") or 120)
    )
    online = age is not None and age < offline_after
    relay_lines = await _relay_lines(
        services, int(user["id"]), str(device["device_id"]), sensor
    )
    lines = [
        "Статус фермы",
        "",
        f"Устройство: {plain(device['device_name'], 120)}",
        f"Связь: {'online' if online else 'offline'}",
        f"Последнее обновление: {timestamp(sensor.get('created_at'))}",
        f"Температура: {number(sensor.get('temperature'), 1, '°C')}",
        f"Влажность воздуха: {number(sensor.get('humidity'), 1, '%')}",
        f"Влажность почвы: {number(sensor.get('soil_moisture'), 1, '%')}",
        f"CO₂: {number(sensor.get('co2'), 0, 'ppm')}",
        f"Давление: {number(sensor.get('pressure'), 1, 'мм рт. ст.')}",
        f"Освещённость: {number(sensor.get('light_level'), 0)}",
        *relay_lines,
        f"Активных тревог: {len(active)}",
        f"Последнее фото/анализ: {timestamp((photo or {}).get('created_at'))}",
    ]
    await message.answer("\n".join(lines), reply_markup=main_menu())
@router.message(Command("sensors"))
@router.message(F.text == "Датчики")
async def sensors(message: Message, services: Services) -> None:
    user, device = await _context(message, services)
    if user is None or device is None:
        return
    user_id, device_id = int(user["id"]), str(device["device_id"])
    lines = ["Датчики", f"Устройство: {plain(device['device_name'], 120)}"]
    dynamic = await _load_dynamic_sensors(services, user_id, device_id)
    if dynamic:
        lines.extend(_dynamic_sensor_lines(dynamic))
    else:
        latest = await services.sensors.latest(user_id, device_id)
        lines.extend(_legacy_sensor_lines(latest))
    await message.answer("\n".join(lines), reply_markup=main_menu())