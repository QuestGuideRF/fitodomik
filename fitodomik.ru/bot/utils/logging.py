from __future__ import annotations
import logging
from logging.handlers import RotatingFileHandler
from pathlib import Path
class SecretRedactionFilter(logging.Filter):
    def __init__(self, secrets: list[str]) -> None:
        super().__init__()
        self._secrets = [secret for secret in secrets if len(secret) >= 6]
    def filter(self, record: logging.LogRecord) -> bool:
        message = record.getMessage()
        for secret in self._secrets:
            message = message.replace(secret, "***")
        record.msg = message
        record.args = ()
        return True
class SecretRedactingFormatter(logging.Formatter):
    def __init__(self, fmt: str, secrets: list[str]) -> None:
        super().__init__(fmt)
        self._secrets = [secret for secret in secrets if len(secret) >= 6]
    def format(self, record: logging.LogRecord) -> str:
        rendered = super().format(record)
        for secret in self._secrets:
            rendered = rendered.replace(secret, "***")
        return rendered
def configure_logging(log_dir: Path, level: str, secrets: list[str]) -> Path:
    log_dir.mkdir(mode=0o750, parents=True, exist_ok=True)
    log_path = log_dir / "telegram_bot.log"
    formatter = SecretRedactingFormatter(
        "%(asctime)s %(levelname)s %(name)s [pid:%(process)d] %(message)s",
        secrets,
    )
    redaction = SecretRedactionFilter(secrets)
    file_handler = RotatingFileHandler(
        log_path,
        maxBytes=5 * 1024 * 1024,
        backupCount=5,
        encoding="utf-8",
    )
    file_handler.setFormatter(formatter)
    file_handler.addFilter(redaction)
    console_handler = logging.StreamHandler()
    console_handler.setFormatter(formatter)
    console_handler.addFilter(redaction)
    root = logging.getLogger()
    root.handlers.clear()
    root.setLevel(getattr(logging, level, logging.INFO))
    root.addHandler(file_handler)
    root.addHandler(console_handler)
    logging.getLogger("aiogram.event").setLevel(logging.INFO)
    logging.getLogger("asyncio").setLevel(logging.WARNING)
    return log_path