from __future__ import annotations
import logging
from dataclasses import dataclass
from datetime import datetime, time
from typing import Any
from aiogram import Bot
from bot.repositories.alerts import AlertsRepository
from bot.repositories.devices import DevicesRepository
from bot.repositories.sensor_data import SensorDataRepository
from bot.repositories.telegram import TelegramRepository
from bot.repositories.users import UsersRepository
from bot.services.photo_service import PhotoService
from bot.utils.formatting import decimal_float, plain
LOGGER = logging.getLogger(__name__)
CONFIRM_SECONDS = 300
PARAMETERS = {
    "temperature": ("temperature", "Температура", "°C", "notify_temperature"),
    "humidity_air": ("humidity", "Влажность воздуха", "%", None),
    "humidity_soil": ("soil_moisture", "Влажность почвы", "%", "notify_soil_moisture"),
    "co2": ("co2", "CO₂", "ppm", None),
}
FAULT_RULES = (
    (("camera", "камер"), "camera_alert_enabled", "Камера недоступна"),
    (("arduino",), "arduino_alert_enabled", "Arduino недоступна"),
    (("analysis", "анализ"), "analysis_alert_enabled", "Ошибка анализа"),
)
@dataclass(frozen=True)
class AlertCondition:
    key: str
    active: bool
    value: Any
    alert_message: str
    recovery_message: str
    confirmation_seconds: int = 0
def _as_datetime(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        return value
    if isinstance(value, str) and value:
        try:
            return datetime.fromisoformat(value)
        except ValueError:
            return None
    return None
def _clock(value: Any) -> time | None:
    try:
        return datetime.strptime(str(value), "%H:%M").time()
    except (TypeError, ValueError):
        return None
def quiet_hours(settings: dict[str, Any], now: datetime | None = None) -> bool:
    start = _clock(settings.get("quiet_hours_start"))
    end = _clock(settings.get("quiet_hours_end"))
    if start is None or end is None or start == end:
        return False
    current = (now or datetime.now()).time()
    return start <= current < end if start < end else current >= start or current < end
def _check_interval(site_settings: dict[str, Any]) -> int:
    return max(
        1, min(1440, int(site_settings.get("alert_check_interval_minutes") or 1))
    )
def _fault_settings(text: str, settings: dict[str, Any]) -> tuple[bool, str]:
    for markers, setting_name, label in FAULT_RULES:
        if any(marker in text for marker in markers):
            return bool(int(settings.get(setting_name) or 0)), label
    return bool(int(settings.get("system_alert_enabled") or 0)), "Ошибка устройства"
class AlertService:
    def __init__(
        self,
        users: UsersRepository,
        devices: DevicesRepository,
        telegram: TelegramRepository,
        sensors: SensorDataRepository,
        alerts: AlertsRepository,
        photos: PhotoService,
    ) -> None:
        self.users = users
        self.devices = devices
        self.telegram = telegram
        self.sensors = sensors
        self.alerts = alerts
        self.photos = photos
    async def process_all(self, bot: Bot, report_service: Any) -> None:
        for link in await self.users.active_links():
            try:
                await self._process_link(bot, link, report_service)
            except Exception:
                LOGGER.exception(
                    "Periodic notifications failed for Telegram user %s",
                    int(link["telegram_user_id"]),
                )
    async def _process_link(
        self, bot: Bot, link: dict[str, Any], report_service: Any
    ) -> None:
        user_id = int(link["id"])
        chat_id = str(link["telegram_chat_id"])
        for device in await self.devices.list_active(user_id):
            await self._process_device(bot, user_id, chat_id, device, report_service)
    async def _process_device(
        self,
        bot: Bot,
        user_id: int,
        chat_id: str,
        device: dict[str, Any],
        report_service: Any,
    ) -> None:
        device_id = str(device["device_id"])
        settings = await self.telegram.get_settings(user_id, device_id)
        site_settings = await self.telegram.site_notification_settings(
            user_id, device_id
        )
        if site_settings and not int(site_settings.get("telegram_enabled") or 0):
            return
        context = {
            "user_id": user_id,
            "chat_id": chat_id,
            "device": device,
            "settings": settings,
            "site_settings": site_settings or {},
            "sensor": await self.sensors.latest(user_id, device_id),
            "photo_sent": False,
        }
        await self._daily_report(bot, context, report_service)
        await self._device_fault_reports(bot, context)
        if not await self.alerts.check_due(
            user_id, device_id, _check_interval(site_settings or {})
        ):
            return
        if await self._run_alert_checks(bot, context):
            await self.alerts.mark_checked(user_id, device_id)
    async def _run_alert_checks(self, bot: Bot, context: dict[str, Any]) -> bool:
        checks_ok = True
        for operation in (self._offline, self._thresholds, self._fault_events):
            try:
                await operation(bot, context)
            except Exception:
                checks_ok = False
                LOGGER.exception(
                    "Alert check failed user_id=%s device_id=%s",
                    int(context["user_id"]),
                    str(context["device"]["device_id"]),
                )
        return checks_ok
    async def _device_fault_reports(self, bot: Bot, context: dict[str, Any]) -> None:
        user_id = int(context["user_id"])
        device_id = str(context["device"]["device_id"])
        for event in await self.alerts.pending_device_faults(user_id, device_id):
            if event["fault_code"] != "PUMP_TIMEOUT":
                continue
            name = plain(context["device"].get("device_name"), 120)
            if event.get("notified_at") is None:
                await bot.send_message(
                    context["chat_id"],
                    f"⚠ Насос аварийно выключен на устройстве «{name}»: превышен лимит работы. "
                    "Проверьте датчик и причину аварии. Сброс: /pump_fault_reset",
                )
                await self.alerts.mark_device_fault_notified(user_id, device_id, int(event["id"]))
            if event.get("photo_file") and event.get("photo_notified_at") is None:
                photo = self.photos.local_photo(event["photo_file"])
                if photo:
                    await bot.send_photo(context["chat_id"], photo, caption="Фото при аварии насоса")
                    await self.alerts.mark_device_fault_photo_notified(user_id, device_id, int(event["id"]))
    async def _condition(
        self,
        bot: Bot,
        context: dict[str, Any],
        condition: AlertCondition,
    ) -> bool:
        user_id = int(context["user_id"])
        device_id = str(context["device"]["device_id"])
        settings = context["settings"]
        state = await self.alerts.state(user_id, device_id, condition.key)
        now = datetime.now()
        if condition.active:
            active_since = _as_datetime(state.get("active_since"))
            if active_since is None:
                await self.alerts.activate(
                    user_id, device_id, condition.key, str(condition.value)
                )
                active_since = now
            elapsed = (now - active_since).total_seconds()
            last_sent = _as_datetime(state.get("last_sent_at"))
            cooldown_minutes = int(
                context.get("site_settings", {}).get("alert_repeat_cooldown_minutes")
                or settings.get("alert_cooldown_minutes")
                or 180
            )
            cooldown = max(1, min(10080, cooldown_minutes)) * 60
            can_send = (
                last_sent is None or (now - last_sent).total_seconds() >= cooldown
            )
            if (
                elapsed >= condition.confirmation_seconds
                and can_send
                and not quiet_hours(settings, now)
            ):
                await bot.send_message(context["chat_id"], condition.alert_message)
                await self.alerts.mark_sent(
                    user_id, device_id, condition.key, str(condition.value)
                )
                return True
            return False
        if not state.get("active_since"):
            return False
        if state.get("last_sent_at") and int(settings.get("system_alert_enabled") or 1):
            if quiet_hours(settings, now):
                return False
            await bot.send_message(context["chat_id"], condition.recovery_message)
        await self.alerts.reset(user_id, device_id, condition.key, str(condition.value))
        return False
    async def _maybe_send_photo(self, bot: Bot, context: dict[str, Any]) -> None:
        settings = context["settings"]
        site = context["site_settings"]
        enabled = int(settings.get("photo_alert_enabled") or 0)
        if site:
            enabled = enabled or int(site.get("send_photos_telegram") or 0)
        if not enabled or context["photo_sent"]:
            return
        row = await self.photos.latest(
            int(context["user_id"]), str(context["device"]["device_id"])
        )
        photo_file = self.photos.local_photo((row or {}).get("photo"))
        if photo_file:
            await bot.send_photo(
                context["chat_id"],
                photo=photo_file,
                caption=f"Последнее фото: {plain(context['device'].get('device_name'), 120)}",
            )
            context["photo_sent"] = True
    async def _offline(self, bot: Bot, context: dict[str, Any]) -> None:
        settings = context["settings"]
        if not int(settings.get("offline_alert_enabled") or 0):
            return
        sensor_at = _as_datetime((context.get("sensor") or {}).get("created_at"))
        device_at = _as_datetime(context["device"].get("last_seen_at"))
        last_seen = sensor_at or device_at or datetime.fromtimestamp(0)
        minutes = max(0, int((datetime.now() - last_seen).total_seconds() // 60))
        limit = int(settings.get("offline_after_minutes") or 120)
        name = plain(context["device"].get("device_name"), 120)
        sent = await self._condition(
            bot,
            context,
            AlertCondition(
                key="offline",
                active=minutes >= limit,
                value=minutes,
                alert_message=f"⚠ ФитоДомик «{name}» не выходит на связь уже {minutes} минут.",
                recovery_message=f"ФитоДомик «{name}» снова в сети.",
            ),
        )
        if sent:
            await self._maybe_send_photo(bot, context)
    async def _thresholds(self, bot: Bot, context: dict[str, Any]) -> None:
        settings = context["settings"]
        sensor = context.get("sensor")
        if not sensor or not int(settings.get("alerts_enabled") or 0):
            return
        for threshold in await self.sensors.thresholds(int(context["user_id"]), str(context["device"]["device_id"])):
            await self._process_threshold(bot, context, sensor, threshold)
    async def _process_threshold(
        self,
        bot: Bot,
        context: dict[str, Any],
        sensor: dict[str, Any],
        threshold: dict[str, Any],
    ) -> None:
        parameter = str(threshold["parameter_type"])
        mapping = PARAMETERS.get(parameter)
        if mapping is None:
            return
        field, label, unit, site_flag = mapping
        site = context["site_settings"]
        if site_flag and site and not int(site.get(site_flag) or 0):
            return
        value = decimal_float(sensor.get(field))
        if value is None:
            return
        name = plain(context["device"].get("device_name"), 120)
        minimum = float(threshold["min_limit"])
        maximum = float(threshold["max_limit"])
        for direction, active, boundary in (
            ("low", value < minimum, minimum),
            ("high", value > maximum, maximum),
        ):
            word = "ниже" if direction == "low" else "выше"
            sent = await self._condition(
                bot,
                context,
                AlertCondition(
                    key=f"threshold_{parameter}_{direction}",
                    active=active,
                    value=value,
                    alert_message=f"⚠ {label} {word} нормы\n\nУстройство: {name}\n"
                    f"Текущее значение: {value:g} {unit}\nГраница: {boundary:g} {unit}",
                    recovery_message=f"Показатель «{label}» устройства «{name}» вернулся в норму.",
                    confirmation_seconds=CONFIRM_SECONDS,
                ),
            )
            if sent:
                await self._maybe_send_photo(bot, context)
    async def _fault_events(self, bot: Bot, context: dict[str, Any]) -> None:
        for event in await self.alerts.recent_fault_events(
            int(context["user_id"]), str(context["device"]["device_id"])
        ):
            await self._process_fault_event(bot, context, event)
    async def _process_fault_event(
        self, bot: Bot, context: dict[str, Any], event: dict[str, Any]
    ) -> None:
        text = plain(event.get("event_description"), 800)
        searchable = (str(event.get("event_type") or "") + " " + text).lower()
        enabled, label = _fault_settings(searchable, context["settings"])
        if not enabled:
            return
        event_id = int(event["id"])
        sent = await self._condition(
            bot,
            context,
            AlertCondition(
                key=f"event_{event_id}",
                active=True,
                value=event_id,
                alert_message=f"⚠ {label}\n\n{text}",
                recovery_message="",
            ),
        )
        if sent:
            await self._maybe_send_photo(bot, context)
    async def _daily_report(
        self, bot: Bot, context: dict[str, Any], report_service: Any
    ) -> None:
        settings = context["settings"]
        site = context["site_settings"]
        enabled = int(settings.get("daily_report_enabled") or 0)
        if site:
            enabled = enabled and int(site.get("send_daily_report") or 0)
        report_time = str(
            (site or {}).get("daily_report_time")
            or settings.get("report_time")
            or "20:00"
        )
        expected = _clock(report_time)
        now = datetime.now()
        if not enabled or expected is None:
            return
        scheduled = now.replace(
            hour=expected.hour, minute=expected.minute, second=0, microsecond=0
        )
        if not (0 <= (now - scheduled).total_seconds() < 600):
            return
        if await self.telegram.claim_daily_report(int(settings["id"])):
            try:
                text = await report_service.daily(
                    int(context["user_id"]),
                    context["device"],
                    int(settings.get("offline_after_minutes") or 120),
                )
                await bot.send_message(context["chat_id"], text)
            except Exception:
                await self.telegram.release_daily_report(int(settings["id"]))
                raise
            await self._maybe_send_photo(bot, context)
