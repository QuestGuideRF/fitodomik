from __future__ import annotations
import glob
import logging
import threading
import time
from typing import Any
from fitodomik_app .core .event_bus import GuiEventBus
from fitodomik_app .core .models import SensorSnapshot
from fitodomik_app .core .performance import log_perf
from fitodomik_app .core .settings import SettingsManager
from .serial_protocol import SerialProtocol
_SERIAL_MODULE =None
_SERIAL_LIST_PORTS =None
def _serial_modules ():
    global _SERIAL_MODULE ,_SERIAL_LIST_PORTS
    if _SERIAL_MODULE is None or _SERIAL_LIST_PORTS is None :
        import serial
        import serial .tools .list_ports
        _SERIAL_MODULE =serial
        _SERIAL_LIST_PORTS =serial .tools .list_ports
    return _SERIAL_MODULE ,_SERIAL_LIST_PORTS
class ArduinoService :
    def __init__ (
    self ,
    settings :SettingsManager ,
    event_bus :GuiEventBus ,
    logger :logging .Logger |None =None ,
    )->None :
        self .settings =settings
        self .event_bus =event_bus
        self .logger =(logger or logging .getLogger ("fitodomik")).getChild ("arduino")
        self .protocol =SerialProtocol ()
        self .serial_connection :Any |None =None
        self .is_connected =False
        self .running =False
        self .current_snapshot =SensorSnapshot ()
        self .current_extra_sensors :dict [str ,object ]={}
        self .current_extra_sensor_payload :dict [str ,object ]={}
        self .last_main_serial_at =0.0
        self .last_extra_serial_at =0.0
        self .last_serial_kind =""
        self .port :str =self .settings .get ("port","")
        self ._thread :threading .Thread |None =None
        self ._lock =threading .RLock ()
        self ._ack_event =threading .Event ()
        self ._command_lock =threading .Lock ()
        self ._relay_reply_event =threading .Event ()
        self ._expected_relay :tuple [str ,bool |None ]|None =None
        self ._relay_reply_ok =False
        self .protocol_version =0
        self ._last_pump_fault :str |None =None
        self ._stop_event =threading .Event ()
        self ._last_parse_error_key =""
        self ._last_parse_error_at =0.0
        self ._parse_error_repeat_count =0
    def list_ports (self )->list [str ]:
        try :
            _serial ,list_ports =_serial_modules ()
            ports =[port .device for port in list_ports .comports ()]
        except Exception as exc :
            self .logger .warning ("PySerial недоступен или список портов не получен: %s",exc )
            return []
        for pattern in ("/dev/ttyUSB*","/dev/ttyACM*","/dev/serial/by-id/*"):
            for match in glob .glob (pattern ):
                if match not in ports :
                    ports .append (match )
        return ports
    def auto_detect_port (self )->str |None :
        ports =self .list_ports ()
        if not ports :
            return None
        preferred =("ttyUSB","ttyACM","Arduino","USB-SERIAL","CH340","COM")
        for port in ports :
            if any (token .lower ()in port .lower ()for token in preferred ):
                return port
        return ports [0 ]
    def connect (self ,port :str |None =None )->bool :
        started =time .perf_counter ()
        with self ._lock :
            selected_port =""
            try :
                if self .is_connected and self .serial_connection and self .serial_connection .is_open :
                    return True
                if port :
                    selected_port =port
                elif self .settings .get ("auto_detect_port",True ):
                    selected_port =self .auto_detect_port ()or self .settings .get ("port","")
                else :
                    selected_port =self .settings .get ("port","")
                if not selected_port :
                    self ._set_disconnected ("Serial-порт Arduino не найден")
                    return False
                serial_module ,_list_ports =_serial_modules ()
                self .serial_connection =serial_module .Serial (
                selected_port ,
                int (self .settings .get ("serial_baudrate",9600 )),
                timeout =float (self .settings .get ("serial_timeout",2.0 )),
                )
                time .sleep (2 )
                self .port =selected_port
                self .is_connected =True
                self .settings .set ("port",selected_port ,save =True )
                self .event_bus .post ("arduino_status",True ,message =f"Arduino подключена: {selected_port }")
                self .logger .info ("Подключено к Arduino на %s",selected_port )
                return True
            except Exception as exc :
                self .logger .warning ("Не удалось подключиться к Arduino %s: %s",selected_port ,exc )
                self ._set_disconnected (f"Не удалось подключиться к Arduino: {selected_port }")
                return False
            finally :
                log_perf ("arduino connect",time .perf_counter ()-started )
    def start (self )->None :
        if self .running :
            return
        self .running =True
        self ._stop_event .clear ()
        self ._thread =threading .Thread (target =self ._read_loop ,name ="arduino-reader",daemon =True )
        self ._thread .start ()
    def stop (self )->None :
        self .request_stop ()
        if self ._thread and self ._thread .is_alive ()and self ._thread is not threading .current_thread ():
            self ._thread .join (timeout =0.5 )
        self .disconnect (wait =False )
    def request_stop (self )->None :
        self .running =False
        self ._stop_event .set ()
        self ._ack_event .set ()
        self ._relay_reply_event .set ()
        connection =self .serial_connection
        if connection :
            try :
                connection .close ()
            except Exception :
                pass
    def disconnect (self ,wait :bool =True )->None :
        timeout =0.5 if wait else 0
        acquired =self ._lock .acquire (timeout =timeout )
        if not acquired :
            self .is_connected =False
            self .event_bus .post ("arduino_status",False ,message ="Arduino отключена")
            return
        try :
            if self .serial_connection :
                try :
                    self .serial_connection .close ()
                except Exception :
                    pass
            self .serial_connection =None
            self .is_connected =False
            self .event_bus .post ("arduino_status",False ,message ="Arduino отключена")
        finally :
            self ._lock .release ()
    def send_command (self ,device_type :str ,desired_state :bool |None =None )->bool :
        if device_type .upper ()=="ALL_OFF":
            results =[self .send_command (relay ,False )for relay in ("PUMP","FAN","CURTAINS","LAMP")]
            results .extend (self .send_command (uid ,False )for uid in self .current_snapshot .extra_relay_states )
            return all (results )
        if device_type .upper ()=="ALL_ON":
            self .logger .warning ("Групповое включение реле не поддерживается без отдельного подтверждения")
            return False
        command =self .protocol .build_command (
        device_type ,
        desired_state ,
        command_protocol ="main",
        )
        if not command :
            self .logger .warning ("Неизвестная команда Arduino: %s",device_type )
            return False
        if command =="S":
            return self ._write_line (command )
        expected :tuple [str ,bool |None ]|None =None
        if command .startswith ("RELAY_SET "):
            parts =command .split ()
            expected =(parts [1 ],parts [2 ]=="1")
        elif command =="PUMP_FAULT_RESET":
            expected =("PUMP_FAULT_RESET",None )
        with self ._command_lock :
            self ._expected_relay =expected
            self ._relay_reply_ok =False
            self ._relay_reply_event .clear ()
            try :
                if not self ._write_line (command ):
                    return False
                self .logger .info ("Команда Arduino отправлена: %s (%s)",device_type ,command )
                if expected is None :
                    self ._write_line ("S")
                    return True
                if not self ._relay_reply_event .wait (timeout =3.0 ):
                    self .logger .warning ("Arduino не подтвердила SET-команду %s",command )
                    return False
                if self ._relay_reply_ok :
                    self ._write_line ("S")
                return self ._relay_reply_ok
            finally :
                self ._expected_relay =None
    def request_status (self )->bool :
        return self .send_command ("STATUS")
    def upload_thresholds (self ,payload :str )->bool :
        if len (payload .encode ("utf-8"))>=192 or "\n"in payload or "\r"in payload :
            self .logger .warning ("Конфигурация превышает Serial-лимит или содержит перевод строки")
            return False
        self ._ack_event .clear ()
        if not self ._write_line (payload ):
            return False
        success =self ._ack_event .wait (timeout =6 )
        if success :
            self .event_bus .post ("arduino_upload",True ,message ="Данные загружены в Arduino")
        else :
            self .event_bus .post ("arduino_upload",False ,level ="warning",message ="Arduino не подтвердила загрузку")
        return success
    def clear_runtime_values (self )->None :
        self .current_snapshot =SensorSnapshot ()
        self .current_extra_sensors ={}
        self .current_extra_sensor_payload ={}
        self .last_main_serial_at =0.0
        self .last_extra_serial_at =0.0
        self .last_serial_kind =""
    def _read_loop (self )->None :
        while self .running and not self ._stop_event .is_set ():
            try :
                connection =self ._active_read_connection ()
                if connection is None :
                    continue
                line =self ._read_serial_line (connection )
                if line :
                    self ._handle_line (line )
            except Exception as exc :
                self .logger .warning ("Ошибка цикла чтения Arduino: %s",exc )
                self ._set_disconnected (f"Ошибка Serial-связи: {exc }")
                time .sleep (self ._reconnect_delay ())
    def _active_read_connection (self )->Any |None :
        if not self .is_connected :
            self .connect ()
            if not self .is_connected :
                time .sleep (self ._reconnect_delay ())
                return None
        connection =self .serial_connection
        if connection is None :
            self .is_connected =False
            return None
        return connection
    def _read_serial_line (self ,connection :Any )->str :
        if connection .in_waiting <=0 :
            time .sleep (0.05 )
            return ""
        return connection .readline ().decode ("utf-8",errors ="replace").strip ()
    def _reconnect_delay (self )->float :
        if not self .settings .get ("arduino_auto_reconnect",True ):
            return 2.0
        return float (self .settings .get ("arduino_reconnect_interval",10 ))
    def _handle_line (self ,line :str )->None :
        try :
            parsed =self .protocol .parse (line ,self .current_snapshot )
            if parsed .acknowledged :
                self ._ack_event .set ()
            if parsed .kind =="protocol"and parsed .protocol_version is not None :
                self .protocol_version =parsed .protocol_version
            if parsed .kind =="relay_ack"and self ._expected_relay is not None :
                if (str (parsed .relay_uid ).upper (),parsed .relay_state )==self ._expected_relay:
                    self ._relay_reply_ok =True
                    self ._relay_reply_event .set ()
            elif parsed .kind =="fault_reset_ack"and self ._expected_relay ==("PUMP_FAULT_RESET",None ):
                self ._relay_reply_ok =True
                self ._relay_reply_event .set ()
            elif parsed .kind =="error"and self ._expected_relay is not None :
                self ._relay_reply_ok =False
                self ._relay_reply_event .set ()
            if parsed .kind =="event":
                self .event_bus .post ("arduino_pump_event",message =parsed .message )
            current_fault =parsed .fault or (parsed .snapshot .pump_fault if parsed .snapshot else None )
            if current_fault =="PUMP_TIMEOUT"and self ._last_pump_fault !=current_fault :
                self ._last_pump_fault =current_fault
                self .event_bus .post ("arduino_fault",current_fault ,level ="error",message ="Насос аварийно выключен: превышены 60 секунд")
            elif parsed .kind =="fault_reset_ack":
                self ._last_pump_fault =None
                self .event_bus .post ("arduino_fault_reset")
            if parsed .kind =="extra_sensors":
                self .last_extra_serial_at =time .time ()
                self .last_serial_kind =parsed .kind
                self .current_extra_sensor_payload =dict (parsed .extra_values or {})
                self .current_extra_sensors =self .protocol .flatten_extra_values (self .current_extra_sensor_payload )
                self .event_bus .post ("extra_sensors_update",self .current_extra_sensor_payload )
            elif parsed .snapshot is not None :
                self .last_main_serial_at =time .time ()
                self .last_serial_kind =parsed .kind
                self .current_snapshot =parsed .snapshot
                self .event_bus .post ("sensor_update",parsed .snapshot )
            elif parsed .kind =="message":
                self .last_serial_kind =parsed .kind
                self .event_bus .post ("arduino_message",line )
            self .logger .debug ("Последовательная линия [%s]: %s",parsed .kind ,line )
        except Exception as exc :
            self ._log_parse_error (line ,exc )
    def _log_parse_error (self ,line :str ,exc :Exception )->None :
        now =time .time ()
        key =f"{line [:120 ]}|{type (exc ).__name__}"
        if key ==self ._last_parse_error_key and now -self ._last_parse_error_at <10 :
            self ._parse_error_repeat_count +=1
            return
        suffix =""
        if self ._parse_error_repeat_count :
            suffix =f" Скрыто повторов: {self ._parse_error_repeat_count }."
        self ._last_parse_error_key =key
        self ._last_parse_error_at =now
        self ._parse_error_repeat_count =0
        self .logger .info ("Не удалось проанализировать последовательную строку %r: %s.%s",line ,exc ,suffix )
    def _write_line (self ,line :str )->bool :
        if not self .is_connected and not self .connect ():
            return False
        with self ._lock :
            try :
                if not self .serial_connection :
                    return False
                self .serial_connection .write ((line +"\n").encode ("utf-8"))
                self .serial_connection .flush ()
                return True
            except Exception as exc :
                self .logger .warning ("Ошибка записи Arduino: %s",exc )
                self ._set_disconnected (f"Не удалось отправить команду Arduino: {exc }")
                return False
    def _set_disconnected (self ,message :str )->None :
        self .is_connected =False
        try :
            if self .serial_connection :
                self .serial_connection .close ()
        except Exception :
            pass
        self .serial_connection =None
        self .event_bus .post ("arduino_status",False ,level ="warning",message =message )
