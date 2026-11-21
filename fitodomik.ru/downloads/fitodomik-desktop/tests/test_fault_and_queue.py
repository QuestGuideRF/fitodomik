import logging
import tempfile
import unittest
from datetime import datetime, timedelta
from pathlib import Path

from fitodomik_app.core.event_bus import GuiEventBus
from fitodomik_app.services.arduino import ArduinoService
from fitodomik_app.services.local_queue import JsonQueue, QueueCorruptError
from fitodomik_app.ui.app import FitoDomikApplication


class SettingsStub:
    def get(self, _name, default=None):
        return default


class FaultAndQueueTests(unittest.TestCase):
    def test_corrupt_queue_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "queue.json"
            path.write_text("{broken", encoding="utf-8")
            queue = JsonQueue(path, max_records=1)
            with self.assertRaises(QueueCorruptError):
                queue.add({"id": "new"})
            self.assertEqual(path.read_text(encoding="utf-8"), "{broken")

    def test_queue_does_not_evict_unacknowledged_records(self):
        with tempfile.TemporaryDirectory() as temp:
            queue = JsonQueue(Path(temp) / "queue.json", max_records=1)
            queue.add({"id": "old", "synced": False})
            queue.add({"id": "new", "synced": False})
            self.assertEqual([item["id"] for item in queue.all()], ["old", "new"])

    def test_relay_set_requires_matching_ack(self):
        service = ArduinoService(SettingsStub(), GuiEventBus(), logging.getLogger("test"))
        writes = []

        def write(line):
            writes.append(line)
            if line.startswith("RELAY_SET"):
                service._handle_line("ACK:RELAY:PUMP:0")
            return True

        service._write_line = write
        self.assertTrue(service.send_command("PUMP", False))
        self.assertEqual(writes[0], "RELAY_SET PUMP 0")
        self.assertIn("S", writes)

    def test_pump_fault_raised_once_from_serial_and_snapshot(self):
        bus = GuiEventBus()
        service = ArduinoService(SettingsStub(), bus, logging.getLogger("test"))
        service._handle_line("FAULT:PUMP_TIMEOUT")
        service._handle_line('{"co2":500,"temp":24,"humidity":40,"pressure":1000,"soil":30,"pump_fault":"PUMP_TIMEOUT"}')
        names = [event.name for event in bus.drain()]
        self.assertEqual(names.count("arduino_fault"), 1)

    def test_zero_scenario_cooldown_still_waits_a_minute(self):
        app = FitoDomikApplication.__new__(FitoDomikApplication)
        now = datetime.now()
        scenario = {"cooldown_seconds": 0, "last_triggered_at": (now - timedelta(seconds=30)).isoformat()}
        self.assertTrue(app._scenario_in_cooldown(scenario, now))
        self.assertFalse(app._scenario_in_cooldown(scenario, now + timedelta(seconds=31)))


if __name__ == "__main__":
    unittest.main()
