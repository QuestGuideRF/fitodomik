"""Start, help and one-time account linking handlers."""
from __future__ import annotations
from aiogram import F, Router
from aiogram.filters import Command, CommandStart
from aiogram.types import Message
from bot.handlers.common import telegram_identity
from bot.keyboards.main_menu import main_menu, unlinked_menu
from bot.services.container import Services
from bot.services.telegram_links import LinkError
router = Router(name=__name__)
LINK_HELP = (
    "Чтобы привязать Telegram, войдите на сайт ФитоДомик, откройте настройки и "
    "нажмите «Привязать Telegram». Одноразовая ссылка действует 15 минут. "
    "Резервный вариант: /link КОД."
)
HELP = """Команды ФитоДомика
/status — статус фермы
/sensors — подключённые датчики
/photo — последнее фото
/analysis — последний анализ растения
/devices — выбрать устройство
/rename — переименовать устройство
/thresholds — пороги
/set_threshold temperature 18 30 — изменить порог
/schedule — расписание
/set_schedule lighting 8 20 on — изменить расписание
/events — последние события
/diagnostics — диагностика
/settings — уведомления
/control — управление устройством
/take_photo — сделать фото
/analyze_now — выполнить анализ
/ping_device — проверить связь
/reconnect_arduino — переподключить Arduino
/refresh_thresholds — обновить пороги
/refresh_schedule — обновить расписание
/upload_thresholds_to_arduino — передать настройки Arduino
/device_test pump — тест устройства
/relay_on 1, /relay_off 1, /relay_pulse 2 3
/notify alerts off
/report_time 20:00
/offline_after 120
/cooldown 180
/login — одноразовый вход на сайт
/unlink — отвязать Telegram"""
async def _try_link(message: Message, services: Services, payload: str) -> bool:
    if message.from_user is None:
        return False
    retry = await services.telegram.consume_rate_limit(
        "telegram-link", str(message.from_user.id)
    )
    if retry:
        await message.answer("Слишком много попыток привязки. Повторите позже.")
        return True
    try:
        user = await services.links.link(
            payload,
            telegram_identity(message.from_user, message.chat.id),
        )
    except LinkError as error:
        await message.answer(str(error), reply_markup=unlinked_menu())
        return True
    device = await services.device.selected(user, int(message.from_user.id))
    text = "✅ Telegram успешно подключён."
    if device:
        text += f"\nАктивное устройство: {device['device_name']}"
    await message.answer(text, reply_markup=main_menu())
    return True
@router.message(CommandStart())
async def start(message: Message, services: Services) -> None:
    text = message.text or "/start"
    payload = services.links.extract_payload(text)
    if payload and await _try_link(message, services, payload):
        return
    if message.from_user is None:
        return
    user = await services.device.linked_user(int(message.from_user.id))
    if user:
        device = await services.device.selected(user, int(message.from_user.id))
        suffix = f"\nАктивное устройство: {device['device_name']}" if device else ""
        await message.answer(
            "ФитоДомик готов к работе." + suffix, reply_markup=main_menu()
        )
    else:
        await message.answer(LINK_HELP, reply_markup=unlinked_menu())
@router.message(Command("link"))
async def link(message: Message, services: Services) -> None:
    payload = services.links.extract_payload(message.text or "")
    if not payload:
        await message.answer(LINK_HELP, reply_markup=unlinked_menu())
        return
    await _try_link(message, services, payload)
@router.message(Command("help"))
@router.message(F.text == "Помощь")
async def help_message(message: Message, services: Services) -> None:
    linked = bool(
        message.from_user
        and await services.device.linked_user(int(message.from_user.id))
    )
    await message.answer(
        HELP if linked else LINK_HELP,
        reply_markup=main_menu() if linked else unlinked_menu(),
    )
@router.message(F.text == "Привязать Telegram")
async def link_button(message: Message) -> None:
    await message.answer(LINK_HELP, reply_markup=unlinked_menu())
