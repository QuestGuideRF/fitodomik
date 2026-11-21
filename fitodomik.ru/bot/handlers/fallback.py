from aiogram import F, Router
from aiogram.filters import Command
from aiogram.fsm.context import FSMContext
from aiogram.types import Message
from bot.keyboards.main_menu import main_menu
router = Router(name=__name__)
@router.message(Command("cancel"))
async def cancel(message: Message, state: FSMContext) -> None:
    await state.clear()
    await message.answer("Ввод отменён.", reply_markup=main_menu())
@router.message(F.chat.type == "private", F.text)
async def unknown(message: Message) -> None:
    if len(message.text or "") > 4096:
        await message.answer("Сообщение слишком большое.")
    else:
        await message.answer("Команда не распознана. Напишите /help.")