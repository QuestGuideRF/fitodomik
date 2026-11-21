from __future__ import annotations
import json
import re
from dataclasses import dataclass
from datetime import datetime
from typing import Any
from fitodomik_app .core .models import SensorSnapshot
CURTAINS_PREFIX ="CURTAINS:"
@dataclass (slots =True )
class ParsedSerialLine :
    kind :str
    snapshot :SensorSnapshot |None =None
    extra_values :dict [str ,Any ]|None =None
    message :str =""
    acknowledged :bool =False
    relay_uid :str |None =None
    relay_state :bool |None =None
    protocol_version :int |None =None
    fault :str |None =None
def _as_float (value :Any )->float |None :
    if value is None or value =="--":
        return None
    try :
        return float (value )
    except (TypeError ,ValueError ):
        return None
def _as_bool (value :Any )->bool :
    if isinstance (value ,bool ):
        return value
    if isinstance (value ,(int ,float )):
        return bool (value )
    return str (value ).strip ().lower ()in {"1","true","on","yes","open"}
def normalize_pressure (value :Any )->float |None :
    pressure =_as_float (value )
    if pressure is None :
        return None
    if pressure >900 :
        return round (pressure *0.75006 ,1 )
    return round (pressure ,1 )
class SerialProtocol :
    PROTOCOL_VERSION =2
    MAIN_COMMANDS ={
    "LED":"LAMP",
    "LAMP":"LAMP",
    "CURTAINS":"CURTAINS",
    "RELAY3":"CURTAINS",
    "PUMP":"PUMP",
    "RELAY2":"PUMP",
    "RELAY4":"LAMP",
    "RELAY1":"FAN",
    "FAN":"FAN",
    "ALL_ON":"A+",
    "ALL_OFF":"A-",
    "STATUS":"S",
    }
    LEGACY_COMMAND_PREFIXES ={
    "LED":"LED:",
    "LAMP":"LED:",
    "CURTAINS":CURTAINS_PREFIX,
    "RELAY3":"RELAY3:",
    "PUMP":"RELAY3:",
    "RELAY4":"RELAY4:",
    "FAN":"RELAY4:",
    }
    def parse (self ,line :str ,previous :SensorSnapshot |None =None )->ParsedSerialLine :
        clean =line .strip ()
        if not clean :
            return ParsedSerialLine (kind ="empty")
        if clean .startswith ("PROTO:"):
            try :
                return ParsedSerialLine (kind ="protocol",message =clean ,protocol_version =int (clean .split (":",1 )[1 ]))
            except ValueError :
                return ParsedSerialLine (kind ="error",message =clean )
        if clean .startswith ("ACK:CONFIG:")or "Данные успешно загружены"in clean or "Data loaded successfully"in clean :
            return ParsedSerialLine (kind ="ack",message =clean ,acknowledged =True )
        if clean .startswith ("ACK:RELAY:"):
            parts =clean .split (":")
            if len (parts )==4 and parts [3 ]in {"0","1"}:
                return ParsedSerialLine (kind ="relay_ack",message =clean ,relay_uid =parts [2 ],relay_state =parts [3 ]=="1")
            return ParsedSerialLine (kind ="error",message =clean )
        if clean .startswith ("ACK:PUMP_FAULT_RESET:"):
            return ParsedSerialLine (kind ="fault_reset_ack",message =clean )
        if clean .startswith ("FAULT:"):
            return ParsedSerialLine (kind ="fault",message =clean ,fault =clean .split (":",1 )[1 ])
        if clean .startswith ("ERR:"):
            return ParsedSerialLine (kind ="error",message =clean )
        if clean .startswith ("EVENT:"):
            return ParsedSerialLine (kind ="event",message =clean )
        if clean .startswith ("EXTRA_SENSORS:"):
            return self ._parse_extra_sensors (clean )
        if clean .startswith ("EXTRA:"):
            return self ._parse_extra_pairs (clean )
        if clean .startswith ("{")and clean .endswith ("}"):
            return self ._parse_json (clean ,previous )
        if clean .startswith ("Status:"):
            return self ._parse_status (clean ,previous )
        if clean .startswith ("SENSORS:"):
            return self ._parse_legacy_sensors (clean ,previous )
        if clean .startswith ("DEVICES:"):
            return self ._parse_legacy_devices (clean ,previous )
        if any (clean .startswith (prefix )for prefix in ("LED:",CURTAINS_PREFIX,"PUMP:","FAN:","R")):
            return self ._parse_device_line (clean ,previous )
        return ParsedSerialLine (kind ="message",message =clean )
    def _parse_extra_sensors (self ,line :str )->ParsedSerialLine :
        payload =line .split (":",1 )[1 ].strip ()
        data =json .loads (payload )
        if not isinstance (data ,dict ):
            raise ValueError ("EXTRA_SENSORS должен содержать JSON object")
        return ParsedSerialLine (kind ="extra_sensors",extra_values =data ,message =line )
    def _parse_extra_pairs (self ,line :str )->ParsedSerialLine :
        payload =line .split (":",1 )[1 ].strip ()
        values :dict [str ,Any ]={}
        for part in payload .split (";"):
            if not part or "="not in part :
                continue
            key ,value =part .split ("=",1 )
            key =key .strip ()
            value =value .strip ()
            number =_as_float (value )
            values [key ]=number if number is not None else value
        return ParsedSerialLine (kind ="extra_sensors",extra_values =values ,message =line )
    def flatten_extra_values(self, payload: dict[str, Any]) -> dict[str, Any]:
        values: dict[str, Any] = {}
        for sensor_id, value in payload.items():
            key = str(sensor_id)
            if not isinstance(value, dict):
                values[key] = value
                continue
            self._flatten_extra_payload_object(key, value, values)
        return values
    def _flatten_extra_payload_object(self, key: str, payload: dict[str, Any], values: dict[str, Any]) -> None:
        if payload.get("ok", True) is False:
            values[key] = str(payload.get("error") or "read_failed")
            return
        first_value: Any = None
        for field_name, field_value in payload.items():
            if field_name in {"ok", "error", "unit", "status"}:
                continue
            if first_value is None:
                first_value = field_value
            values[f"{key}_{self._safe_extra_key(field_name)}"] = field_value
        if first_value is not None:
            values[key] = first_value
    def build_command (
    self ,
    device_type :str ,
    state :bool |None =None ,
    command_protocol :str ="main",
    )->str |None :
        device_key =device_type .upper ()
        if command_protocol =="legacy"and device_key in self .LEGACY_COMMAND_PREFIXES :
            if state is None :
                return None
            return f"{self .LEGACY_COMMAND_PREFIXES [device_key ]}{1 if state else 0 }"
        if device_key =="STATUS":
            return "S"
        if device_key =="PUMP_FAULT_RESET":
            return "PUMP_FAULT_RESET"
        if device_key in {"ALL_ON","ALL_OFF"}:
            return self .MAIN_COMMANDS [device_key ]
        relay_uid =self .MAIN_COMMANDS .get (device_key ,device_key )
        if state is None or not re .fullmatch (r"[A-Z0-9_-]{1,64}",relay_uid ):
            return None
        return f"RELAY_SET {relay_uid} {1 if state else 0}"
    def build_threshold_payload (
    self ,
    thresholds :dict [str ,Any ]|None ,
    schedule_data :Any ,
    device_state :tuple [int ,int ]=(0 ,0 ),
    )->str :
        order =["temperature","humidity_air","humidity_soil","co2"]
        threshold_parts :list [str ]=[]
        thresholds =thresholds or {}
        for key in order :
            values =thresholds .get (key ,{})
            if isinstance (values ,dict ):
                min_val =values .get ("min_limit",values .get ("min",0 ))
                max_val =values .get ("max_limit",values .get ("max",0 ))
                threshold_parts .append (f"{min_val }-{max_val }")
            else :
                threshold_parts .append ("0-0")
        devices =f"{int (device_state [0 ])},{int (device_state [1 ])}"
        lamp ,curtains =self ._schedule_ranges (schedule_data )
        return f"{';'.join (threshold_parts )}|{devices }|{lamp };{curtains }"
    def build_manual_payload (
    self ,
    manual_thresholds :dict [str ,Any ],
    manual_schedule :dict [str ,Any ],
    )->str :
        order =["temperature","humidity_air","humidity_soil","co2"]
        threshold_parts =[]
        for key in order :
            values =manual_thresholds .get (key ,{})
            threshold_parts .append (f"{values .get ('min',0 )}-{values .get ('max',0 )}")
        lamp_hours =manual_schedule .get ("lamp",{}).get ("active_hours",[])
        curtain_hours =manual_schedule .get ("curtains",{}).get ("active_hours",[])
        lamp =self ._hours_to_ranges (lamp_hours )
        curtains =self ._hours_to_ranges (curtain_hours )
        return f"{';'.join (threshold_parts )}|0,0|{lamp };{curtains }"
    def _parse_json (self ,line :str ,previous :SensorSnapshot |None )->ParsedSerialLine :
        data =json .loads (line )
        snapshot =SensorSnapshot (
        timestamp =datetime .now (),
        temperature =_as_float (data .get ("temp")),
        humidity =_as_float (data .get ("humidity")),
        soil_moisture =_as_float (data .get ("soil")),
        light_level =_as_float (data .get ("light",data .get ("light_level"))),
        co2 =_as_float (data .get ("co2")),
        pressure =normalize_pressure (data .get ("pressure")),
        fan_state =_as_bool (data .get ("relay1")),
        pump_state =_as_bool (data .get ("relay2")),
        curtains_state =_as_bool (data .get ("relay3")),
        lamp_state =_as_bool (data .get ("relay4")),
        source ="json",
        raw =line ,
        pump_fault =str (data ["pump_fault"])if data .get ("pump_fault")else None ,
        protocol_version =int (data .get ("protocol_version",0 )or 0 ),
        extra_relay_states ={str (key ).upper ():_as_bool (value )for key ,value in data .get ("extra_relays",{}).items ()}if isinstance (data .get ("extra_relays"),dict )else {},
        )
        return ParsedSerialLine (kind ="sensor",snapshot =snapshot .merged_with (previous ))
    def _parse_status (self ,line :str ,previous :SensorSnapshot |None )->ParsedSerialLine :
        snapshot =previous or SensorSnapshot ()
        values ={
        match .group (1 ):match .group (2 )=="ON"
        for match in re .finditer (r"R([1-4]):(ON|OFF)",line )
        }
        snapshot =SensorSnapshot (
        timestamp =datetime .now (),
        temperature =snapshot .temperature ,
        humidity =snapshot .humidity ,
        soil_moisture =snapshot .soil_moisture ,
        light_level =snapshot .light_level ,
        co2 =snapshot .co2 ,
        pressure =snapshot .pressure ,
        fan_state =values .get ("1",snapshot .fan_state ),
        pump_state =values .get ("2",snapshot .pump_state ),
        curtains_state =values .get ("3",snapshot .curtains_state ),
        lamp_state =values .get ("4",snapshot .lamp_state ),
        source ="status",
        extra_relay_states =dict (snapshot .extra_relay_states ),
        raw =line ,
        pump_fault ="PUMP_TIMEOUT"if "FAULT:PUMP_TIMEOUT"in line else None ,
        protocol_version =2 if "PROTO:2"in line else snapshot .protocol_version ,
        )
        return ParsedSerialLine (kind ="devices",snapshot =snapshot )
    def _parse_legacy_sensors (self ,line :str ,previous :SensorSnapshot |None )->ParsedSerialLine :
        parts =line .split (":")
        snapshot =previous or SensorSnapshot ()
        if len (parts )>=7 :
            snapshot =SensorSnapshot (
            timestamp =datetime .now (),
            temperature =_as_float (parts [1 ]),
            humidity =_as_float (parts [2 ]),
            soil_moisture =_as_float (parts [3 ]),
            light_level =_as_float (parts [4 ]),
            co2 =_as_float (parts [5 ]),
            pressure =normalize_pressure (parts [6 ]),
            lamp_state =snapshot .lamp_state ,
            curtains_state =snapshot .curtains_state ,
            pump_state =snapshot .pump_state ,
            fan_state =snapshot .fan_state ,
            source ="legacy_sensors",
            pump_fault =snapshot .pump_fault ,
            protocol_version =snapshot .protocol_version ,
            extra_relay_states =dict (snapshot .extra_relay_states ),
            raw =line ,
            )
        return ParsedSerialLine (kind ="sensor",snapshot =snapshot .merged_with (previous ))
    def _parse_legacy_devices (self ,line :str ,previous :SensorSnapshot |None )->ParsedSerialLine :
        parts =line .split (":")
        snapshot =previous or SensorSnapshot ()
        if len (parts )>=5 :
            snapshot =SensorSnapshot (
            timestamp =datetime .now (),
            temperature =snapshot .temperature ,
            humidity =snapshot .humidity ,
            soil_moisture =snapshot .soil_moisture ,
            light_level =snapshot .light_level ,
            co2 =snapshot .co2 ,
            pressure =snapshot .pressure ,
            lamp_state =_as_bool (parts [1 ]),
            curtains_state =_as_bool (parts [2 ]),
            pump_state =_as_bool (parts [3 ]),
            fan_state =_as_bool (parts [4 ]),
            source ="legacy_devices",
            pump_fault =snapshot .pump_fault ,
            protocol_version =snapshot .protocol_version ,
            extra_relay_states =dict (snapshot .extra_relay_states ),
            raw =line ,
            )
        return ParsedSerialLine (kind ="devices",snapshot =snapshot )
    def _parse_device_line (self ,line :str ,previous :SensorSnapshot |None )->ParsedSerialLine :
        snapshot =previous or SensorSnapshot ()
        values ={
        "lamp_state":snapshot .lamp_state ,
        "curtains_state":snapshot .curtains_state ,
        "pump_state":snapshot .pump_state ,
        "fan_state":snapshot .fan_state ,
        }
        if line .startswith ("LED:"):
            values ["lamp_state"]=_as_bool (line .split (":",1 )[1 ])
        elif line .startswith (CURTAINS_PREFIX):
            values ["curtains_state"]=_as_bool (line .split (":",1 )[1 ])
        elif line .startswith ("PUMP:"):
            values ["pump_state"]=_as_bool (line .split (":",1 )[1 ])
        elif line .startswith ("FAN:"):
            values ["fan_state"]=_as_bool (line .split (":",1 )[1 ])
        elif line .startswith ("R")and ":"in line :
            relay ,state =line .split (":",1 )
            state_bool =_as_bool (state )
            if relay =="R1":
                values ["fan_state"]=state_bool
            elif relay =="R2":
                values ["pump_state"]=state_bool
            elif relay =="R3":
                values ["curtains_state"]=state_bool
            elif relay =="R4":
                values ["lamp_state"]=state_bool
        snapshot =SensorSnapshot (
        timestamp =datetime .now (),
        temperature =snapshot .temperature ,
        humidity =snapshot .humidity ,
        soil_moisture =snapshot .soil_moisture ,
        light_level =snapshot .light_level ,
        co2 =snapshot .co2 ,
        pressure =snapshot .pressure ,
        source ="device_line",
        raw =line ,
        lamp_state =values ["lamp_state"],
        curtains_state =values ["curtains_state"],
        pump_state =values ["pump_state"],
        fan_state =values ["fan_state"],
        pump_fault =snapshot .pump_fault ,
        protocol_version =snapshot .protocol_version ,
        extra_relay_states =dict (snapshot .extra_relay_states ),
        )
        return ParsedSerialLine (kind ="devices",snapshot =snapshot )
    def _schedule_ranges (self ,schedule_data :Any )->tuple [str ,str ]:
        if isinstance (schedule_data ,dict )and "data"in schedule_data :
            schedule_data =schedule_data ["data"]
        if isinstance (schedule_data ,dict )and "schedule"in schedule_data :
            schedule_data =schedule_data ["schedule"]
        if not isinstance (schedule_data ,list ):
            return "0","0"
        lamp_hours :list [int ]=[]
        curtain_hours :list [int ]=[]
        for item in schedule_data :
            try :
                hour =int (str (item .get ("time_interval",item .get ("hour","0"))).split (":")[0 ])
            except (TypeError ,ValueError ):
                continue
            if item .get ("lighting_active"):
                lamp_hours .append (hour )
            if item .get ("curtains_active"):
                curtain_hours .append (hour )
        return self ._hours_to_ranges (lamp_hours ),self ._hours_to_ranges (curtain_hours )
    def _hours_to_ranges (self ,hours :list [int ])->str :
        if not hours :
            return "0"
        hours =sorted ({int (hour )for hour in hours if 0 <=int (hour )<=23 })
        if not hours :
            return "0"
        result :list [str ]=[]
        start =end =hours [0 ]
        for hour in hours [1 :]:
            if hour ==end +1 :
                end =hour
                continue
            result .append (f"{start }-{end }"if start !=end or start ==0 else str (start ))
            start =end =hour
        result .append (f"{start }-{end }"if start !=end or start ==0 else str (start ))
        return ",".join (result )
    def _safe_extra_key(self, value: Any) -> str:
        text = str(value)
        result = "".join(ch.lower() if ch.isalnum() else "_" for ch in text)
        while "__" in result:
            result = result.replace("__", "_")
        return result.strip("_") or "value"
