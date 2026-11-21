from __future__ import annotations
import logging
import argparse
import asyncio
from bot.config import load_settings
from bot.database import Database
from bot.collations import normalize_project_collations
LOGGER = logging.getLogger(__name__)
TELEGRAM_USER_ID_COLUMN = "BIGINT DEFAULT NULL AFTER `telegram_chat_id`"
TELEGRAM_NAME_COLUMN = "VARCHAR(120) DEFAULT NULL AFTER `telegram_username`"
TABLES = (
    """
    CREATE TABLE IF NOT EXISTS api_rate_limits (
        bucket_key CHAR(64) NOT NULL PRIMARY KEY,
        window_started_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        blocked_until DATETIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_api_rate_limits_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS user_telegram_settings (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        telegram_chat_id VARCHAR(64) DEFAULT NULL,
        telegram_user_id BIGINT DEFAULT NULL,
        telegram_username VARCHAR(120) DEFAULT NULL,
        telegram_last_name VARCHAR(120) DEFAULT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        last_seen_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_telegram_settings_user (user_id),
        INDEX idx_user_telegram_id (telegram_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_verifications (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NOT NULL,
        telegram_chat_id VARCHAR(64) NOT NULL,
        telegram_user_id BIGINT DEFAULT NULL,
        telegram_username VARCHAR(120) DEFAULT NULL,
        telegram_first_name VARCHAR(120) DEFAULT NULL,
        registration_code_hash CHAR(64) DEFAULT NULL,
        user_id INT DEFAULT NULL,
        verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_telegram_verification_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_login_tokens (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        telegram_chat_id VARCHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_telegram_login_token (token_hash),
        INDEX idx_telegram_login_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_links (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        telegram_user_id BIGINT NOT NULL,
        telegram_chat_id VARCHAR(64) NOT NULL,
        telegram_username VARCHAR(120) DEFAULT NULL,
        telegram_first_name VARCHAR(120) DEFAULT NULL,
        telegram_last_name VARCHAR(120) DEFAULT NULL,
        status ENUM('active','revoked') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME DEFAULT NULL,
        last_seen_at DATETIME DEFAULT NULL,
        INDEX idx_telegram_links_telegram_user (telegram_user_id),
        INDEX idx_telegram_links_user (user_id),
        INDEX idx_telegram_links_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_link_codes (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code VARCHAR(20) NOT NULL UNIQUE,
        deep_token_hash CHAR(64) DEFAULT NULL,
        status ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        telegram_user_id BIGINT DEFAULT NULL,
        telegram_chat_id VARCHAR(64) DEFAULT NULL,
        INDEX idx_tg_link_codes_user (user_id),
        INDEX idx_tg_link_codes_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_sessions (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT NOT NULL UNIQUE,
        user_id INT NOT NULL,
        selected_device_id VARCHAR(64) DEFAULT NULL,
        state VARCHAR(80) DEFAULT NULL,
        state_payload LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_seen_at DATETIME DEFAULT NULL,
        INDEX idx_tg_sessions_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_settings (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) DEFAULT NULL,
        alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
        daily_report_enabled TINYINT(1) NOT NULL DEFAULT 1,
        offline_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
        arduino_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
        camera_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
        analysis_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
        photo_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
        system_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
        report_time VARCHAR(5) NOT NULL DEFAULT '20:00',
        quiet_hours_start VARCHAR(5) DEFAULT NULL,
        quiet_hours_end VARCHAR(5) DEFAULT NULL,
        offline_after_minutes INT NOT NULL DEFAULT 120,
        alert_cooldown_minutes INT NOT NULL DEFAULT 180,
        last_daily_report_date DATE DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tg_settings_device (user_id, device_id),
        INDEX idx_tg_settings_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_alert_state (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        alert_key VARCHAR(80) NOT NULL,
        active_since DATETIME DEFAULT NULL,
        last_sent_at DATETIME DEFAULT NULL,
        last_alert_value VARCHAR(120) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tg_alert_state (user_id, device_id, alert_key),
        INDEX idx_tg_alert_state_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS telegram_callback_actions (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        telegram_user_id BIGINT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        action VARCHAR(80) NOT NULL,
        action_payload LONGTEXT DEFAULT NULL,
        status ENUM('pending','used','cancelled','expired') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        INDEX idx_tg_callback_user (user_id, telegram_user_id),
        INDEX idx_tg_callback_status_expires (status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS device_commands (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        command_type VARCHAR(80) NOT NULL,
        command_payload LONGTEXT DEFAULT NULL,
        status ENUM('pending','running','done','error','cancelled','expired') NOT NULL DEFAULT 'pending',
        source ENUM('web','site','telegram','automation','desktop','system') NOT NULL DEFAULT 'site',
        requested_by_user_id INT DEFAULT NULL,
        requested_by_telegram_id BIGINT DEFAULT NULL,
        created_by_user_id INT DEFAULT NULL,
        telegram_user_id BIGINT DEFAULT NULL,
        scenario_id VARCHAR(96) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME DEFAULT NULL,
        picked_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        duration_ms INT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        result_message TEXT DEFAULT NULL,
        result_payload LONGTEXT DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        attempts INT NOT NULL DEFAULT 0,
        cancelled_at DATETIME DEFAULT NULL,
        cancelled_by_user_id INT DEFAULT NULL,
        result_notified_at DATETIME DEFAULT NULL,
        INDEX idx_device_commands_device_status (user_id, device_id, status),
        INDEX idx_device_commands_telegram_notify (source, requested_by_telegram_id, status, result_notified_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS event_log (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) DEFAULT NULL,
        event_type VARCHAR(80) NOT NULL,
        event_description TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_user_device_created (user_id,device_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS alarm_thresholds (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        parameter_type ENUM('temperature','humidity_air','humidity_soil','co2') NOT NULL,
        min_limit DECIMAL(8,2) NOT NULL,
        max_limit DECIMAL(8,2) NOT NULL,
        target_value DECIMAL(8,2) DEFAULT NULL,
        tolerance DECIMAL(5,2) DEFAULT 1.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY user_parameter_unique (user_id,parameter_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS schedule (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        curtains_schedule TINYINT(1) DEFAULT 0,
        lighting_schedule TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        time VARCHAR(10) NOT NULL,
        KEY user_id (user_id,time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE IF NOT EXISTS device_notification_settings (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        telegram_enabled TINYINT(1) NOT NULL DEFAULT 1,
        notify_soil_moisture TINYINT(1) NOT NULL DEFAULT 1,
        notify_temperature TINYINT(1) NOT NULL DEFAULT 1,
        notify_camera TINYINT(1) NOT NULL DEFAULT 1,
        send_photos_telegram TINYINT(1) NOT NULL DEFAULT 0,
        send_daily_report TINYINT(1) NOT NULL DEFAULT 1,
        daily_report_time VARCHAR(5) NOT NULL DEFAULT '20:00',
        alert_check_interval_minutes INT NOT NULL DEFAULT 60,
        alert_repeat_cooldown_minutes INT NOT NULL DEFAULT 180,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_device_notifications (user_id,device_id),
        INDEX idx_device_notifications_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
)
TABLE_NAMES = (
    "api_rate_limits",
    "user_telegram_settings",
    "telegram_verifications",
    "telegram_login_tokens",
    "telegram_links",
    "telegram_link_codes",
    "telegram_sessions",
    "telegram_settings",
    "telegram_alert_state",
    "telegram_callback_actions",
    "device_commands",
    "event_log",
    "alarm_thresholds",
    "schedule",
    "device_notification_settings",
)
COLUMN_DDL = {
    (
        "users",
        "telegram_user_id",
        TELEGRAM_USER_ID_COLUMN,
    ): "ALTER TABLE `users` ADD COLUMN `telegram_user_id` BIGINT DEFAULT NULL AFTER `telegram_chat_id`",
    (
        "users",
        "telegram_last_name",
        TELEGRAM_NAME_COLUMN,
    ): "ALTER TABLE `users` ADD COLUMN `telegram_last_name` VARCHAR(120) DEFAULT NULL AFTER `telegram_username`",
    (
        "user_telegram_settings",
        "telegram_user_id",
        TELEGRAM_USER_ID_COLUMN,
    ): "ALTER TABLE `user_telegram_settings` ADD COLUMN `telegram_user_id` BIGINT DEFAULT NULL AFTER `telegram_chat_id`",
    (
        "user_telegram_settings",
        "telegram_last_name",
        TELEGRAM_NAME_COLUMN,
    ): "ALTER TABLE `user_telegram_settings` ADD COLUMN `telegram_last_name` VARCHAR(120) DEFAULT NULL AFTER `telegram_username`",
    (
        "user_telegram_settings",
        "status",
        "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `enabled`",
    ): "ALTER TABLE `user_telegram_settings` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `enabled`",
    (
        "user_telegram_settings",
        "last_seen_at",
        "DATETIME DEFAULT NULL AFTER `status`",
    ): "ALTER TABLE `user_telegram_settings` ADD COLUMN `last_seen_at` DATETIME DEFAULT NULL AFTER `status`",
    (
        "telegram_link_codes",
        "deep_token_hash",
        "CHAR(64) DEFAULT NULL AFTER `code`",
    ): "ALTER TABLE `telegram_link_codes` ADD COLUMN `deep_token_hash` CHAR(64) DEFAULT NULL AFTER `code`",
    (
        "device_commands",
        "requested_by_telegram_id",
        "BIGINT DEFAULT NULL AFTER `requested_by_user_id`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `requested_by_telegram_id` BIGINT DEFAULT NULL AFTER `requested_by_user_id`",
    (
        "device_commands",
        "created_by_user_id",
        "INT DEFAULT NULL AFTER `requested_by_telegram_id`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `created_by_user_id` INT DEFAULT NULL AFTER `requested_by_telegram_id`",
    (
        "device_commands",
        "telegram_user_id",
        "BIGINT DEFAULT NULL AFTER `created_by_user_id`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `telegram_user_id` BIGINT DEFAULT NULL AFTER `created_by_user_id`",
    (
        "device_commands",
        "scenario_id",
        "VARCHAR(96) DEFAULT NULL AFTER `telegram_user_id`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `scenario_id` VARCHAR(96) DEFAULT NULL AFTER `telegram_user_id`",
    (
        "device_commands",
        "picked_at",
        "DATETIME DEFAULT NULL AFTER `started_at`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `picked_at` DATETIME DEFAULT NULL AFTER `started_at`",
    (
        "device_commands",
        "duration_ms",
        "INT DEFAULT NULL AFTER `finished_at`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `duration_ms` INT DEFAULT NULL AFTER `finished_at`",
    (
        "device_commands",
        "attempts",
        "INT NOT NULL DEFAULT 0 AFTER `error_message`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `attempts` INT NOT NULL DEFAULT 0 AFTER `error_message`",
    (
        "device_commands",
        "cancelled_at",
        "DATETIME DEFAULT NULL AFTER `attempts`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL AFTER `attempts`",
    (
        "device_commands",
        "cancelled_by_user_id",
        "INT DEFAULT NULL AFTER `cancelled_at`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `cancelled_by_user_id` INT DEFAULT NULL AFTER `cancelled_at`",
    (
        "device_commands",
        "result_notified_at",
        "DATETIME DEFAULT NULL AFTER `error_message`",
    ): "ALTER TABLE `device_commands` ADD COLUMN `result_notified_at` DATETIME DEFAULT NULL AFTER `error_message`",
    (
        "telegram_settings",
        "photo_alert_enabled",
        "TINYINT(1) NOT NULL DEFAULT 0 AFTER `analysis_alert_enabled`",
    ): "ALTER TABLE `telegram_settings` ADD COLUMN `photo_alert_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `analysis_alert_enabled`",
    (
        "telegram_settings",
        "system_alert_enabled",
        "TINYINT(1) NOT NULL DEFAULT 1 AFTER `photo_alert_enabled`",
    ): "ALTER TABLE `telegram_settings` ADD COLUMN `system_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `photo_alert_enabled`",
    (
        "telegram_settings",
        "offline_after_minutes",
        "INT NOT NULL DEFAULT 120 AFTER `quiet_hours_end`",
    ): "ALTER TABLE `telegram_settings` ADD COLUMN `offline_after_minutes` INT NOT NULL DEFAULT 120 AFTER `quiet_hours_end`",
    (
        "telegram_settings",
        "alert_cooldown_minutes",
        "INT NOT NULL DEFAULT 180 AFTER `offline_after_minutes`",
    ): "ALTER TABLE `telegram_settings` ADD COLUMN `alert_cooldown_minutes` INT NOT NULL DEFAULT 180 AFTER `offline_after_minutes`",
    (
        "telegram_settings",
        "last_daily_report_date",
        "DATE DEFAULT NULL AFTER `alert_cooldown_minutes`",
    ): "ALTER TABLE `telegram_settings` ADD COLUMN `last_daily_report_date` DATE DEFAULT NULL AFTER `alert_cooldown_minutes`",
    (
        "telegram_alert_state",
        "last_alert_value",
        "VARCHAR(120) DEFAULT NULL AFTER `last_sent_at`",
    ): "ALTER TABLE `telegram_alert_state` ADD COLUMN `last_alert_value` VARCHAR(120) DEFAULT NULL AFTER `last_sent_at`",
    (
        "event_log",
        "device_id",
        "VARCHAR(64) DEFAULT NULL AFTER `user_id`",
    ): "ALTER TABLE `event_log` ADD COLUMN `device_id` VARCHAR(64) DEFAULT NULL AFTER `user_id`",
}
INDEX_DDL = {
    (
        "user_telegram_settings",
        "idx_user_telegram_id",
        "`telegram_user_id`",
        False,
    ): "CREATE INDEX `idx_user_telegram_id` ON `user_telegram_settings` (`telegram_user_id`)",
    (
        "telegram_link_codes",
        "uniq_tg_link_deep_token",
        "`deep_token_hash`",
        True,
    ): "CREATE UNIQUE INDEX `uniq_tg_link_deep_token` ON `telegram_link_codes` (`deep_token_hash`)",
    (
        "device_commands",
        "idx_device_commands_source_created",
        "`user_id`, `device_id`, `source`, `created_at`",
        False,
    ): "CREATE INDEX `idx_device_commands_source_created` ON `device_commands` (`user_id`, `device_id`, `source`, `created_at`)",
    (
        "device_commands",
        "idx_device_commands_telegram_notify",
        "`source`, `requested_by_telegram_id`, `status`, `result_notified_at`",
        False,
    ): "CREATE INDEX `idx_device_commands_telegram_notify` ON `device_commands` (`source`, `requested_by_telegram_id`, `status`, `result_notified_at`)",
    (
        "event_log",
        "idx_event_user_device_created",
        "`user_id`, `device_id`, `created_at`",
        False,
    ): "CREATE INDEX `idx_event_user_device_created` ON `event_log` (`user_id`, `device_id`, `created_at`)",
}
DROP_INDEX_DDL = {
    (
        "telegram_links",
        "uniq_telegram_user_active",
    ): "ALTER TABLE `telegram_links` DROP INDEX `uniq_telegram_user_active`"
}
async def _table_exists(database: Database, table: str) -> bool:
    count = await database.fetch_value(
        """
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s
        """,
        (table,),
    )
    return bool(count)
async def _column_exists(database: Database, table: str, column: str) -> bool:
    count = await database.fetch_value(
        """
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
        """,
        (table, column),
    )
    return bool(count)
async def _index_exists(database: Database, table: str, index: str) -> bool:
    count = await database.fetch_value(
        """
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
        """,
        (table, index),
    )
    return bool(count)
async def _column_type(database: Database, table: str, column: str) -> str:
    value = await database.fetch_value(
        """
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
        LIMIT 1
        """,
        (table, column),
    )
    return str(value or "").lower()
async def _add_column(
    database: Database, table: str, column: str, definition: str
) -> None:
    statement = COLUMN_DDL.get((table, column, definition))
    if statement is None:
        raise ValueError("Unapproved column migration")
    if not await _column_exists(database, table, column):
        await database.execute(statement)
async def _add_index(
    database: Database, table: str, index: str, columns: str, unique: bool = False
) -> None:
    statement = INDEX_DDL.get((table, index, columns, unique))
    if statement is None:
        raise ValueError("Unapproved index migration")
    if await _index_exists(database, table, index):
        return
    await database.execute(statement)
async def _drop_index(database: Database, table: str, index: str) -> None:
    statement = DROP_INDEX_DDL.get((table, index))
    if statement is None:
        raise ValueError("Unapproved index removal")
    if await _index_exists(database, table, index):
        await database.execute(statement)
async def migrate(database: Database) -> None:
    if len(TABLE_NAMES) != len(TABLES):
        raise RuntimeError("Telegram migration table metadata is inconsistent")
    for table, statement in zip(TABLE_NAMES, TABLES):
        if not await _table_exists(database, table):
            await database.execute(statement)
    for table, column, definition in (
        ("users", "telegram_user_id", TELEGRAM_USER_ID_COLUMN),
        (
            "users",
            "telegram_last_name",
            TELEGRAM_NAME_COLUMN,
        ),
        (
            "user_telegram_settings",
            "telegram_user_id",
            TELEGRAM_USER_ID_COLUMN,
        ),
        (
            "user_telegram_settings",
            "telegram_last_name",
            TELEGRAM_NAME_COLUMN,
        ),
        (
            "user_telegram_settings",
            "status",
            "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `enabled`",
        ),
        (
            "user_telegram_settings",
            "last_seen_at",
            "DATETIME DEFAULT NULL AFTER `status`",
        ),
    ):
        await _add_column(database, table, column, definition)
    await _add_index(
        database,
        "user_telegram_settings",
        "idx_user_telegram_id",
        "`telegram_user_id`",
    )
    await _drop_index(database, "telegram_links", "uniq_telegram_user_active")
    await _add_column(
        database,
        "telegram_link_codes",
        "deep_token_hash",
        "CHAR(64) DEFAULT NULL AFTER `code`",
    )
    await _add_index(
        database,
        "telegram_link_codes",
        "uniq_tg_link_deep_token",
        "`deep_token_hash`",
        unique=True,
    )
    for column, definition in (
        (
            "requested_by_telegram_id",
            "BIGINT DEFAULT NULL AFTER `requested_by_user_id`",
        ),
        ("created_by_user_id", "INT DEFAULT NULL AFTER `requested_by_telegram_id`"),
        ("telegram_user_id", "BIGINT DEFAULT NULL AFTER `created_by_user_id`"),
        ("scenario_id", "VARCHAR(96) DEFAULT NULL AFTER `telegram_user_id`"),
        ("picked_at", "DATETIME DEFAULT NULL AFTER `started_at`"),
        ("duration_ms", "INT DEFAULT NULL AFTER `finished_at`"),
        ("attempts", "INT NOT NULL DEFAULT 0 AFTER `error_message`"),
        ("cancelled_at", "DATETIME DEFAULT NULL AFTER `attempts`"),
        ("cancelled_by_user_id", "INT DEFAULT NULL AFTER `cancelled_at`"),
        ("result_notified_at", "DATETIME DEFAULT NULL AFTER `error_message`"),
    ):
        await _add_column(database, "device_commands", column, definition)
    source_type = await _column_type(database, "device_commands", "source")
    required_sources = ("web", "site", "telegram", "automation", "desktop", "system")
    if not all(f"'{source}'" in source_type for source in required_sources):
        await database.execute(
            "ALTER TABLE device_commands MODIFY COLUMN source "
            "ENUM('web','site','telegram','automation','desktop','system') "
            "NOT NULL DEFAULT 'site'"
        )
    await _add_index(
        database,
        "device_commands",
        "idx_device_commands_source_created",
        "`user_id`, `device_id`, `source`, `created_at`",
    )
    await _add_index(
        database,
        "device_commands",
        "idx_device_commands_telegram_notify",
        "`source`, `requested_by_telegram_id`, `status`, `result_notified_at`",
    )
    for column, definition in (
        (
            "photo_alert_enabled",
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER `analysis_alert_enabled`",
        ),
        (
            "system_alert_enabled",
            "TINYINT(1) NOT NULL DEFAULT 1 AFTER `photo_alert_enabled`",
        ),
        (
            "offline_after_minutes",
            "INT NOT NULL DEFAULT 120 AFTER `quiet_hours_end`",
        ),
        (
            "alert_cooldown_minutes",
            "INT NOT NULL DEFAULT 180 AFTER `offline_after_minutes`",
        ),
        (
            "last_daily_report_date",
            "DATE DEFAULT NULL AFTER `alert_cooldown_minutes`",
        ),
    ):
        await _add_column(database, "telegram_settings", column, definition)
    await _add_column(
        database,
        "telegram_alert_state",
        "last_alert_value",
        "VARCHAR(120) DEFAULT NULL AFTER `last_sent_at`",
    )
    await _add_column(
        database,
        "event_log",
        "device_id",
        "VARCHAR(64) DEFAULT NULL AFTER `user_id`",
    )
    await _add_index(
        database,
        "event_log",
        "idx_event_user_device_created",
        "`user_id`, `device_id`, `created_at`",
    )
    await normalize_project_collations(database)
    LOGGER.info("Telegram database compatibility migration completed")


async def _run_manual_migration() -> None:
    database = Database(load_settings())
    try:
        await database.connect()
        await migrate(database)
    finally:
        await database.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Apply Telegram database migrations manually")
    parser.add_argument("--backup-verified", action="store_true")
    args = parser.parse_args()
    if not args.backup_verified:
        parser.error("Verify a restorable database backup, then pass --backup-verified")
    logging.basicConfig(level=logging.INFO)
    asyncio.run(_run_manual_migration())
