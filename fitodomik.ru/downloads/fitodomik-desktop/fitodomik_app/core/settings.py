from __future__ import annotations
import json
import os
import shutil
import threading
from copy import deepcopy
from datetime import datetime
from pathlib import Path
from typing import Any
from .paths import ANALYSIS_DIR ,SETTINGS_PATH
from .performance import measure_perf
WEATHER_API_KEYS = [key.strip() for key in os.environ.get("FITODOMIK_WEATHER_API_KEYS", "").split(",") if key.strip()]
SERIAL_COMMAND_PROTOCOL_KEY ="serial_command_protocol"
SERIAL_COMMAND_PROTOCOL_MAIN ="main"
DEFAULT_SETTINGS :dict [str ,Any ]={
"theme":"light",
"theme_mode":"light",
"dark_theme_time":"20:00",
"light_theme_time":"07:00",
"token":"",
"account_username":"",
"device_id":"",
"device_token":"",
"device_name":"",
"show_notification_center":True,
"show_extra_devices_tab":True,
"tab_order":["weather","monitoring","charts","control","analysis","extra_devices","automation_scenarios","thresholds","notifications","settings"],
"device_config_schema_version":1,
"device_config_mode":"standard",
"device_sensors":[],
"device_relays":[],
"automation_scenarios":[],
"first_run_completed":False,
"setup_completed_at":"",
"setup_steps":{
"board_selected":False,
"arduino_checked":False,
"camera_checked":False,
"account_linked":False,
"device_config_selected":False,
},
"updates_check_enabled":True,
"updates_latest_url":"",
"updates_last_checked_at":"",
"updates_last_version":"",
"analysis_feature_enabled":True,
"alert_check_interval_minutes":60,
"alert_repeat_cooldown_minutes":180,
"alert_rules":[
{
"id":"soil_low",
"name":"Низкая влажность почвы",
"enabled":True,
"sensor":"soil_moisture",
"operator":"<",
"value":30,
"duration_minutes":60,
"cooldown_minutes":180,
"actions":[],
},
{
"id":"temp_high_fan",
"name":"Высокая температура",
"enabled":True,
"sensor":"temperature",
"operator":">",
"value":32,
"duration_minutes":20,
"cooldown_minutes":60,
"actions":["relay_fan_on"],
},
{
"id":"low_light_day",
"name":"Мало света днём",
"enabled":False,
"sensor":"light_level",
"operator":"<",
"value":300,
"duration_minutes":30,
"cooldown_minutes":120,
"time_from":"08:00",
"time_to":"20:00",
"actions":["relay_light_on"],
},
],
"photo_schedule_enabled":True,
"photo_interval_minutes":0,
"photo_send_to_server":True,
"photo_save_local":True,
"extra_sensors_enabled":True,
"extra_sensors_board":"arduino_nano",
"extra_sensors_manual_fqbn":"",
"extra_sensors_poll_interval":5,
"extra_sensors_history_limit":2000,
"extra_sensors_config":[],
"extra_sensors_user_libraries_dir":"arduino_user_libraries",
"sensor_profiles_user_dir":"sensor_profiles",
"smart_photo_enabled":True,
"smart_photo_min_interval_minutes":120,
"smart_photo_on_temperature_jump":True,
"smart_photo_temperature_jump_delta":4.0,
"smart_photo_on_soil_drop":True,
"smart_photo_soil_drop_delta":15.0,
"smart_photo_on_light_on":True,
"smart_photo_if_no_analysis_hours":48,
"smart_photo_if_alert":True,
"local_sensor_queue_max_records":10000,
"local_dynamic_sensor_queue_max_records":10000,
"local_command_result_queue_max_records":5000,
"local_scenario_log_queue_max_records":5000,
"command_poll_interval_seconds":15,
"automation_scenario_check_interval_seconds":10,
"failed_upload_retry_interval_seconds":120,
"device_test_pulse_seconds":3,
"pump_test_pulse_seconds":1,
"calibration":{
"temperature_offset":0.0,
"humidity_offset":0.0,
"pressure_offset":0.0,
"co2_offset":0.0,
"soil_min":0.0,
"soil_max":100.0,
"soil_invert":False,
"soil_offset":0.0,
},
"port":"",
"auto_detect_port":True ,
"serial_baudrate":9600 ,
"serial_timeout":2.0 ,
"serial_command_protocol":"main",
"polling_interval":5 ,
"active_polling_interval":10 ,
"background_polling_interval":600 ,
"arduino_reconnect_interval":10 ,
"arduino_auto_reconnect":True ,
"data_send_interval":1800 ,
"weather_update_interval":3600 ,
"api_token_check_interval":1800 ,
"threshold_reload_interval":900 ,
"chart_update_interval":30 ,
"chart_active_interval":10 ,
"weather_city":"Moscow",
"weather_api_keys":WEATHER_API_KEYS ,
"weather_api_key_index":0 ,
"camera_index":0 ,
"photo_count":1 ,
"photo_time":12 ,
"photo_time2":18 ,
"photo_check_interval":60 ,
"control_mode":"manual",
"thresholds_mode":"auto",
"manual_thresholds":{},
"manual_schedule":{},
"fullscreen":True ,
"kiosk_mode":True ,
"interface_scale":"auto",
"auto_upload_thresholds":True ,
"analysis_output_dir":str (ANALYSIS_DIR ),
"analysis_retention_files":240,
"analysis_upload_enabled":True ,
"last_auto_photo_date":"",
"last_auto_photo_hour":None ,
}
class SettingsManager :
    def __init__ (self ,path :Path =SETTINGS_PATH )->None :
        self .path =path
        self ._lock =threading .RLock ()
        self ._settings :dict [str ,Any ]=deepcopy (DEFAULT_SETTINGS )
        self .is_first_run =True
        self .load ()
    def load (self )->dict [str ,Any ]:
        with measure_perf ("settings loaded"):
            with self ._lock :
                loaded :dict [str ,Any ]={}
                if self .path .exists ()and self .path .stat ().st_size >0 :
                    try :
                        with self .path .open ("r",encoding ="utf-8")as file :
                            decoded =json .load (file )
                            loaded =decoded if isinstance (decoded ,dict )else {}
                    except Exception :
                        backup =self .path .with_suffix (f".broken_{datetime .now ().strftime ('%Y%m%d_%H%M%S')}.json")
                        try :
                            shutil .copy2 (self .path ,backup )
                        except Exception :
                            pass
                        loaded ={}
                self .is_first_run =not bool (loaded .get ("first_run_completed",False ))
                self ._settings =self ._merge (deepcopy (DEFAULT_SETTINGS ),loaded )
                self ._settings [SERIAL_COMMAND_PROTOCOL_KEY ]=SERIAL_COMMAND_PROTOCOL_MAIN
                self ._settings .pop ("telegram",None )
                self ._settings .pop ("photo_send_to_telegram",None )
                if "theme_mode"not in loaded and loaded .get ("theme")in ("light","dark","auto"):
                    self ._settings ["theme_mode"]=loaded ["theme"]
                if self ._settings .get ("data_send_interval",0 )<300 :
                    self ._settings ["data_send_interval"]=300
                return self .as_dict ()
    def save (self )->None :
        with self ._lock :
            self .path .parent .mkdir (parents =True ,exist_ok =True )
            temporary =self .path .with_suffix (".tmp")
            with temporary .open ("w",encoding ="utf-8")as file :
                json .dump (self ._settings ,file ,ensure_ascii =False ,indent =4 )
                file .flush ()
                os .fsync (file .fileno ())
            temporary .replace (self .path )
    def as_dict (self )->dict [str ,Any ]:
        with self ._lock :
            return deepcopy (self ._settings )
    def get (self ,key :str ,default :Any =None )->Any :
        with self ._lock :
            return deepcopy (self ._settings .get (key ,default ))
    def set (self ,key :str ,value :Any ,save :bool =True )->None :
        with self ._lock :
            if key in {"telegram","photo_send_to_telegram"}:
                return
            self ._settings [key ]=SERIAL_COMMAND_PROTOCOL_MAIN if key ==SERIAL_COMMAND_PROTOCOL_KEY else value
            if key =="theme_mode"and value in ("light","dark"):
                self ._settings ["theme"]=value
            if save :
                self .save ()
    def update (self ,values :dict [str ,Any ],save :bool =True )->None :
        with self ._lock :
            values .pop ("telegram",None )
            values .pop ("photo_send_to_telegram",None )
            self ._settings .update (values )
            self ._settings [SERIAL_COMMAND_PROTOCOL_KEY ]=SERIAL_COMMAND_PROTOCOL_MAIN
            if self ._settings .get ("theme_mode")in ("light","dark"):
                self ._settings ["theme"]=self ._settings ["theme_mode"]
            if self ._settings .get ("data_send_interval",0 )<300 :
                self ._settings ["data_send_interval"]=300
            if save :
                self .save ()
    def _merge (self ,defaults :dict [str ,Any ],loaded :dict [str ,Any ])->dict [str ,Any ]:
        for key ,value in loaded .items ():
            if isinstance (value ,dict )and isinstance (defaults .get (key ),dict ):
                defaults [key ]=self ._merge (defaults [key ],value )
            else :
                defaults [key ]=value
        return defaults
