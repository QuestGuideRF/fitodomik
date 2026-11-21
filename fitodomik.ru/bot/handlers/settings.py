from __future__ import annotations
import re
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import (
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)
from bot.handlers.common import linked_callback, linked_message, selected_message
from bot.repositories.telegram import SETTING_COLUMNS
from bot.services.container import Services
from bot.utils.formatting import boolean
router = Router(name=__name__)
TIME_RE = re.compile(r"^(?:[01]\d|2[0-3]):[0-5]\d$")
async def _settings(message: Message, services: Services):
    user = await linked_message(message, services)
    if user is None or message.from_user is None:
        return None, None, None, None
    device = await selected_message(message, services, user)
    if device is None:
        return user, None, None, None
    settings = await services.telegram.get_settings(
        int(user["id"]), str(device["device_id"])
    )
    site_settings = await services.telegram.site_notification_settings(
        int(user["id"]), str(device["device_id"])
    )
    return user, device, settings, site_settings or {}
def _effective(values: dict, site: dict) -> dict:
    result = dict(values)
    if not site:
        return result
    for own, existing in (
        ("alerts_enabled", "telegram_enabled"),
        ("daily_report_enabled", "send_daily_report"),
        ("camera_alert_enabled", "notify_camera"),
    ):
        result[own] = int(values.get(own) or 0) and int(site.get(existing) or 0)
    result["photo_alert_enabled"] = int(values.get("photo_alert_enabled") or 0) or int(
        site.get("send_photos_telegram") or 0
    )
    result["report_time"] = site.get("daily_report_time") or values.get("report_time")
    result["alert_cooldown_minutes"] = site.get(
        "alert_repeat_cooldown_minutes"
    ) or values.get("alert_cooldown_minutes")
    return result
@router.message(Command("settings"))
@router.message(F.text.in_({"Настройки", "Уведомления"}))
async def settings(message: Message, services: Services) -> None:
    user, device, values, site_values = await _settings(message, services)
    if user is None or device is None or values is None:
        return
    values = _effective(values, site_values or {})
    labels = {
        "alerts": "Тревоги датчиков",
        "report": "Ежедневный отчёт",
        "offline": "Offline",
        "arduino": "Arduino",
        "camera": "Камера",
        "analysis": "Анализ",
        "photo": "Фото",
        "system": "Системные",
    }
    lines = ["Уведомления", f"Устройство: {device['device_name']}"]
    buttons = []
    for key, column in SETTING_COLUMNS.items():
        enabled = bool(int(values.get(column) or 0))
        lines.append(f"{labels[key]}: {boolean(enabled)}")
        buttons.append(
            [
                InlineKeyboardButton(
                    text=f"{labels[key]}: {'выкл' if enabled else 'вкл'}",
                    callback_data=f"setting:{key}:{0 if enabled else 1}",
                )
            ]
        )
    lines.extend(
        (
            f"Время отчёта: {values['report_time']}",
            f"Offline-порог: {values['offline_after_minutes']} мин",
            f"Cooldown: {values['alert_cooldown_minutes']} мин",
            f"Проверка тревог: {(site_values or {}).get('alert_check_interval_minutes', 1)} мин",
            "",
            "Команды: /report_time 20:00, /offline_after 120, /cooldown 180",
        )
    )
    await message.answer(
        "\n".join(lines), reply_markup=InlineKeyboardMarkup(inline_keyboard=buttons)
    )
@router.callback_query(
    F.data.regexp(
        r"^setting:(alerts|report|offline|arduino|camera|analysis|photo|system):[01]$"
    )
)
async def setting_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    device = await services.device.selected(user, int(callback.from_user.id))
    if device is None:
        await callback.answer("Нет активного устройства.", show_alert=True)
        return
    _, key, value = str(callback.data).split(":")
    current = await services.telegram.get_settings(
        int(user["id"]), str(device["device_id"])
    )
    enabled = value == "1"
    await services.telegram.set_toggle(int(current["id"]), key, enabled)
    await services.telegram.set_site_toggle(
        int(user["id"]), str(device["device_id"]), key, enabled
    )
    await callback.answer("Настройка сохранена")
    if isinstance(callback.message, Message):
        await callback.message.edit_reply_markup(reply_markup=None)
        await callback.message.answer(
            "Настройка обновлена. Откройте «Настройки» для проверки."
        )
@router.message(Command("notify"))
async def notify(message: Message, services: Services) -> None:
    user, device, values, _ = await _settings(message, services)
    if user is None or device is None or values is None:
        return
    parts = (message.text or "").split()
    if (
        len(parts) != 3
        or parts[1] not in SETTING_COLUMNS
        or parts[2].lower() not in {"on", "off", "1", "0", "вкл", "выкл"}
    ):
        await message.answer("Формат: /notify alerts off")
        return
    enabled = parts[2].lower() in {"on", "1", "вкл"}
    await services.telegram.set_toggle(int(values["id"]), parts[1], enabled)
    await services.telegram.set_site_toggle(
        int(user["id"]), str(device["device_id"]), parts[1], enabled
    )
    await message.answer(
        f"Настройка {parts[1]}: {'включена' if enabled else 'выключена'}."
    )
@router.message(Command("report_time"))
async def report_time(message: Message, services: Services) -> None:
    user, device, values, _ = await _settings(message, services)
    if user is None or device is None or values is None:
        return
    parts = (message.text or "").split()
    if len(parts) != 2 or not TIME_RE.fullmatch(parts[1]):
        await message.answer("Формат: /report_time 20:00")
        return
    await services.telegram.set_report_time(int(values["id"]), parts[1])
    await services.telegram.set_site_report_time(
        int(user["id"]), str(device["device_id"]), parts[1]
    )
    await message.answer(f"Время ежедневного отчёта: {parts[1]}.")
async def _numeric(
    message: Message, services: Services, column: str, minimum: int
) -> None:
    user, device, values, _ = await _settings(message, services)
    if user is None or device is None or values is None:
        return
    parts = (message.text or "").split()
    try:
        value = int(parts[1]) if len(parts) == 2 else 0
    except ValueError:
        value = 0
    if not minimum <= value <= 10080:
        await message.answer(f"Значение должно быть от {minimum} до 10080 минут.")
        return
    await services.telegram.set_numeric(int(values["id"]), column, value)
    if column == "alert_cooldown_minutes":
        await services.telegram.set_site_cooldown(
            int(user["id"]), str(device["device_id"]), value
        )
    await message.answer(f"Настройка обновлена: {value} минут.")
@router.message(Command("offline_after"))
async def offline_after(message: Message, services: Services) -> None:
    await _numeric(message, services, "offline_after_minutes", 5)
@router.message(Command("cooldown"))
async def cooldown(message: Message, services: Services) -> None:
    await _numeric(message, services, "alert_cooldown_minutes", 1)