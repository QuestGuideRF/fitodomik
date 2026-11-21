from __future__ import annotations
import logging
import time
from contextlib import contextmanager
from logging.handlers import RotatingFileHandler
from typing import Iterator
from .paths import LOGS_DIR, ensure_runtime_directories
_LOGGER_NAME = "fitodomik.performance"
def performance_logger() -> logging.Logger:
    ensure_runtime_directories()
    logger = logging.getLogger(_LOGGER_NAME)
    logger.setLevel(logging.INFO)
    logger.propagate = False
    if logger.handlers:
        return logger
    handler = RotatingFileHandler(
        LOGS_DIR / "performance.log",
        maxBytes=500_000,
        backupCount=3,
        encoding="utf-8",
    )
    handler.setFormatter(logging.Formatter("%(message)s"))
    handler.setLevel(logging.INFO)
    logger.addHandler(handler)
    return logger
def log_perf(label: str, seconds: float) -> None:
    performance_logger().info("[PERF] %s: %.2fs", label, max(0.0, seconds))
@contextmanager
def measure_perf(label: str) -> Iterator[None]:
    started = time.perf_counter()
    try:
        yield
    finally:
        log_perf(label, time.perf_counter() - started)