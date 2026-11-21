from __future__ import annotations
import json
import hashlib
from datetime import datetime, timedelta
from typing import Any
import aiomysql
from bot.database import Database
SETTING_COLUMNS = {
    "alerts": "alerts_enabled",
    "report": "daily_report_enabled",
    "offline": "offline_alert_enabled",
    "arduino": "arduino_alert_enabled",
    "camera": "camera_alert_enabled",
    "analysis": "analysis_alert_enabled",
    "photo": "photo_alert_enabled",
    "system": "system_alert_enabled",
}
SETTING_UPDATE_QUERIES = {
    "alerts": "UPDATE telegram_settings SET alerts_enabled=%s WHERE id=%s",
    "report": "UPDATE telegram_settings SET daily_report_enabled=%s WHERE id=%s",
    "offline": "UPDATE telegram_settings SET offline_alert_enabled=%s WHERE id=%s",
    "arduino": "UPDATE telegram_settings SET arduino_alert_enabled=%s WHERE id=%s",
    "camera": "UPDATE telegram_settings SET camera_alert_enabled=%s WHERE id=%s",
    "analysis": "UPDATE telegram_settings SET analysis_alert_enabled=%s WHERE id=%s",
    "photo": "UPDATE telegram_settings SET photo_alert_enabled=%s WHERE id=%s",
    "system": "UPDATE telegram_settings SET system_alert_enabled=%s WHERE id=%s",
}
SITE_TOGGLE_QUERIES = {
    "alerts": "INSERT INTO device_notification_settings "
    "(user_id,device_id,telegram_enabled) VALUES (%s,%s,%s) "
    "ON DUPLICATE KEY UPDATE telegram_enabled=VALUES(telegram_enabled)",
    "report": "INSERT INTO device_notification_settings "
    "(user_id,device_id,send_daily_report) VALUES (%s,%s,%s) "
    "ON DUPLICATE KEY UPDATE send_daily_report=VALUES(send_daily_report)",
    "camera": "INSERT INTO device_notification_settings "
    "(user_id,device_id,notify_camera) VALUES (%s,%s,%s) "
    "ON DUPLICATE KEY UPDATE notify_camera=VALUES(notify_camera)",
    "photo": "INSERT INTO device_notification_settings "
    "(user_id,device_id,send_photos_telegram) VALUES (%s,%s,%s) "
    "ON DUPLICATE KEY UPDATE send_photos_telegram=VALUES(send_photos_telegram)",
}
NUMERIC_UPDATE_QUERIES = {
    "offline_after_minutes": "UPDATE telegram_settings "
    "SET offline_after_minutes=%s WHERE id=%s",
    "alert_cooldown_minutes": "UPDATE telegram_settings "
    "SET alert_cooldown_minutes=%s WHERE id=%s",
}
class TelegramRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def link_code_for_update(
        self,
        value: str,
        *,
        deep_token_hash: str | None,
        connection: aiomysql.Connection,
    ) -> dict[str, Any] | None:
        if deep_token_hash is not None:
            query = """
                SELECT * FROM telegram_link_codes
                WHERE deep_token_hash = %s AND status = 'pending' AND expires_at > NOW()
                LIMIT 1 FOR UPDATE
            """
            params = (deep_token_hash,)
        else:
            query = """
                SELECT * FROM telegram_link_codes
                WHERE code = %s AND status = 'pending' AND expires_at > NOW()
                LIMIT 1 FOR UPDATE
            """
            params = (value,)
        return await self.database.fetch_one(query, params, connection=connection)
    async def bind(
        self,
        user: dict[str, Any],
        telegram: dict[str, Any],
        code_id: int,
        connection: aiomysql.Connection,
    ) -> bool:
        telegram_id = int(telegram["id"])
        chat_id = str(telegram["chat_id"])
        displaced = await self.database.fetch_all(
            "SELECT id,user_id FROM telegram_links WHERE status='active' "
            "AND (telegram_user_id=%s OR user_id=%s) FOR UPDATE",
            (telegram_id, int(user["id"])), connection=connection,
        )
        for owner in {int(row["user_id"]) for row in displaced}:
            await self.revoke_web_grants(owner, connection)
            await self.database.execute(
                "UPDATE telegram_callback_actions SET status='cancelled',used_at=NOW() "
                "WHERE user_id=%s AND status='pending'", (owner,), connection=connection,
            )
        await self.database.execute(
            "UPDATE telegram_links SET status='revoked', revoked_at=NOW() "
            "WHERE telegram_user_id=%s AND status='active'",
            (telegram_id,),
            connection=connection,
        )
        await self.database.execute(
            "UPDATE telegram_links SET status='revoked', revoked_at=NOW() "
            "WHERE user_id=%s AND status='active'",
            (int(user["id"]),),
            connection=connection,
        )
        await self.database.insert(
            """
            INSERT INTO telegram_links (
                user_id, telegram_user_id, telegram_chat_id, telegram_username,
                telegram_first_name, telegram_last_name, status, linked_at, last_seen_at
            ) VALUES (%s,%s,%s,%s,%s,%s,'active',NOW(),NOW())
            """,
            (
                int(user["id"]),
                telegram_id,
                chat_id,
                telegram.get("username") or None,
                telegram.get("first_name") or None,
                telegram.get("last_name") or None,
            ),
            connection=connection,
        )
        await self.database.execute(
            """
            UPDATE users SET telegram_chat_id=%s, telegram_user_id=%s, telegram_username=%s
            WHERE id=%s
            """,
            (chat_id, telegram_id, telegram.get("username") or None, int(user["id"])),
            connection=connection,
        )
        await self.database.execute(
            """
            INSERT INTO user_telegram_settings (
                user_id, telegram_chat_id, telegram_user_id, telegram_username,
                telegram_last_name, enabled, status, last_seen_at
            ) VALUES (%s,%s,%s,%s,%s,1,'active',NOW())
            ON DUPLICATE KEY UPDATE telegram_chat_id=VALUES(telegram_chat_id),
                telegram_user_id=VALUES(telegram_user_id),
                telegram_username=VALUES(telegram_username),
                telegram_last_name=VALUES(telegram_last_name), enabled=1,
                status='active', last_seen_at=NOW()
            """,
            (
                int(user["id"]),
                chat_id,
                telegram_id,
                telegram.get("username") or None,
                telegram.get("last_name") or None,
            ),
            connection=connection,
        )
        changed = await self.database.execute(
            """
            DELETE FROM telegram_link_codes
            WHERE id=%s AND status='pending' AND expires_at > NOW()
            """,
            (code_id,),
            connection=connection,
        )
        if changed != 1:
            return False
        await self.database.execute(
            "UPDATE telegram_link_codes SET status='revoked' "
            "WHERE user_id=%s AND id<>%s AND status='pending'",
            (int(user["id"]), code_id),
            connection=connection,
        )
        await self.touch_session(telegram_id, int(user["id"]), connection=connection)
        return True
    async def touch_session(
        self,
        telegram_user_id: int,
        user_id: int,
        *,
        connection: aiomysql.Connection | None = None,
    ) -> None:
        await self.database.execute(
            """
            INSERT INTO telegram_sessions (telegram_user_id, user_id, last_seen_at)
            VALUES (%s,%s,NOW())
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), last_seen_at=NOW()
            """,
            (telegram_user_id, user_id),
            connection=connection,
        )
        await self.database.execute(
            "UPDATE telegram_links SET last_seen_at=NOW() "
            "WHERE telegram_user_id=%s AND user_id=%s AND status='active'",
            (telegram_user_id, user_id),
            connection=connection,
        )
    async def session(
        self, telegram_user_id: int, user_id: int
    ) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            "SELECT * FROM telegram_sessions WHERE telegram_user_id=%s AND user_id=%s LIMIT 1",
            (telegram_user_id, user_id),
        )
    async def select_device(
        self, telegram_user_id: int, user_id: int, device_id: str
    ) -> None:
        await self.database.execute(
            """
            UPDATE telegram_sessions SET selected_device_id=%s, state=NULL, state_payload=NULL
            WHERE telegram_user_id=%s AND user_id=%s
            """,
            (device_id, telegram_user_id, user_id),
        )
    async def get_settings(self, user_id: int, device_id: str | None) -> dict[str, Any]:
        row = await self.database.fetch_one(
            "SELECT * FROM telegram_settings WHERE user_id=%s AND device_id <=> %s ORDER BY id LIMIT 1",
            (user_id, device_id),
        )
        if row is None:
            await self.database.execute(
                "INSERT INTO telegram_settings (user_id, device_id) VALUES (%s,%s)",
                (user_id, device_id),
            )
            row = await self.database.fetch_one(
                "SELECT * FROM telegram_settings WHERE user_id=%s AND device_id <=> %s ORDER BY id LIMIT 1",
                (user_id, device_id),
            )
        if row is None:
            raise RuntimeError("Could not create Telegram settings")
        return row
    async def site_notification_settings(
        self, user_id: int, device_id: str
    ) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            """
            SELECT telegram_enabled,notify_soil_moisture,notify_temperature,
                   notify_camera,send_photos_telegram,send_daily_report,
                   daily_report_time,alert_check_interval_minutes,
                   alert_repeat_cooldown_minutes
            FROM device_notification_settings
            WHERE user_id=%s AND device_id=%s LIMIT 1
            """,
            (user_id, device_id),
        )
    async def set_toggle(self, settings_id: int, key: str, enabled: bool) -> None:
        query = SETTING_UPDATE_QUERIES.get(key)
        if query is None:
            raise ValueError("Unknown notification setting")
        await self.database.execute(
            query,
            (1 if enabled else 0, settings_id),
        )
    async def set_site_toggle(
        self, user_id: int, device_id: str, key: str, enabled: bool
    ) -> None:
        query = SITE_TOGGLE_QUERIES.get(key)
        if query is None:
            return
        await self.database.execute(
            query,
            (user_id, device_id, 1 if enabled else 0),
        )
    async def set_numeric(self, settings_id: int, column: str, value: int) -> None:
        query = NUMERIC_UPDATE_QUERIES.get(column)
        if query is None:
            raise ValueError("Unknown numeric setting")
        await self.database.execute(
            query,
            (value, settings_id),
        )
    async def set_report_time(self, settings_id: int, report_time: str) -> None:
        await self.database.execute(
            "UPDATE telegram_settings SET report_time=%s WHERE id=%s",
            (report_time, settings_id),
        )
    async def set_site_report_time(
        self, user_id: int, device_id: str, report_time: str
    ) -> None:
        await self.database.execute(
            """
            INSERT INTO device_notification_settings
                (user_id,device_id,daily_report_time)
            VALUES (%s,%s,%s)
            ON DUPLICATE KEY UPDATE daily_report_time=VALUES(daily_report_time)
            """,
            (user_id, device_id, report_time),
        )
    async def set_site_cooldown(
        self, user_id: int, device_id: str, minutes: int
    ) -> None:
        await self.database.execute(
            """
            INSERT INTO device_notification_settings
                (user_id,device_id,alert_repeat_cooldown_minutes)
            VALUES (%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                alert_repeat_cooldown_minutes=VALUES(alert_repeat_cooldown_minutes)
            """,
            (user_id, device_id, minutes),
        )
    async def claim_daily_report(self, settings_id: int) -> bool:
        changed = await self.database.execute(
            """
            UPDATE telegram_settings SET last_daily_report_date=CURDATE()
            WHERE id=%s AND (last_daily_report_date IS NULL OR last_daily_report_date<>CURDATE())
            """,
            (settings_id,),
        )
        return changed == 1
    async def release_daily_report(self, settings_id: int) -> None:
        await self.database.execute(
            "UPDATE telegram_settings SET last_daily_report_date=NULL "
            "WHERE id=%s AND last_daily_report_date=CURDATE()",
            (settings_id,),
        )
    async def create_callback(
        self,
        token_hash: str,
        user_id: int,
        telegram_user_id: int,
        device_id: str,
        action: str,
        payload: dict[str, Any],
    ) -> None:
        async with self.database.transaction() as connection:
            link = await self.active_link_for_update(user_id, telegram_user_id, connection)
            if not link:
                raise ValueError("Telegram binding is no longer active")
            await self.database.execute(
                "INSERT INTO telegram_callback_actions "
                "(token_hash,user_id,telegram_user_id,device_id,action,action_payload,"
                "telegram_link_id,status,created_at,expires_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,'pending',NOW(),DATE_ADD(NOW(),INTERVAL 5 MINUTE))",
                (token_hash,user_id,telegram_user_id,device_id,action,json.dumps(payload),link["id"]),
                connection=connection,
            )
    async def active_link_for_update(self, user_id, telegram_user_id, connection):
        return await self.database.fetch_one(
            "SELECT l.id,l.user_id FROM telegram_links l JOIN users u ON u.id=l.user_id "
            "WHERE l.user_id=%s AND l.telegram_user_id=%s AND l.status='active' "
            "AND u.is_locked=0 LIMIT 1 FOR UPDATE",
            (user_id, telegram_user_id), connection=connection,
        )
    async def create_login_token(self, user_id: int, chat_id: str, token_hash: str) -> None:
        async with self.database.transaction() as connection:
            link = await self.database.fetch_one(
                "SELECT l.id FROM telegram_links l JOIN users u ON u.id=l.user_id "
                "WHERE l.user_id=%s AND l.telegram_chat_id=%s AND l.status='active' "
                "AND u.is_locked=0 LIMIT 1 FOR UPDATE", (user_id,chat_id), connection=connection,
            )
            if not link:
                raise ValueError("Telegram binding is no longer active")
            await self.database.execute(
                "UPDATE telegram_login_tokens SET used_at=NOW() WHERE user_id=%s AND used_at IS NULL",
                (user_id,), connection=connection,
            )
            await self.database.execute(
                "INSERT INTO telegram_login_tokens (user_id,telegram_chat_id,token_hash,expires_at,telegram_link_id) "
                "VALUES (%s,%s,%s,DATE_ADD(NOW(),INTERVAL 10 MINUTE),%s)",
                (user_id,chat_id,token_hash,link["id"]), connection=connection,
            )
    async def callback_for_update(self, token_hash, telegram_user_id, connection):
        return await self.database.fetch_one(
            "SELECT a.* FROM telegram_callback_actions a "
            "JOIN telegram_links l ON l.id=a.telegram_link_id AND l.user_id=a.user_id "
            "AND l.telegram_user_id=a.telegram_user_id AND l.status='active' "
            "JOIN users u ON u.id=a.user_id AND u.is_locked=0 "
            "WHERE a.token_hash=%s AND a.telegram_user_id=%s LIMIT 1 FOR UPDATE",
            (token_hash,telegram_user_id), connection=connection,
        )
    async def revoke_web_grants(self, user_id, connection):
        # Historical sessions have no provenance; incrementing the account epoch
        # also signs out historical password sessions, which can log in again.
        await self.database.execute(
            "UPDATE users SET auth_generation=auth_generation+1 WHERE id=%s",
            (user_id,), connection=connection,
        )
        await self.database.execute(
            "UPDATE telegram_login_tokens SET used_at=NOW() WHERE user_id=%s AND used_at IS NULL",
            (user_id,), connection=connection,
        )
        await self.database.execute(
            "UPDATE user_remember_tokens SET revoked_at=NOW() WHERE user_id=%s AND revoked_at IS NULL",
            (user_id,), connection=connection,
        )
    async def finish_callback(
        self, action_id: int, status: str, connection: aiomysql.Connection
    ) -> bool:
        if status not in {"used", "cancelled", "expired"}:
            raise ValueError("Invalid callback status")
        changed = await self.database.execute(
            "UPDATE telegram_callback_actions SET status=%s, used_at=NOW() "
            "WHERE id=%s AND status='pending'",
            (status, action_id),
            connection=connection,
        )
        return changed == 1
    async def unlink(self, user_id: int) -> None:
        async with self.database.transaction() as connection:
            await self.revoke_web_grants(user_id, connection)
            await self.database.execute(
                "UPDATE telegram_links SET status='revoked', revoked_at=NOW() "
                "WHERE user_id=%s AND status='active'",
                (user_id,),
                connection=connection,
            )
            await self.database.execute(
                "UPDATE telegram_link_codes SET status='revoked' "
                "WHERE user_id=%s AND status='pending'",
                (user_id,),
                connection=connection,
            )
            await self.database.execute(
                "UPDATE telegram_callback_actions SET status='cancelled', used_at=NOW() "
                "WHERE user_id=%s AND status='pending'",
                (user_id,),
                connection=connection,
            )
            await self.database.execute(
                "UPDATE user_telegram_settings SET status='revoked', enabled=0 WHERE user_id=%s",
                (user_id,),
                connection=connection,
            )
            await self.database.execute(
                "UPDATE users SET telegram_user_id=NULL, telegram_chat_id=NULL WHERE id=%s",
                (user_id,),
                connection=connection,
            )
            await self.database.execute(
                "DELETE FROM telegram_sessions WHERE user_id=%s",
                (user_id,),
                connection=connection,
            )
    async def cleanup(self) -> None:
        await self.database.execute(
            "UPDATE telegram_link_codes SET status='expired' "
            "WHERE status='pending' AND expires_at<NOW()"
        )
        await self.database.execute(
            "UPDATE telegram_callback_actions SET status='expired' "
            "WHERE status='pending' AND expires_at<NOW()"
        )
        await self.database.execute(
            "DELETE FROM telegram_login_tokens WHERE expires_at<NOW()-INTERVAL 1 DAY"
        )
        await self.database.execute(
            "DELETE FROM telegram_sessions WHERE last_seen_at<NOW()-INTERVAL 90 DAY"
        )
        await self.database.execute(
            "DELETE FROM telegram_callback_actions "
            "WHERE status<>'pending' AND created_at<NOW()-INTERVAL 7 DAY"
        )
        await self.database.execute(
            "DELETE FROM telegram_alert_state "
            "WHERE LEFT(alert_key,6)='event_' "
            "AND created_at<NOW()-INTERVAL 7 DAY"
        )
    async def consume_rate_limit(
        self,
        scope: str,
        subject: str,
        maximum: int = 5,
        window_seconds: int = 900,
        block_seconds: int = 900,
    ) -> int:
        bucket = hashlib.sha256(f"{scope}\0{subject}".encode("utf-8")).hexdigest()
        now = datetime.now()
        async with self.database.transaction() as connection:
            row = await self.database.fetch_one(
                "SELECT attempts,window_started_at,blocked_until FROM api_rate_limits "
                "WHERE bucket_key=%s FOR UPDATE",
                (bucket,),
                connection=connection,
            )
            blocked_until = row.get("blocked_until") if row else None
            if isinstance(blocked_until, datetime) and blocked_until > now:
                return max(1, int((blocked_until - now).total_seconds()))
            window_start = row.get("window_started_at") if row else None
            reset = (
                not isinstance(window_start, datetime)
                or (now - window_start).total_seconds() >= window_seconds
            )
            attempts = 1 if reset or row is None else int(row.get("attempts") or 0) + 1
            blocked = attempts > maximum
            next_block = now + timedelta(seconds=block_seconds) if blocked else None
            await self.database.execute(
                """
                INSERT INTO api_rate_limits (bucket_key,window_started_at,attempts,blocked_until)
                VALUES (%s,%s,%s,%s)
                ON DUPLICATE KEY UPDATE window_started_at=VALUES(window_started_at),
                    attempts=VALUES(attempts),blocked_until=VALUES(blocked_until)
                """,
                (bucket, now if reset else window_start, attempts, next_block),
                connection=connection,
            )
        return block_seconds if blocked else 0
