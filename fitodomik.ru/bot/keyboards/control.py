from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
def control_keyboard() -> InlineKeyboardMarkup:
    rows = []
    for relay, label in (
        (1, "Вентилятор"),
        (2, "Насос"),
        (3, "Шторы"),
        (4, "Лампа"),
    ):
        rows.append(
            [
                InlineKeyboardButton(
                    text=f"{label}: вкл", callback_data=f"control:on:{relay}"
                ),
                InlineKeyboardButton(
                    text=f"{label}: выкл", callback_data=f"control:off:{relay}"
                ),
            ]
        )
    rows.extend(
        (
            [
                InlineKeyboardButton(text="Тест лампы", callback_data="test:lamp"),
                InlineKeyboardButton(text="Тест вентилятора", callback_data="test:fan"),
            ],
            [
                InlineKeyboardButton(text="Тест насоса", callback_data="test:pump"),
                InlineKeyboardButton(text="Тест штор", callback_data="test:curtains"),
            ],
            [
                InlineKeyboardButton(
                    text="Импульс насоса (3 сек.)", callback_data="control:pulse:2"
                )
            ],
            [
                InlineKeyboardButton(
                    text="Сброс аварии насоса", callback_data="control:pump_reset"
                )
            ],
            [
                InlineKeyboardButton(
                    text="Проверить связь", callback_data="command:ping"
                )
            ],
        )
    )
    return InlineKeyboardMarkup(inline_keyboard=rows)
def confirmation_keyboard(token: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(
                    text="Подтвердить", callback_data=f"confirm:{token}"
                ),
                InlineKeyboardButton(text="Отмена", callback_data=f"cancel:{token}"),
            ]
        ]
    )
def command_keyboard(command: str, label: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        inline_keyboard=[
            [InlineKeyboardButton(text=label, callback_data=f"command:{command}")]
        ]
    )
