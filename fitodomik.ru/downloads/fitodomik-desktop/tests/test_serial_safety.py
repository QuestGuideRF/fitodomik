"""Contract tests for the machine Serial protocol, without connected hardware."""

import unittest

from fitodomik_app.services.serial_protocol import SerialProtocol


class SerialSafetyContractTests(unittest.TestCase):
    def setUp(self) -> None:
        self.protocol = SerialProtocol()

    def test_pump_off_is_explicit_and_idempotent(self) -> None:
        self.assertEqual(self.protocol.build_command("PUMP", False), "RELAY_SET PUMP 0")
        self.assertEqual(self.protocol.build_command("PUMP", True), "RELAY_SET PUMP 1")
        self.assertIsNone(self.protocol.build_command("PUMP"))

    def test_all_base_relays_use_set(self) -> None:
        for name in ("FAN", "PUMP", "CURTAINS", "LAMP"):
            with self.subTest(name=name):
                self.assertEqual(
                    self.protocol.build_command(name, False),
                    f"RELAY_SET {name} 0",
                )

    def test_machine_ack_and_legacy_ack(self) -> None:
        for line in (
            "ACK:CONFIG:2",
            "Data loaded successfully",
            "Данные успешно загружены",
        ):
            with self.subTest(line=line):
                self.assertTrue(self.protocol.parse(line).acknowledged)
        relay = self.protocol.parse("ACK:RELAY:PUMP:0")
        self.assertEqual(relay.kind, "relay_ack")
        self.assertEqual((relay.relay_uid, relay.relay_state), ("PUMP", False))
        self.assertFalse(relay.acknowledged)

    def test_fault_and_protocol_version(self) -> None:
        self.assertEqual(self.protocol.parse("PROTO:2").protocol_version, 2)
        fault = self.protocol.parse("FAULT:PUMP_TIMEOUT")
        self.assertEqual((fault.kind, fault.fault), ("fault", "PUMP_TIMEOUT"))
        self.assertEqual(self.protocol.parse("ERR:INPUT_TOO_LONG").kind, "error")
        sensor = self.protocol.parse(
            '{"soil":20,"relay2":false,"pump_fault":"PUMP_TIMEOUT","protocol_version":2}'
        )
        self.assertEqual(sensor.snapshot.pump_fault, "PUMP_TIMEOUT")
        self.assertFalse(sensor.snapshot.pump_state)


if __name__ == "__main__":
    unittest.main()
