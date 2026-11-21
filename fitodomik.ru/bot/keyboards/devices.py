from __future__ import annotations
from typing import Any
from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
def devices_keyboard(
    devices: list[dict[str, Any]], selected_id: str = "", mode: str = "select"
) -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for device in devices:
        mark = " [активно]" if str(device["device_id"]) == selected_id else ""
        rows.append(
            [
                InlineKeyboardButton(
                    text=str(device["device_name"])[:48] + mark,
                    callback_data=f"device:{mode}:{int(device['id'])}",
                )
            ]
        )
    return InlineKeyboardMarkup(inline_keyboard=rows)