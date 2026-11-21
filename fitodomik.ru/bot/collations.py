from __future__ import annotations
import logging
from types import MappingProxyType
from typing import Any, Dict, List, Mapping, Optional, Sequence, Tuple
from bot.database import Database
from bot.sql_utils import (
    ColumnDefinition,
    build_alter_column_sql,
    build_table_default_sql,
)
LOGGER = logging.getLogger(__name__)
VARCHAR_64 = "VARCHAR(64)"
VARCHAR_80 = "VARCHAR(80)"
VARCHAR_255 = "VARCHAR(255)"
DEFAULT_NULL = "DEFAULT NULL"
UTF8MB4 = "utf8mb4"
UTF8MB4_UNICODE_CI = "utf8mb4_unicode_ci"
TARGET_CHARSET = UTF8MB4
TARGET_COLLATION = UTF8MB4_UNICODE_CI
def _column(type_sql: str, nullable: bool) -> ColumnDefinition:
    return ColumnDefinition(
        type_sql=type_sql,
        nullable=nullable,
        default_sql=DEFAULT_NULL if nullable else None,
        comment_parameter=True,
    )
COLLATION_COLUMN_DEFINITIONS: Mapping[
    Tuple[str, str], ColumnDefinition
] = MappingProxyType(
    {
        ("telegram_links", "telegram_chat_id"): _column(VARCHAR_64, False),
        ("telegram_sessions", "selected_device_id"): _column(VARCHAR_64, True),
        ("telegram_settings", "device_id"): _column(VARCHAR_64, True),
        ("telegram_alert_state", "device_id"): _column(VARCHAR_64, False),
        ("telegram_alert_state", "alert_key"): _column(VARCHAR_80, False),
        ("device_commands", "device_id"): _column(VARCHAR_64, False),
        ("devices", "device_id"): _column(VARCHAR_64, False),
        ("user_devices", "device_id"): _column(VARCHAR_64, False),
        ("user_telegram_settings", "telegram_chat_id"): _column(VARCHAR_64, True),
        ("device_notification_settings", "device_id"): _column(VARCHAR_64, False),
        ("users", "telegram_chat_id"): _column(VARCHAR_255, True),
        ("telegram_link_codes", "telegram_chat_id"): _column(VARCHAR_64, True),
        ("telegram_callback_actions", "device_id"): _column(VARCHAR_64, False),
        ("event_log", "device_id"): _column(VARCHAR_64, True),
        ("sensor_data", "device_id"): _column(VARCHAR_64, True),
        ("farm_status", "device_id"): _column(VARCHAR_64, True),
        ("device_sensor_definitions", "device_id"): _column(VARCHAR_64, False),
        ("device_sensor_values", "device_id"): _column(VARCHAR_64, False),
        ("device_relay_definitions", "device_id"): _column(VARCHAR_64, False),
    }
)
COLLATION_TARGETS: Sequence[Tuple[str, str]] = tuple(
    COLLATION_COLUMN_DEFINITIONS
)
COLLATION_TABLES = frozenset(table for table, _ in COLLATION_TARGETS)
COLLATION_COLUMNS = frozenset(column for _, column in COLLATION_TARGETS)
COLLATION_TYPES = frozenset((VARCHAR_64, VARCHAR_80, VARCHAR_255))
COLLATION_DEFAULTS = frozenset((DEFAULT_NULL,))
COLLATION_CHARSETS = frozenset((UTF8MB4,))
COLLATION_NAMES = frozenset((UTF8MB4_UNICODE_CI,))
_ALTER_COLUMN_STATEMENTS: Mapping[Tuple[str, str], str] = MappingProxyType(
    {
        (table, column): build_alter_column_sql(
            table,
            column,
            definition,
            charset=UTF8MB4,
            collation=UTF8MB4_UNICODE_CI,
            allowed_tables=COLLATION_TABLES,
            allowed_columns=COLLATION_COLUMNS,
            allowed_types=COLLATION_TYPES,
            allowed_defaults=COLLATION_DEFAULTS,
            allowed_charsets=COLLATION_CHARSETS,
            allowed_collations=COLLATION_NAMES,
        )
        for (table, column), definition in COLLATION_COLUMN_DEFINITIONS.items()
    }
)
_TABLE_DEFAULT_STATEMENTS: Mapping[str, str] = MappingProxyType(
    {
        table: build_table_default_sql(
            table,
            charset=UTF8MB4,
            collation=UTF8MB4_UNICODE_CI,
            allowed_tables=COLLATION_TABLES,
            allowed_charsets=COLLATION_CHARSETS,
            allowed_collations=COLLATION_NAMES,
        )
        for table in COLLATION_TABLES
    }
)
async def _column_metadata(
    database: Database, table: str, column: str
) -> Optional[Dict[str, Any]]:
    return await database.fetch_one(
        """
        SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,
               IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_COMMENT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s
          AND CHARACTER_SET_NAME IS NOT NULL
        LIMIT 1
        """,
        (table, column),
    )
def _validate_expected_column(
    table: str,
    column: str,
    metadata: Dict[str, Any],
    definition: ColumnDefinition,
) -> None:
    actual = (
        str(metadata.get("COLUMN_TYPE") or "").lower(),
        str(metadata.get("IS_NULLABLE") or "") == "YES",
        metadata.get("COLUMN_DEFAULT"),
        str(metadata.get("EXTRA") or "").replace("DEFAULT_GENERATED", "").strip(),
    )
    expected = (definition.type_sql.lower(), definition.nullable, None, "")
    if actual != expected:
        raise RuntimeError(
            "Refusing to alter unexpected schema for {}.{}; preserve its attributes manually".format(
                table, column
            )
        )
async def _normalize_column(
    database: Database,
    table: str,
    column: str,
    metadata: Dict[str, Any],
) -> Optional[str]:
    charset = str(metadata.get("CHARACTER_SET_NAME") or "").lower()
    collation = str(metadata.get("COLLATION_NAME") or "").lower()
    LOGGER.debug(
        "MySQL collation check %s.%s: %s/%s",
        table,
        column,
        charset,
        collation,
    )
    if charset == UTF8MB4 and collation == UTF8MB4_UNICODE_CI:
        return None
    definition = COLLATION_COLUMN_DEFINITIONS[(table, column)]
    _validate_expected_column(table, column, metadata, definition)
    query = _ALTER_COLUMN_STATEMENTS[(table, column)]
    await database.execute(query, (str(metadata.get("COLUMN_COMMENT") or ""),))
    change = "{}.{} {}/{} -> {}/{}".format(
        table,
        column,
        charset or "none",
        collation or "none",
        UTF8MB4,
        UTF8MB4_UNICODE_CI,
    )
    LOGGER.info("MySQL collation normalized: %s", change)
    return change
async def _normalize_table_default(database: Database, table: str) -> None:
    table_collation = await database.fetch_value(
        """
        SELECT TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s LIMIT 1
        """,
        (table,),
    )
    if str(table_collation or "").lower() == UTF8MB4_UNICODE_CI:
        return
    query = _TABLE_DEFAULT_STATEMENTS[table]
    await database.execute(query)
    LOGGER.info(
        "MySQL table default normalized: %s %s -> %s",
        table,
        table_collation or "none",
        UTF8MB4_UNICODE_CI,
    )
async def normalize_project_collations(database: Database) -> List[str]:
    changed: List[str] = []
    existing_tables = set()
    for table, column in COLLATION_TARGETS:
        metadata = await _column_metadata(database, table, column)
        if metadata is None:
            continue
        existing_tables.add(table)
        change = await _normalize_column(database, table, column, metadata)
        if change is not None:
            changed.append(change)
    for table in sorted(existing_tables):
        await _normalize_table_default(database, table)
    if changed:
        LOGGER.info("MySQL collation migration changed %d column(s)", len(changed))
    else:
        LOGGER.info("MySQL identifier collations are already compatible")
    return changed