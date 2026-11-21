from __future__ import annotations
import json
import shutil
import threading
from datetime import datetime
from pathlib import Path
from typing import Any
from fitodomik_app.core.paths import DATA_DIR
class NotificationCenter:
    def __init__(self, path: Path | None = None, max_items: int = 1000) -> None:
        self.path = path or DATA_DIR / "notifications.json"
        self.max_items = max_items
        self._lock = threading.RLock()
        self.path.parent.mkdir(parents=True, exist_ok=True)
    def add(
        self,
        title: str,
        message: str,
        *,
        event_type: str = "info",
        level: str = "info",
        extra: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        item = {
            "time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "type": event_type,
            "level": level,
            "title": title,
            "message": message,
        }
        if extra:
            item.update(extra)
        with self._lock:
            items = self.list()
            items.append(item)
            items = items[-self.max_items :]
            self._write(items)
        return item
    def list(self) -> list[dict[str, Any]]:
        with self._lock:
            return self._read()
    def clear(self) -> None:
        with self._lock:
            self._write([])
    def count_today(self, event_type: str | None = None, level: str | None = None) -> int:
        today = datetime.now().strftime("%Y-%m-%d")
        total = 0
        for item in self.list():
            if not str(item.get("time", "")).startswith(today):
                continue
            if event_type and item.get("type") != event_type:
                continue
            if level and item.get("level") != level:
                continue
            total += 1
        return total
    def _read(self) -> list[dict[str, Any]]:
        if not self.path.exists():
            return []
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            return data if isinstance(data, list) else []
        except Exception:
            backup = self.path.with_suffix(f".broken_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json")
            try:
                shutil.copy2(self.path, backup)
            except Exception:
                pass
            return []
    def _write(self, items: list[dict[str, Any]]) -> None:
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(items, ensure_ascii=False, indent=2), encoding="utf-8")
        tmp.replace(self.path)