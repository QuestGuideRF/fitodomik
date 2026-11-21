from __future__ import annotations
from datetime import date, datetime
from typing import Any
from bot.repositories.alerts import AlertsRepository
from bot.repositories.photos import PhotosRepository
from bot.repositories.sensor_data import SensorDataRepository
from bot.utils.formatting import number, timestamp
class ReportService:
    def __init__(
        self,
        sensors: SensorDataRepository,
        photos: PhotosRepository,
        alerts: AlertsRepository,
    ) -> None:
        self.sensors = sensors
        self.photos = photos
        self.alerts = alerts
    async def daily(
        self,
        user_id: int,
        device: dict[str, Any],
        offline_after_minutes: int = 120,
    ) -> str:
        device_id = str(device["device_id"])
        summary = await self.sensors.daily_summary(user_id, device_id, date.today())
        latest_sensor = await self.sensors.latest(user_id, device_id)
        photos = await self.photos.daily_counts(user_id, device_id)
        latest_analysis = await self.photos.latest_analysis(user_id, device_id)
        events = await self.alerts.daily_counts(user_id, device_id)
        last_seen = (latest_sensor or {}).get("created_at") or device.get(
            "last_seen_at"
        )
        online = (
            isinstance(last_seen, datetime)
            and (datetime.now() - last_seen).total_seconds()
            < max(5, offline_after_minutes) * 60
        )
        return "\n".join(
            (
                "Ежедневный отчёт ФитоДомика",
                "",
                f"Устройство: {device['device_name']}",
                f"Дата: {date.today().strftime('%d.%m.%Y')}",
                f"Связь: {'online' if online else 'offline'}",
                f"Записей датчиков: {int(summary.get('records_count') or 0)}",
                "Температура (сред/мин/макс): "
                + " / ".join(
                    number(summary.get(key), 1, "°C")
                    for key in ("avg_temp", "min_temp", "max_temp")
                ),
                f"Средняя влажность воздуха: {number(summary.get('avg_humidity'), 1, '%')}",
                f"Средняя влажность почвы: {number(summary.get('avg_soil'), 1, '%')}",
                f"Средний CO₂: {number(summary.get('avg_co2'), 0, 'ppm')}",
                f"Тревог: {int(events.get('alert_count') or 0)}",
                f"Поливов: {int(events.get('watering_count') or 0)}",
                f"Фото: {int(photos.get('photo_count') or 0)}",
                f"Анализов: {int(photos.get('analysis_count') or 0)}",
                f"Последний анализ: {timestamp((latest_analysis or {}).get('created_at'))}",
                f"Ошибок: {int(events.get('error_count') or 0)}",
            )
        )