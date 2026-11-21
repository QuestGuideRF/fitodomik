from __future__ import annotations
import re
from dataclasses import dataclass
from enum import Enum
from typing import Collection, Optional, Sequence
_IDENTIFIER_RE = re.compile(r"^[A-Za-z_]\w*$", re.ASCII)
ALTER_TABLE = "ALTER TABLE"
class SortDirection(str, Enum):
    ASC = "ASC"
    DESC = "DESC"
@dataclass(frozen=True)
class ColumnDefinition:
    type_sql: str
    nullable: bool = True
    default_sql: Optional[str] = None
    after: Optional[str] = None
    comment_parameter: bool = False
def _require_allowed(value: str, allowed: Collection[str], label: str) -> str:
    if value not in allowed:
        raise ValueError("Unapproved SQL " + label)
    return value
def quote_identifier(identifier: str, allowed: Collection[str]) -> str:
    if not _IDENTIFIER_RE.fullmatch(identifier) or identifier not in allowed:
        raise ValueError("Unapproved SQL identifier")
    return "`" + identifier + "`"
def _definition_tokens(
    definition: ColumnDefinition,
    *,
    allowed_types: Collection[str],
    allowed_defaults: Collection[str],
    allowed_columns: Collection[str],
) -> list[str]:
    type_sql = _require_allowed(definition.type_sql, allowed_types, "type")
    tokens = [type_sql, "NULL" if definition.nullable else "NOT NULL"]
    if definition.default_sql is not None:
        tokens.append(
            _require_allowed(definition.default_sql, allowed_defaults, "default")
        )
    if definition.after is not None:
        tokens.extend(("AFTER", quote_identifier(definition.after, allowed_columns)))
    if definition.comment_parameter:
        tokens.extend(("COMMENT", "%s"))
    return tokens
def build_alter_column_sql(
    table: str,
    column: str,
    definition: ColumnDefinition,
    *,
    charset: str,
    collation: str,
    allowed_tables: Collection[str],
    allowed_columns: Collection[str],
    allowed_types: Collection[str],
    allowed_defaults: Collection[str],
    allowed_charsets: Collection[str],
    allowed_collations: Collection[str],
) -> str:
    tokens = [
        ALTER_TABLE,
        quote_identifier(table, allowed_tables),
        "MODIFY COLUMN",
        quote_identifier(column, allowed_columns),
        _require_allowed(definition.type_sql, allowed_types, "type"),
        "CHARACTER SET",
        _require_allowed(charset, allowed_charsets, "character set"),
        "COLLATE",
        _require_allowed(collation, allowed_collations, "collation"),
        "NULL" if definition.nullable else "NOT NULL",
    ]
    if definition.default_sql is not None:
        tokens.append(
            _require_allowed(definition.default_sql, allowed_defaults, "default")
        )
    if definition.comment_parameter:
        tokens.extend(("COMMENT", "%s"))
    return " ".join(tokens)
def build_add_column_sql(
    table: str,
    column: str,
    definition: ColumnDefinition,
    *,
    allowed_tables: Collection[str],
    allowed_columns: Collection[str],
    allowed_types: Collection[str],
    allowed_defaults: Collection[str],
) -> str:
    tokens = [
        ALTER_TABLE,
        quote_identifier(table, allowed_tables),
        "ADD COLUMN",
        quote_identifier(column, allowed_columns),
    ]
    tokens.extend(
        _definition_tokens(
            definition,
            allowed_types=allowed_types,
            allowed_defaults=allowed_defaults,
            allowed_columns=allowed_columns,
        )
    )
    return " ".join(tokens)
def build_create_index_sql(
    table: str,
    index: str,
    columns: Sequence[str],
    *,
    unique: bool,
    allowed_tables: Collection[str],
    allowed_indexes: Collection[str],
    allowed_columns: Collection[str],
) -> str:
    if not columns:
        raise ValueError("Index requires at least one column")
    quoted_columns = ", ".join(
        quote_identifier(column, allowed_columns) for column in columns
    )
    tokens = [
        "CREATE UNIQUE INDEX" if unique else "CREATE INDEX",
        quote_identifier(index, allowed_indexes),
        "ON",
        quote_identifier(table, allowed_tables),
        "(" + quoted_columns + ")",
    ]
    return " ".join(tokens)
def build_drop_index_sql(
    table: str,
    index: str,
    *,
    allowed_tables: Collection[str],
    allowed_indexes: Collection[str],
) -> str:
    return " ".join(
        (
            ALTER_TABLE,
            quote_identifier(table, allowed_tables),
            "DROP INDEX",
            quote_identifier(index, allowed_indexes),
        )
    )
def build_table_default_sql(
    table: str,
    *,
    charset: str,
    collation: str,
    allowed_tables: Collection[str],
    allowed_charsets: Collection[str],
    allowed_collations: Collection[str],
) -> str:
    return " ".join(
        (
            ALTER_TABLE,
            quote_identifier(table, allowed_tables),
            "DEFAULT CHARACTER SET",
            _require_allowed(charset, allowed_charsets, "character set"),
            "COLLATE",
            _require_allowed(collation, allowed_collations, "collation"),
        )
    )
def build_select_dynamic_columns_sql(
    table: str,
    columns: Sequence[str],
    *,
    where_clause: str,
    allowed_tables: Collection[str],
    allowed_columns: Collection[str],
    allowed_where_clauses: Collection[str],
    order_by: Optional[str] = None,
    direction: SortDirection = SortDirection.ASC,
    with_limit: bool = False,
) -> str:
    if not columns:
        raise ValueError("SELECT requires at least one column")
    selected = ", ".join(
        quote_identifier(column, allowed_columns) for column in columns
    )
    tokens = [
        "SELECT",
        selected,
        "FROM",
        quote_identifier(table, allowed_tables),
        "WHERE",
        _require_allowed(where_clause, allowed_where_clauses, "filter"),
    ]
    if order_by is not None:
        if not isinstance(direction, SortDirection):
            raise ValueError("Unapproved SQL sort direction")
        tokens.extend(
            (
                "ORDER BY",
                quote_identifier(order_by, allowed_columns),
                direction.value,
            )
        )
    if with_limit:
        tokens.extend(("LIMIT", "%s"))
    return " ".join(tokens)
