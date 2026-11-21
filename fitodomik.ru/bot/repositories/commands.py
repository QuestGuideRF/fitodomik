from __future__ import annotations
import json
from datetime import datetime, timedelta
from typing import Any
import aiomysql
from bot.database import Database
ALLOWED_COMMANDS = {
    "take_photo",
    "analyze_plant",
    "refresh_thresholds",
    "refresh_schedule",
    "upload_thresholds_to_arduino",
    "reconnect_arduino",
    "relay_on",
    "relay_off",
    "relay_pulse",
    "pump_fault_reset",
    "device_test",
    "sync_device_config",
    "request_device_config",
    "apply_device_config",
    "ping",
}
COMMAND_LABELS = {
    "take_photo": "Сделать фото",
    "analyze_plant": "Анализ растения",
    "refresh_thresholds": "Обновить пороги",
    "refresh_schedule": "Обновить расписание",
    "upload_thresholds_to_arduino": "Загрузить настройки в Arduino",
    "reconnect_arduino": "Переподключить Arduino",
    "relay_on": "Включить реле",
    "relay_off": "Выключить реле",
    "relay_pulse": "Импульс реле",
    "pump_fault_reset": "Сброс блокировки насоса",
    "device_test": "Тест устройства",
    "sync_device_config": "Синхронизировать конфигурацию",
    "request_device_config": "Запросить конфигурацию",
    "apply_device_config": "Применить конфигурацию",
    "ping": "Проверка связи",
}
def normalize_payload(command: str, payload: dict[str, Any] | None) -> dict[str, Any]:
    if command not in ALLOWED_COMMANDS:
        raise ValueError("Неизвестная команда устройства")
    normalized = dict(payload or {})
    if command in {"relay_on", "relay_off", "relay_pulse"}:
        relay = int(normalized.get("relay", normalized.get("relay_id", 0)))
        if relay < 1 or relay > 4:
            raise ValueError("Реле должно быть в диапазоне 1-4")
        normalized["relay"] = relay
        if command == "relay_pulse":
            seconds = float(normalized.get("seconds", 3))
            normalized["seconds"] = min(30.0, max(0.2, seconds))
    if command == "device_test":
        device = str(normalized.get("device", "all")).strip().lower()
        if device not in {"all", "lamp", "curtains", "pump", "fan"}:
            raise ValueError("Неизвестное устройство для теста")
        normalized["device"] = device
        seconds = float(normalized.get("seconds", 1 if device == "pump" else 3))
        normalized["seconds"] = min(10.0, max(0.2, seconds))
    return normalized
class CommandsRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def create(
        self,
        user_id: int,
        device_id: str,
        telegram_user_id: int,
        command: str,
        payload: dict[str, Any] | None = None,
        *,
        connection: aiomysql.Connection | None = None,
    ) -> int:
        normalized = normalize_payload(command, payload)
        owner = await self.database.fetch_one(
            "SELECT device_id FROM user_devices "
            "WHERE user_id=%s AND device_id=%s AND status='active' LIMIT 1",
            (user_id, device_id),
            connection=connection,
        )
        if owner is None:
            raise PermissionError(
                "Устройство не найдено или не принадлежит пользователю"
            )
        expires_at = datetime.now() + timedelta(minutes=30)
        command_id = await self.database.insert(
            """
            INSERT INTO device_commands (
                user_id,device_id,command_type,command_payload,status,source,
                requested_by_user_id,requested_by_telegram_id,created_by_user_id,
                telegram_user_id,available_at,expires_at
            ) VALUES (%s,%s,%s,%s,'pending','telegram',%s,%s,%s,%s,NOW(),%s)
            """,
            (
                user_id,
                device_id,
                command,
                json.dumps(normalized, ensure_ascii=False, separators=(",", ":")),
                user_id,
                telegram_user_id,
                user_id,
                telegram_user_id,
                expires_at,
            ),
            connection=connection,
        )
        await self.database.execute(
            """
            INSERT INTO event_log (user_id,device_id,event_type,event_description,created_at)
            VALUES (%s,%s,'device_command',%s,NOW())
            """,
            (user_id, device_id, "Создана команда: " + COMMAND_LABELS[command]),
            connection=connection,
        )
        return command_id
    async def completed_for_notification(self) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT c.*,d.device_name,l.telegram_chat_id
            FROM device_commands c
            INNER JOIN telegram_links l ON l.user_id=c.user_id
              AND l.telegram_user_id=c.requested_by_telegram_id AND l.status='active'
              AND CONVERT(l.telegram_chat_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  =CONVERT(CAST(l.telegram_user_id AS CHAR) USING utf8mb4)
                    COLLATE utf8mb4_unicode_ci
              AND l.id=(
                  SELECT l2.id FROM telegram_links l2
                  WHERE l2.user_id=c.user_id
                    AND l2.telegram_user_id=c.requested_by_telegram_id
                    AND l2.status='active'
                  ORDER BY l2.linked_at DESC,l2.id DESC LIMIT 1
              )
            LEFT JOIN user_devices d ON d.user_id=c.user_id
              AND d.device_id COLLATE utf8mb4_unicode_ci
                  =c.device_id COLLATE utf8mb4_unicode_ci
            WHERE c.source='telegram' AND c.requested_by_telegram_id IS NOT NULL
              AND c.status IN ('done','error','cancelled','expired')
              AND c.result_notified_at IS NULL
            ORDER BY COALESCE(c.finished_at,c.created_at) ASC LIMIT 20
            """
        )
    async def mark_notified(self, row: dict[str, Any]) -> bool:
        changed = await self.database.execute(
            """
            UPDATE device_commands SET result_notified_at=NOW()
            WHERE id=%s AND user_id=%s AND device_id=%s
              AND requested_by_telegram_id=%s AND result_notified_at IS NULL
            """,
            (
                int(row["id"]),
                int(row["user_id"]),
                row["device_id"],
                int(row["requested_by_telegram_id"]),
            ),
        )
        return changed == 1
    async def expire_old(self) -> None:
        await self.database.execute(
            """
            UPDATE device_commands SET status='expired',finished_at=NOW(),
                error_message='Команда не была завершена устройством до истечения срока'
            WHERE status IN ('pending','running') AND expires_at IS NOT NULL AND expires_at<NOW()
            """
        )
