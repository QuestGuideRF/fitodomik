from urllib.parse import urlencode
from aiogram import F, Router
from aiogram.filters import Command
from aiogram.types import (
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)
from bot.handlers.common import linked_callback, linked_message
from bot.keyboards.main_menu import unlinked_menu
from bot.services.container import Services
router = Router(name=__name__)
@router.message(Command("login"))
async def login(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    token = await services.links.create_login_token(
        int(user["id"]), str(message.chat.id)
    )
    url = (
        services.settings.site_url
        + "/authentication/telegram_login.php?"
        + urlencode({"token": token})
    )
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[[InlineKeyboardButton(text="Войти в ФитоДомик", url=url)]]
    )
    await message.answer(
        "Одноразовая ссылка для входа действует 10 минут.", reply_markup=keyboard
    )
@router.message(Command("unlink"))
async def unlink_command(message: Message, services: Services) -> None:
    user = await linked_message(message, services)
    if user is None:
        return
    if (message.text or "").strip().lower() == "/unlink confirm":
        await services.telegram.unlink(int(user["id"]))
        await message.answer(
            "Telegram отвязан от профиля.", reply_markup=unlinked_menu()
        )
        return
    keyboard = InlineKeyboardMarkup(
        inline_keyboard=[
            [
                InlineKeyboardButton(text="Отвязать", callback_data="account:unlink"),
                InlineKeyboardButton(text="Отмена", callback_data="account:cancel"),
            ]
        ]
    )
    await message.answer(
        "Отвязать Telegram? Доступ к данным устройств будет закрыт, аккаунт сайта не удалится.",
        reply_markup=keyboard,
    )
@router.callback_query(F.data.in_({"account:unlink", "account:cancel"}))
async def unlink_callback(callback: CallbackQuery, services: Services) -> None:
    user = await linked_callback(callback, services)
    if user is None:
        return
    if callback.data == "account:cancel":
        await callback.answer("Отмена")
        if isinstance(callback.message, Message):
            await callback.message.edit_text("Отвязка отменена.")
        return
    await services.telegram.unlink(int(user["id"]))
    await callback.answer("Telegram отвязан")
    if isinstance(callback.message, Message):
        await callback.message.answer(
            "Telegram отвязан от профиля.", reply_markup=unlinked_menu()
        )
        await callback.message.delete()