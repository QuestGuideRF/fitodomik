from __future__ import annotations
from datetime import datetime, timedelta
from typing import Any
from bot.database import Database
ERROR_EVENT_PATTERN = "%error%"
ERROR_DESCRIPTION_PATTERN = "%ошиб%"
class AlertsRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def pending_device_faults(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            "SELECT id, fault_code, photo_file, created_at, notified_at, photo_notified_at FROM device_fault_reports "
            "WHERE user_id=%s AND device_id=%s AND (notified_at IS NULL OR (photo_file IS NOT NULL AND photo_notified_at IS NULL)) "
            "ORDER BY id ASC LIMIT 20", (user_id, device_id),
        )
    async def mark_device_fault_notified(self, user_id: int, device_id: str, fault_id: int) -> None:
        await self.database.execute(
            "UPDATE device_fault_reports SET notified_at=NOW() "
            "WHERE id=%s AND user_id=%s AND device_id=%s AND notified_at IS NULL",
            (fault_id, user_id, device_id),
        )
    async def mark_device_fault_photo_notified(self, user_id: int, device_id: str, fault_id: int) -> None:
        await self.database.execute(
            "UPDATE device_fault_reports SET photo_notified_at=NOW() WHERE id=%s AND user_id=%s AND device_id=%s",
            (fault_id, user_id, device_id),
        )
    async def state(self, user_id: int, device_id: str, key: str) -> dict[str, Any]:
        await self.database.execute(
            """
            INSERT INTO telegram_alert_state (user_id,device_id,alert_key)
            VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE alert_key=VALUES(alert_key)
            """,
            (user_id, device_id, key),
        )
        row = await self.database.fetch_one(
            "SELECT * FROM telegram_alert_state "
            "WHERE user_id=%s AND device_id=%s AND alert_key=%s LIMIT 1",
            (user_id, device_id, key),
        )
        if row is None:
            raise RuntimeError("Could not create alert state")
        return row
    async def active_states(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT alert_key,active_since,last_sent_at,last_alert_value
            FROM telegram_alert_state
            WHERE user_id=%s AND device_id=%s AND active_since IS NOT NULL
              AND LEFT(alert_key,6)<>'event_'
            ORDER BY active_since ASC
            """,
            (user_id, device_id),
        )
    async def check_due(
        self, user_id: int, device_id: str, interval_minutes: int
    ) -> bool:
        row = await self.state(user_id, device_id, "scheduler_check")
        last_check = row.get("last_sent_at")
        if not isinstance(last_check, datetime):
            return True
        return datetime.now() - last_check >= timedelta(minutes=interval_minutes)
    async def mark_checked(self, user_id: int, device_id: str) -> None:
        await self.database.execute(
            "UPDATE telegram_alert_state SET last_sent_at=NOW() "
            "WHERE user_id=%s AND device_id=%s AND alert_key='scheduler_check'",
            (user_id, device_id),
        )
    async def activate(
        self, user_id: int, device_id: str, key: str, value: str
    ) -> None:
        await self.database.execute(
            """
            UPDATE telegram_alert_state SET active_since=COALESCE(active_since,NOW()),
                last_alert_value=%s WHERE user_id=%s AND device_id=%s AND alert_key=%s
            """,
            (value, user_id, device_id, key),
        )
    async def mark_sent(
        self, user_id: int, device_id: str, key: str, value: str
    ) -> None:
        await self.database.execute(
            """
            UPDATE telegram_alert_state SET active_since=COALESCE(active_since,NOW()),
                last_sent_at=NOW(),last_alert_value=%s
            WHERE user_id=%s AND device_id=%s AND alert_key=%s
            """,
            (value, user_id, device_id, key),
        )
    async def reset(self, user_id: int, device_id: str, key: str, value: str) -> None:
        await self.database.execute(
            """
            UPDATE telegram_alert_state SET active_since=NULL,last_sent_at=NULL,last_alert_value=%s
            WHERE user_id=%s AND device_id=%s AND alert_key=%s
            """,
            (value, user_id, device_id, key),
        )
    async def events(
        self, user_id: int, device_id: str, event_filter: str = "all", limit: int = 10
    ) -> list[dict[str, Any]]:
        clauses = ["user_id=%s", "(device_id=%s OR device_id IS NULL)"]
        params: list[Any] = [user_id, device_id]
        if event_filter == "alerts":
            clauses.append(
                "(event_type IN ('temperature','humidity','soil_moisture','co2','alarm') OR event_description LIKE %s)"
            )
            params.append("%Тревога%")
        elif event_filter == "errors":
            clauses.append("(event_type LIKE %s OR event_description LIKE %s)")
            params.extend((ERROR_EVENT_PATTERN, ERROR_DESCRIPTION_PATTERN))
        elif event_filter == "control":
            clauses.append(
                "event_type IN ('device','lighting','schedule','device_command')"
            )
        params.append(max(1, min(50, limit)))
        return await self.database.fetch_all(
            "SELECT id,event_type,event_description,created_at FROM event_log WHERE "
            + " AND ".join(clauses)
            + " ORDER BY created_at DESC LIMIT %s",
            tuple(params),
        )
    async def recent_fault_events(
        self, user_id: int, device_id: str
    ) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT id,event_type,event_description,created_at FROM event_log
            WHERE user_id=%s AND (device_id=%s OR device_id IS NULL)
              AND created_at>=NOW()-INTERVAL 2 MINUTE
              AND (event_type LIKE %s OR event_description LIKE %s
                   OR event_description LIKE %s OR event_description LIKE %s)
            ORDER BY created_at ASC LIMIT 20
            """,
            (
                user_id,
                device_id,
                ERROR_EVENT_PATTERN,
                ERROR_DESCRIPTION_PATTERN,
                "%недоступ%",
                "%offline%",
            ),
        )
    async def daily_counts(self, user_id: int, device_id: str) -> dict[str, Any]:
        return (
            await self.database.fetch_one(
                """
                SELECT COUNT(*) events_count,
                       SUM(event_type IN ('alarm','temperature','humidity','soil_moisture','co2')) alert_count,
                       SUM(event_description LIKE %s) watering_count,
                       SUM(event_type LIKE %s OR event_description LIKE %s) error_count
                FROM event_log WHERE user_id=%s AND (device_id=%s OR device_id IS NULL)
                  AND DATE(created_at)=CURDATE()
                """,
                (
                    "%полив%",
                    ERROR_EVENT_PATTERN,
                    ERROR_DESCRIPTION_PATTERN,
                    user_id,
                    device_id,
                ),
            )
            or {}
        )
