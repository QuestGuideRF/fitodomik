from __future__ import annotations
from datetime import datetime
from decimal import Decimal
from typing import Any
NO_DATA = "Нет данных"
def number(value: Any, digits: int = 1, unit: str = "") -> str:
    if value is None or value == "":
        return NO_DATA
    try:
        rendered = f"{float(value):.{digits}f}".rstrip("0").rstrip(".")
    except (TypeError, ValueError):
        rendered = str(value)
    return rendered + (f" {unit}" if unit else "")
def timestamp(value: Any) -> str:
    if value is None or value == "":
        return NO_DATA
    if isinstance(value, datetime):
        return value.strftime("%d.%m.%Y %H:%M:%S")
    return str(value)
def boolean(value: Any, enabled: str = "включено", disabled: str = "выключено") -> str:
    return enabled if bool(int(value or 0)) else disabled
def plain(value: Any, maximum: int = 500) -> str:
    text = " ".join(str(value or "").replace("\x00", "").split())
    return text[:maximum]
def decimal_float(value: Decimal | float | int | str | None) -> float | None:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None