from __future__ import annotations
import asyncio
import logging
import re
from collections.abc import Awaitable, Callable
from typing import Any
from aiogram import Bot
from bot.repositories.commands import COMMAND_LABELS
from bot.services.container import Services
from bot.utils.formatting import plain
from bot.utils.security import ProcessLock
LOGGER = logging.getLogger(__name__)
SECRET_RE = re.compile(
    r"(?i)\b(token|password|secret|api[_ -]?key|device[_ -]?token)\b"
    r"\s*[:=]\s*\S+|\b\d{6,12}:[A-Za-z0-9_-]{30,}\b|\b[a-f0-9]{32,}\b"
)
def safe_result(value: Any) -> str:
    return SECRET_RE.sub(
        lambda match: (match.group(1) or "secret") + "=***", plain(value, 1200)
    )
class Scheduler:
    def __init__(self, services: Services, process_lock: ProcessLock) -> None:
        self.services = services
        self.process_lock = process_lock
        self._tasks: list[asyncio.Task[None]] = []
    def start(self, bot: Bot) -> None:
        if self._tasks:
            return
        self._tasks = [
            asyncio.create_task(
                self._loop(
                    "command-results",
                    self.services.settings.command_results_interval,
                    lambda: self._command_results(bot),
                )
            ),
            asyncio.create_task(
                self._loop(
                    "alerts-and-reports",
                    self.services.settings.alerts_interval,
                    lambda: self.services.alert.process_all(bot, self.services.report),
                )
            ),
            asyncio.create_task(
                self._loop(
                    "storage-cleanup",
                    self.services.settings.cleanup_interval,
                    self._cleanup,
                )
            ),
            asyncio.create_task(self._heartbeat_loop()),
        ]
    async def stop(self) -> None:
        tasks, self._tasks = self._tasks, []
        for task in tasks:
            task.cancel()
        if tasks:
            await asyncio.gather(*tasks, return_exceptions=True)
    async def _loop(
        self, name: str, interval: int, operation: Callable[[], Awaitable[None]]
    ) -> None:
        while True:
            try:
                await operation()
            except asyncio.CancelledError:
                raise
            except Exception:
                LOGGER.exception("Scheduled task %s failed", name)
            await asyncio.sleep(interval)
    async def _heartbeat_loop(self) -> None:
        while True:
            try:
                self.process_lock.heartbeat()
            except asyncio.CancelledError:
                raise
            except Exception:
                LOGGER.exception("Could not update Telegram bot heartbeat")
            await asyncio.sleep(self.services.settings.heartbeat_interval)
    async def _cleanup(self) -> None:
        await self.services.commands.expire_old()
        await self.services.telegram.cleanup()
    async def _command_results(self, bot: Bot) -> None:
        for row in await self.services.commands.completed_for_notification():
            try:
                await self._send_command_result(bot, row)
                await self.services.commands.mark_notified(row)
            except Exception:
                LOGGER.exception(
                    "Could not notify command result id=%s", int(row["id"])
                )
    async def _send_command_result(self, bot: Bot, row: dict[str, Any]) -> None:
        status = {
            "done": "выполнена",
            "error": "завершилась с ошибкой",
            "cancelled": "отменена",
            "expired": "истекла до выполнения",
        }.get(str(row.get("status")), "завершена")
        command = str(row.get("command_type") or "")
        details = safe_result(
            row.get("result_message") or row.get("error_message") or "нет сообщения"
        )
        await bot.send_message(
            str(row["telegram_chat_id"]),
            "\n".join(
                (
                    f"Результат команды #{int(row['id'])}",
                    "",
                    f"Устройство: {plain(row.get('device_name') or row.get('device_id'), 120)}",
                    f"Команда: {COMMAND_LABELS.get(command, command)}",
                    f"Статус: {status}",
                    f"Сообщение: {details}",
                )
            ),
        )
        if str(row.get("status")) != "done":
            return
        photo_file = None
        if command in {"take_photo", "analyze_plant"}:
            latest = await self.services.photo.latest(
                int(row["user_id"]), str(row["device_id"])
            )
            if latest:
                photo_file = self.services.photo.command_result_photo(command, latest)
        if photo_file:
            try:
                await bot.send_photo(
                    str(row["telegram_chat_id"]),
                    photo=photo_file,
                    caption=f"Результат команды #{int(row['id'])}",
                )
            except Exception:
                LOGGER.exception(
                    "Command result text sent, but photo delivery failed id=%s",
                    int(row["id"]),
                )
