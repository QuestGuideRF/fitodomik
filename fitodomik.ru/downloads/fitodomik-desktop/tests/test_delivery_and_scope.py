import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from fitodomik_app.services.command_journal import CommandJournal
from fitodomik_app.services.arduino_code_generator import ArduinoCodeGenerator
from fitodomik_app.services.serial_protocol import SerialProtocol
from fitodomik_app.core.models import SensorSnapshot
from fitodomik_app.ui.app import FitoDomikApplication

class DeliveryAndScopeTests(unittest.TestCase):
    def test_journal_survives_restart_and_does_not_replay_uncertain_effect(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'journal.json'
            journal=CommandJournal(path)
            client=journal.client_id()
            first, record=journal.begin('A', 42)
            self.assertTrue(first)
            restored=CommandJournal(path)
            self.assertEqual(restored.client_id(),client)
            self.assertFalse(restored.begin('A',42)[0])
            restored.complete(record, {'status':'done'})
            self.assertEqual(CommandJournal(path).begin('A',42)[1]['result']['status'],'done')
            self.assertTrue(restored.begin('B',42)[0])

    def test_only_current_device_sensor_records_are_sent(self):
        app=FitoDomikApplication.__new__(FitoDomikApplication)
        rows=[{'id':'1','device_id':'A','synced':False},{'id':'2','device_id':'B','synced':False}]
        sent=[]
        app.sensor_queue=SimpleNamespace(all=lambda: rows, update_records=lambda rows: None)
        app.api=SimpleNamespace(device_id='A', send_sensor_record=lambda row: sent.append(row['id']) or True)
        app.notifications=SimpleNamespace(add=lambda *args,**kwargs:None)
        app.event_bus=SimpleNamespace(post=lambda *args,**kwargs:None)
        self.assertEqual(app._sync_sensor_queue(),1)
        self.assertEqual(sent,['1'])
        self.assertFalse(rows[1]['synced'])

    def test_pump_off_attempted_even_if_sleep_raises(self):
        app=FitoDomikApplication.__new__(FitoDomikApplication)
        app.latest_snapshot=SensorSnapshot(pump_state=True)
        calls=[]
        app._set_relay_state=lambda relay,state: calls.append((relay,state)) or True
        with patch('fitodomik_app.ui.app.time_module.sleep',side_effect=RuntimeError('interrupted')):
            with self.assertRaises(RuntimeError): app._pulse_relay(2,1)
        self.assertEqual(calls,[(2,True),(2,False)])

    def test_extra_relay_roundtrip(self):
        protocol=SerialProtocol()
        self.assertEqual(protocol.build_command('AUX_1',False),'RELAY_SET AUX_1 0')
        parsed=protocol.parse('{"co2":500,"extra_relays":{"aux_1":true}}')
        self.assertTrue(parsed.snapshot.extra_relay_states['AUX_1'])
    def test_midnight_schedule_is_distinct_from_disabled_schedule(self):
        protocol=SerialProtocol()
        self.assertEqual(protocol._hours_to_ranges([]),'0')
        self.assertEqual(protocol._hours_to_ranges([0]),'0-0')

    def test_generator_rejects_second_sensor_pin_and_duplicate_relay(self):
        settings=SimpleNamespace(get=lambda name,default: [{'relay_uid':'aux','pin':'D9'}])
        config=SimpleNamespace(settings=settings,registry=None,sensors=lambda:[{'pin':'D8/D9'}])
        generator=ArduinoCodeGenerator(config,registry=SimpleNamespace())
        with self.assertRaises(ValueError): generator._configured_extra_relays()
        config.sensors=lambda:[]
        settings.get=lambda name,default:[{'relay_uid':'aux','pin':'D9'},{'relay_uid':'AUX','pin':'D10'}]
        with self.assertRaises(ValueError): generator._configured_extra_relays()

if __name__=='__main__': unittest.main()
