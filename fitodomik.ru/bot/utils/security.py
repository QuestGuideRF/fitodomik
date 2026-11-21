from __future__ import annotations
import hashlib
import importlib
import json
import os
import re
import secrets
from pathlib import Path
from typing import Any, Protocol, cast
DEVICE_ID_RE = re.compile(r"^[A-Za-z0-9._:-]{1,64}$")
HEX_CALLBACK_RE = re.compile(r"^[a-f0-9]{32}$")
CONTROL_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
class AlreadyRunningError(RuntimeError):
    pass
class _FcntlApi(Protocol):
    LOCK_EX: int
    LOCK_NB: int
    LOCK_UN: int
    def flock(self, fd: int, operation: int) -> Any:
        ...
def _load_fcntl() -> _FcntlApi:
    return cast(_FcntlApi, importlib.import_module("fcntl"))
def clean_text(value: Any, maximum: int = 4096) -> str:
    text = str(value or "").strip()
    if len(text) > maximum or CONTROL_RE.search(text):
        raise ValueError("Text has an invalid format")
    return text
def validate_device_id(value: Any) -> str:
    device_id = str(value or "").strip()
    if not DEVICE_ID_RE.fullmatch(device_id):
        raise ValueError("Invalid device id")
    return device_id
def new_callback_token() -> tuple[str, str]:
    token = secrets.token_hex(16)
    return token, hashlib.sha256(token.encode("ascii")).hexdigest()
def callback_token_hash(token: str) -> str:
    if not HEX_CALLBACK_RE.fullmatch(token):
        raise ValueError("Invalid callback token")
    return hashlib.sha256(token.encode("ascii")).hexdigest()
def safe_json_object(value: Any) -> dict[str, Any]:
    if isinstance(value, dict):
        return value
    if not isinstance(value, str) or len(value) > 64 * 1024:
        return {}
    try:
        decoded = json.loads(value)
    except (TypeError, ValueError):
        return {}
    return decoded if isinstance(decoded, dict) else {}
class ProcessLock:
    def __init__(self, runtime_dir: Path) -> None:
        self.runtime_dir = runtime_dir.resolve()
        self.lock_path = self.runtime_dir / "telegram_bot.lock"
        self.pid_path = self.runtime_dir / "telegram_bot.pid"
        self.heartbeat_path = self.runtime_dir / "telegram_bot_heartbeat.json"
        self._handle: Any = None
    def acquire(self) -> None:
        self.runtime_dir.mkdir(mode=0o750, parents=True, exist_ok=True)
        handle = self.lock_path.open("a+", encoding="ascii")
        try:
            self._lock_handle(handle)
        except OSError as error:
            handle.close()
            raise AlreadyRunningError("Telegram bot is already running") from error
        self._handle = handle
        self.pid_path.write_text(str(os.getpid()), encoding="ascii")
    def heartbeat(self, status: str = "running") -> None:
        payload = {
            "pid": os.getpid(),
            "status": status,
            "updated_at_unix": int(__import__("time").time()),
        }
        temporary = self.runtime_dir / "telegram_bot_heartbeat.tmp"
        temporary.write_text(
            json.dumps(payload, separators=(",", ":")), encoding="utf-8"
        )
        temporary.replace(self.heartbeat_path)
    def release(self) -> None:
        if self._handle is None:
            return
        try:
            current = self.pid_path.read_text(encoding="ascii").strip()
            if current == str(os.getpid()):
                self.pid_path.write_text("", encoding="ascii")
                self.heartbeat("stopped")
        finally:
            self._unlock_handle(self._handle)
            self._handle.close()
            self._handle = None
    @staticmethod
    def _lock_handle(handle: Any) -> None:
        if os.name == "nt":
            import msvcrt
            handle.seek(0)
            handle.write("0")
            handle.flush()
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
            return
        fcntl_api = _load_fcntl()
        fcntl_api.flock(handle.fileno(), fcntl_api.LOCK_EX | fcntl_api.LOCK_NB)
    @staticmethod
    def _unlock_handle(handle: Any) -> None:
        if os.name == "nt":
            import msvcrt
            handle.seek(0)
            try:
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            except OSError:
                pass
            return
        fcntl_api = _load_fcntl()
        fcntl_api.flock(handle.fileno(), fcntl_api.LOCK_UN)
    def __enter__(self) -> "ProcessLock":
        self.acquire()
        return self
    def __exit__(self, *_: object) -> None:
        self.release()
