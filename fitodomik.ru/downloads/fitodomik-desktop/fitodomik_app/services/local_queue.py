from __future__ import annotations
import json
import os
import shutil
import threading
import uuid
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable
from fitodomik_app.core.models import SensorSnapshot
from fitodomik_app.core.paths import DATA_DIR
class QueueCorruptError(RuntimeError):
    pass
class JsonQueue:
    def __init__(self, path: Path, max_records: int = 10000) -> None:
        self.path = path
        self.max_records = max_records
        self._lock = threading.RLock()
        self.path.parent.mkdir(parents=True, exist_ok=True)
    def add(self, record: dict[str, Any]) -> dict[str, Any]:
        with self._lock:
            items = self._read()
            items.append(record)
            self._write(self._trim(items))
        return record
    def all(self) -> list[dict[str, Any]]:
        with self._lock:
            return self._read()
    def unsynced(self, keys: Iterable[str] = ("synced",)) -> list[dict[str, Any]]:
        key_list = list(keys)
        return [item for item in self.all() if any(not item.get(key, False) for key in key_list)]
    def update_all(self, items: list[dict[str, Any]]) -> None:
        with self._lock:
            self._write(self._trim(items))
    def update_records(self, items: list[dict[str, Any]]) -> None:
        """Merge a sync snapshot without dropping records added during network I/O."""
        self._merge_records(items, ())
    def update_and_remove_synced(self, items: list[dict[str, Any]], keys: Iterable[str] = ("synced",)) -> int:
        """Commit acknowledgements and cleanup with one read/atomic replacement."""
        return self._merge_records(items, tuple(keys))
    def _merge_records(self, items: list[dict[str, Any]], keys: tuple[str, ...]) -> int:
        updates = {item["id"]: item for item in items}
        with self._lock:
            current = self._read()
            changed = False
            for record in current:
                update = updates.get(record["id"])
                if update is not None and any(key not in record or record[key] != value for key, value in update.items()):
                    record.update(update)
                    changed = True
            kept = [record for record in current if not keys or not all(record.get(key, False) for key in keys)]
            removed = len(current) - len(kept)
            if changed or removed:
                self._write(self._trim(kept))
            return removed
    def remove_synced(self, keys: Iterable[str] = ("synced",)) -> int:
        key_list = list(keys)
        with self._lock:
            items = self._read()
            kept = [item for item in items if not all(item.get(key, False) for key in key_list)]
            removed = len(items) - len(kept)
            if removed:
                self._write(self._trim(kept))
            return removed
    def _trim(self, items: list[dict[str, Any]]) -> list[dict[str, Any]]:
        # Never evict data which has not been acknowledged by the server.
        return items
    def _read(self) -> list[dict[str, Any]]:
        if not self.path.exists():
            return []
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
            if not isinstance(data, list) or any(not isinstance(item, dict) for item in data):
                raise ValueError("Queue must contain JSON objects")
            return data
        except Exception as exc:
            backup = self.path.with_suffix(f".broken_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json")
            try:
                shutil.copy2(self.path, backup)
            except Exception:
                pass
            raise QueueCorruptError(f"Local queue is unreadable; backup: {backup}") from exc
    def _write(self, items: list[dict[str, Any]]) -> None:
        tmp = self.path.with_suffix(".tmp")
        with tmp.open("w", encoding="utf-8") as stream:
            json.dump(items, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        tmp.replace(self.path)
class LocalSensorQueue(JsonQueue):
    def __init__(self, max_records: int = 10000) -> None:
        super().__init__(DATA_DIR / "local_sensor_queue.json", max_records=max_records)
    def add_snapshot(self, snapshot: SensorSnapshot, device_id: str) -> dict[str, Any]:
        record = {
            "id": str(uuid.uuid4()),
            "device_id": device_id,
            "timestamp": snapshot.timestamp.strftime("%Y-%m-%d %H:%M:%S"),
            "temperature": snapshot.temperature,
            "air_humidity": snapshot.humidity,
            "soil_moisture": snapshot.soil_moisture,
            "light": snapshot.light_level,
            "co2": snapshot.co2,
            "pressure": snapshot.pressure,
            "lamp_state": snapshot.lamp_state,
            "curtains_state": snapshot.curtains_state,
            "pump_state": snapshot.pump_state,
            "fan_state": snapshot.fan_state,
            "synced": False,
        }
        return self.add(record)
class LocalDynamicSensorQueue(JsonQueue):
    def __init__(self, max_records: int = 10000) -> None:
        super().__init__(DATA_DIR / "local_dynamic_sensor_queue.json", max_records=max_records)
    def add_values(self, values: list[dict[str, Any]], device_id: str) -> dict[str, Any]:
        record = {
            "id": str(uuid.uuid4()),
            "device_id": device_id,
            "values": values,
            "synced": False,
            "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }
        return self.add(record)
class LocalPhotoQueue(JsonQueue):
    def __init__(self, max_records: int = 2000) -> None:
        super().__init__(DATA_DIR / "local_photo_queue.json", max_records=max_records)
    def add_photo(
        self,
        *,
        device_id: str,
        file_path: Path,
        analysis_path: Path | None = None,
        report_path: Path | None = None,
        send_to_server: bool = True,
    ) -> dict[str, Any]:
        record = {
            "id": str(uuid.uuid4()),
            "device_id": device_id,
            "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "file_path": str(file_path),
            "analysis_path": str(analysis_path) if analysis_path else "",
            "report_path": str(report_path) if report_path else "",
            "send_to_server": bool(send_to_server),
            "server_sent": not send_to_server,
        }
        return self.add(record)
class LocalCommandResultQueue(JsonQueue):
    def __init__(self, max_records: int = 5000) -> None:
        super().__init__(DATA_DIR / "local_command_result_queue.json", max_records=max_records)
    def add_result(
        self,
        *,
        device_id: str,
        command_id: int,
        status: str,
        message: str,
        result_payload: dict[str, Any] | None = None,
        started_at: str = "",
        finished_at: str = "",
    ) -> dict[str, Any]:
        record = {
            "id": str(uuid.uuid4()),
            "device_id": device_id,
            "command_id": int(command_id),
            "status": status,
            "message": message,
            "result_payload": result_payload or {},
            "started_at": started_at,
            "finished_at": finished_at,
            "synced": False,
            "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }
        return self.add(record)
class LocalScenarioLogQueue(JsonQueue):
    def __init__(self, max_records: int = 5000) -> None:
        super().__init__(DATA_DIR / "local_scenario_log_queue.json", max_records=max_records)
    def add_log(
        self,
        *,
        device_id: str,
        scenario_id: str,
        message: str,
        payload: dict[str, Any] | None = None,
        event_type: str = "triggered",
    ) -> dict[str, Any]:
        record = {
            "id": str(uuid.uuid4()),
            "device_id": device_id,
            "scenario_id": scenario_id,
            "event_type": event_type,
            "message": message,
            "payload": payload or {},
            "synced": False,
            "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }
        return self.add(record)
class LocalFaultQueue(JsonQueue):
    def __init__(self) -> None:
        super().__init__(DATA_DIR / "local_fault_queue.json", max_records=10000)
    def add_fault(self, fault_code: str, device_id: str) -> dict[str, Any]:
        return self.add({
            "id": str(uuid.uuid4()), "fault_code": fault_code,
            "device_id": device_id, "created_at": datetime.now().isoformat(),
            "photo_file": "", "synced": False,
            "capture_pending": True, "photo_path": "",
        })
