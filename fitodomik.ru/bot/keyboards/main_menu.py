from aiogram.types import KeyboardButton, ReplyKeyboardMarkup
def main_menu() -> ReplyKeyboardMarkup:
    labels = (
        ("Статус", "Фото"),
        ("Устройства", "Пороги"),
        ("Расписание", "События"),
        ("Настройки", "Помощь"),
    )
    return ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=value) for value in row] for row in labels],
        resize_keyboard=True,
        is_persistent=True,
        input_field_placeholder="Выберите действие",
    )
def unlinked_menu() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="Привязать Telegram")],
            [KeyboardButton(text="Помощь")],
        ],
        resize_keyboard=True,
        is_persistent=True,
    )
