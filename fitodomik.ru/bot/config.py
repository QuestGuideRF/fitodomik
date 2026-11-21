from __future__ import annotations
import os
import re
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse
from dotenv import load_dotenv
PROJECT_DIR = Path(__file__).resolve().parent.parent
ENV_FILE = PROJECT_DIR / ".env"
TELEGRAM_TOKEN_RE = re.compile(r"^\d{6,12}:[A-Za-z0-9_-]{30,}$")
TELEGRAM_USERNAME_RE = re.compile(r"^\w{5,32}$", re.ASCII)
class ConfigurationError(RuntimeError):
    pass
def _env(*names: str, default: str = "") -> str:
    for name in names:
        value = os.getenv(name)
        if value is not None and value.strip() != "":
            return value.strip()
    return default
def _positive_int(name: str, default: int, minimum: int = 1) -> int:
    value = _env(name, default=str(default))
    try:
        parsed = int(value)
    except ValueError as error:
        raise ConfigurationError(f"{name} must be an integer") from error
    if parsed < minimum:
        raise ConfigurationError(f"{name} must be at least {minimum}")
    return parsed
@dataclass(frozen=True)
class Settings:
    telegram_token: str
    bot_username: str
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    site_url: str
    project_dir: Path
    runtime_dir: Path
    log_dir: Path
    log_level: str
    command_results_interval: int
    alerts_interval: int
    cleanup_interval: int
    heartbeat_interval: int
def load_settings() -> Settings:
    load_dotenv(ENV_FILE, override=False)
    token = _env("TELEGRAM_BOT_TOKEN")
    username = _env("BOT_USERNAME", "TELEGRAM_BOT_USERNAME").lstrip("@")
    host = _env("DB_HOST")
    name = _env("DB_NAME")
    user = _env("DB_USERNAME", "DB_USER")
    password = _env("DB_PASSWORD", "DB_PASS")
    missing = [
        label
        for label, value in (
            ("TELEGRAM_BOT_TOKEN", token),
            ("BOT_USERNAME/TELEGRAM_BOT_USERNAME", username),
            ("DB_HOST", host),
            ("DB_NAME", name),
            ("DB_USERNAME/DB_USER", user),
            ("DB_PASSWORD/DB_PASS", password),
        )
        if not value
    ]
    if missing:
        raise ConfigurationError("Missing required settings: " + ", ".join(missing))
    if not TELEGRAM_TOKEN_RE.fullmatch(token):
        raise ConfigurationError("TELEGRAM_BOT_TOKEN has an invalid format")
    site_url = _env("SITE_URL", "APP_URL", default="https://fitodomik.ru").rstrip("/")
    parsed_site = urlparse(site_url)
    if parsed_site.scheme != "https" or parsed_site.netloc not in {
        "fitodomik.ru",
        "www.fitodomik.ru",
    }:
        raise ConfigurationError("SITE_URL must be the trusted FitoDomik HTTPS origin")
    site_url = "https://fitodomik.ru"
    if not TELEGRAM_USERNAME_RE.fullmatch(username):
        raise ConfigurationError("TELEGRAM_BOT_USERNAME has an invalid format")
    bot_dir = PROJECT_DIR / "bot"
    return Settings(
        telegram_token=token,
        bot_username=username,
        db_host=host,
        db_port=_positive_int("DB_PORT", 3306),
        db_name=name,
        db_user=user,
        db_password=password,
        site_url=site_url,
        project_dir=PROJECT_DIR,
        runtime_dir=bot_dir / "runtime",
        log_dir=bot_dir / "logs",
        log_level=_env("TELEGRAM_LOG_LEVEL", default="INFO").upper(),
        command_results_interval=_positive_int(
            "TELEGRAM_COMMAND_RESULTS_INTERVAL", 15, 5
        ),
        alerts_interval=_positive_int("TELEGRAM_ALERTS_INTERVAL", 60, 30),
        cleanup_interval=_positive_int("TELEGRAM_CLEANUP_INTERVAL", 3600, 300),
        heartbeat_interval=_positive_int("TELEGRAM_HEARTBEAT_INTERVAL", 20, 5),
    )
