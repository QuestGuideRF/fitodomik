from __future__ import annotations
from dataclasses import dataclass ,field
from datetime import datetime
from pathlib import Path
from typing import Any
@dataclass (slots =True )
class SensorSnapshot :
    timestamp :datetime =field (default_factory =datetime .now )
    temperature :float |None =None
    humidity :float |None =None
    soil_moisture :float |None =None
    light_level :float |None =None
    co2 :float |None =None
    pressure :float |None =None
    lamp_state :bool =False
    curtains_state :bool =False
    pump_state :bool =False
    fan_state :bool =False
    pump_fault :str |None =None
    protocol_version :int =0
    extra_relay_states :dict [str ,bool ]=field (default_factory =dict )
    source :str ="unknown"
    raw :str =""
    def has_sensor_values (self )->bool :
        return any (
        value is not None
        for value in (
        self .temperature ,
        self .humidity ,
        self .soil_moisture ,
        self .co2 ,
        self .pressure ,
        )
        )
    def has_api_required_values (self )->bool :
        return self .temperature is not None and self .humidity is not None
    def merged_with (self ,previous :"SensorSnapshot | None")->"SensorSnapshot":
        if previous is None :
            return self
        return SensorSnapshot (
        timestamp =self .timestamp ,
        temperature =self .temperature if self .temperature is not None else previous .temperature ,
        humidity =self .humidity if self .humidity is not None else previous .humidity ,
        soil_moisture =self .soil_moisture
        if self .soil_moisture is not None
        else previous .soil_moisture ,
        light_level =self .light_level if self .light_level is not None else previous .light_level ,
        co2 =self .co2 if self .co2 is not None else previous .co2 ,
        pressure =self .pressure if self .pressure is not None else previous .pressure ,
        lamp_state =self .lamp_state ,
        curtains_state =self .curtains_state ,
        pump_state =self .pump_state ,
        fan_state =self .fan_state ,
        pump_fault =self .pump_fault ,
        protocol_version =self .protocol_version ,
        extra_relay_states =self .extra_relay_states or dict (previous .extra_relay_states ),
        source =self .source ,
        raw =self .raw ,
        )
@dataclass (slots =True )
class ExtraSensorValue :
    sensor_id :str
    name :str
    value :float |str |bool
    unit :str =""
    pin :str =""
    connection :str =""
    status :str ="нет данных"
    timestamp :datetime =field (default_factory =datetime .now )
    def as_float (self )->float |None :
        if isinstance (self .value ,bool ):
            return 1.0 if self .value else 0.0
        try :
            return float (self .value )
        except (TypeError ,ValueError ):
            return None
@dataclass (slots =True )
class WeatherData :
    city :str
    temperature_c :float
    condition :str
    humidity :int
    wind_ms :float
    icon_bytes :bytes |None =None
    updated_at :datetime =field (default_factory =datetime .now )
@dataclass (slots =True )
class AnalysisResult :
    analysis :dict [str ,Any ]
    original_path :Path
    detection_path :Path
    mask_path :Path |None
    report_path :Path
    uploaded :bool =False
@dataclass (slots =True )
class GuiEvent :
    name :str
    payload :Any =None
    level :str ="info"
    message :str =""
    created_at :datetime =field (default_factory =datetime .now )
