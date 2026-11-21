from __future__ import annotations
import uuid
from typing import Any
from fitodomik_app.core.paths import DATA_DIR
from fitodomik_app.services.local_queue import JsonQueue
class CommandJournal(JsonQueue):
    """Durable intent before effects; uncertain effects are never replayed."""
    def __init__(self, path=None):
        super().__init__(path or DATA_DIR / 'command_execution_journal.json')
    def begin(self, device_id: str, command_id: int) -> tuple[bool, dict[str, Any]]:
        key = f'{device_id}:{command_id}'
        with self._lock:
            records = self._read()
            for record in records:
                if record['id'] == key:
                    return False, record
            record = {'id': key, 'device_id': device_id, 'command_id': command_id, 'state': 'started'}
            records.append(record)
            self._write(records)
            return True, record
    def complete(self, record: dict[str, Any], result: dict[str, Any]) -> None:
        record.update(state='completed', result=result)
        self.update_records([record])
    def client_id(self) -> str:
        with self._lock:
            records = self._read()
            for record in records:
                if record['id'] == '_client':
                    return str(record['value'])
            value = str(uuid.uuid4())
            records.append({'id': '_client', 'value': value})
            self._write(records)
            return value
