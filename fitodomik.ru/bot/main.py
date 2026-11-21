from __future__ import annotations
import asyncio
import logging
import os
import sys
# Upstream workaround for CVE-2026-69244, before aiogram imports aiohttp.
# Preserve the pinned aiogram contract while avoiding the affected C parser.
os.environ["AIOHTTP_NO_EXTENSIONS"] = "1"
from aiogram import Bot, Dispatcher
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.types import BotCommand, ErrorEvent
from bot.config import ConfigurationError, load_settings
from bot.database import Database
from bot.handlers import register_routers
from bot.services import build_services
from bot.services.scheduler import Scheduler
from bot.utils.logging import configure_logging
from bot.utils.security import AlreadyRunningError, ProcessLock
LOGGER = logging.getLogger(__name__)
BOT_COMMANDS = [
    BotCommand(command="start", description="Открыть главное меню"),
    BotCommand(command="link", description="Привязать аккаунт одноразовым кодом"),
    BotCommand(command="unlink", description="Отвязать Telegram"),
    BotCommand(command="login", description="Одноразовый вход на сайт"),
    BotCommand(command="status", description="Статус фермы"),
    BotCommand(command="sensors", description="Показания датчиков"),
    BotCommand(command="photo", description="Последнее фото"),
    BotCommand(command="analysis", description="Последний анализ"),
    BotCommand(command="devices", description="Выбрать устройство"),
    BotCommand(command="rename", description="Переименовать устройство"),
    BotCommand(command="control", description="Управление"),
    BotCommand(command="thresholds", description="Пороги"),
    BotCommand(command="schedule", description="Расписание"),
    BotCommand(command="events", description="События"),
    BotCommand(command="diagnostics", description="Диагностика"),
    BotCommand(command="settings", description="Уведомления"),
    BotCommand(command="take_photo", description="Запросить новое фото"),
    BotCommand(command="analyze_now", description="Запустить анализ растения"),
    BotCommand(command="ping_device", description="Проверить связь с устройством"),
    BotCommand(command="reconnect_arduino", description="Переподключить Arduino"),
    BotCommand(command="refresh_thresholds", description="Синхронизировать пороги"),
    BotCommand(command="refresh_schedule", description="Синхронизировать расписание"),
    BotCommand(command="device_test", description="Запустить диагностику устройства"),
    BotCommand(command="notify", description="Настроить уведомления"),
    BotCommand(command="report_time", description="Установить время отчёта"),
    BotCommand(command="offline_after", description="Настроить offline-интервал"),
    BotCommand(command="cooldown", description="Настроить паузу между тревогами"),
    BotCommand(command="cancel", description="Отменить текущий ввод"),
    BotCommand(command="help", description="Справка"),
]
def _register_error_handler(dispatcher: Dispatcher) -> None:
    @dispatcher.error()
    async def handle_error(event: ErrorEvent) -> bool:
        update_id = getattr(event.update, "update_id", "unknown")
        error = event.exception
        LOGGER.error(
            "Unhandled update error update_id=%s",
            update_id,
            exc_info=(type(error), error, error.__traceback__),
        )
        message = getattr(event.update, "message", None)
        if message is not None:
            try:
                await message.answer(
                    "Не удалось обработать запрос. Повторите попытку позже."
                )
            except Exception:
                LOGGER.warning("Could not send safe update error response")
        return True
async def run() -> None:
    settings = load_settings()
    process_lock = ProcessLock(settings.runtime_dir)
    try:
        process_lock.acquire()
    except AlreadyRunningError:
        print("Telegram bot is already running.")
        return
    database: Database | None = None
    bot: Bot | None = None
    scheduler: Scheduler | None = None
    try:
        configure_logging(
            settings.log_dir,
            settings.log_level,
            [settings.telegram_token, settings.db_password],
        )
        process_lock.heartbeat("starting")
        LOGGER.info("FitoDomik Telegram bot starting")
        database = Database(settings)
        bot = Bot(settings.telegram_token)
        await database.connect()
        await database.ping()
        LOGGER.info("MySQL pool connected")
        services = build_services(settings, database)
        dispatcher = Dispatcher(storage=MemoryStorage())
        register_routers(dispatcher)
        _register_error_handler(dispatcher)
        await bot.delete_webhook(drop_pending_updates=False)
        await bot.set_my_commands(BOT_COMMANDS)
        scheduler = Scheduler(services, process_lock)
        scheduler.start(bot)
        process_lock.heartbeat()
        LOGGER.info("Long polling started")
        await dispatcher.start_polling(
            bot,
            services=services,
            allowed_updates=dispatcher.resolve_used_update_types(),
        )
    finally:
        try:
            if scheduler is not None:
                await scheduler.stop()
        finally:
            try:
                if bot is not None:
                    await bot.session.close()
            finally:
                try:
                    if database is not None:
                        await database.close()
                finally:
                    process_lock.release()
                    LOGGER.info("FitoDomik Telegram bot stopped")
def main() -> int:
    try:
        asyncio.run(run())
    except ConfigurationError as error:
        print(f"Configuration error: {error}", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 0
    except Exception:
        logging.getLogger(__name__).exception("Telegram bot stopped unexpectedly")
        return 1
    return 0
if __name__ == "__main__":
    raise SystemExit(main())
