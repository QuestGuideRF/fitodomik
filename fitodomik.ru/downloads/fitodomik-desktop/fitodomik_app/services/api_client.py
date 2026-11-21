from __future__ import annotations
import logging
import os
import ssl
from pathlib import Path
from typing import Any
from fitodomik_app .core .config import API_BASE_URL
from fitodomik_app .core .models import SensorSnapshot
_REQUESTS_MODULE =None
INVALID_SITE_RESPONSE ="Сайт вернул некорректный ответ"
SITE_UNAVAILABLE ="Сайт временно недоступен"
ACCOUNT_NOT_LINKED ="Аккаунт не привязан"
JPEG_MIME ="image/jpeg"
def _safe_int (value :Any ,default :int =0 )->int :
    try :
        if value is None :
            return default
        return int (value )
    except (TypeError ,ValueError ):
        return default
def _requests_module ():
    global _REQUESTS_MODULE
    if _REQUESTS_MODULE is None :
        import requests
        _REQUESTS_MODULE =requests
    return _REQUESTS_MODULE
def setup_ssl_and_network (logger :logging .Logger |None =None )->bool :
    try :
        from certifi import where
        # Trust only an explicitly configured CA or the installed requests bundle.
        configured =os .environ .get ("REQUESTS_CA_BUNDLE")or os .environ .get ("SSL_CERT_FILE")
        cert_path =Path (configured or where ())
        if cert_path .is_dir ():
            ssl .create_default_context (capath =str (cert_path ))
        else :
            ssl .create_default_context (cafile =str (cert_path ))
        os .environ ["REQUESTS_CA_BUNDLE"]=str (cert_path )
        os .environ ["SSL_CERT_FILE"]=str (cert_path )
        return True
    except (OSError ,ValueError )as exc :
        if logger :
            logger .warning ("Не удалось проверить пакет CA: %s",exc )
    return False
class FitoDomikApiClient :
    def __init__ (self ,token :str ="",logger :logging .Logger |None =None ,base_url :str =API_BASE_URL )->None :
        self .base_url =base_url .rstrip ("/")
        self .token =token
        self .device_id =""
        self .device_token =""
        self .device_name =""
        self .delivery_client =""
        self .logger =(logger or logging .getLogger ("fitodomik")).getChild ("api")
        self ._session =None
        self .last_used_id =0
    @property
    def session (self ):
        return self ._ensure_session ()
    def _ensure_session (self ):
        if self ._session is None :
            self ._session =_requests_module ().Session ()
            self ._apply_headers_to_session (self ._session )
        return self ._session
    def set_token (self ,token :str )->None :
        self .token =token .strip ()
        self ._apply_headers ()
    def set_device (self ,device_id :str ,device_name :str ,device_token :str ="" )->None :
        self .device_id =device_id .strip ()
        self .device_name =device_name .strip ()
        self .device_token =device_token .strip ()
        self ._apply_headers ()
    def check_token (self )->bool :
        if not self .token :
            return False
        try :
            response =self .session .get (
            f"{self .base_url }/get-max-sensor-id.php",
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            return response .status_code ==200 and response .json ().get ("success")is True
        except Exception as exc :
            self .logger .info ("Проверка токена не удалась: %s",exc )
            return False
    def link_account (self ,username :str ,password :str ,device_name :str ="",device_id :str ="" )->tuple [bool ,dict [str ,Any ]|None ,str ]:
        username =username .strip ()
        if not username or not password :
            return False ,None ,"Введите имя пользователя и пароль"
        try :
            response =self .session .post (
            f"{self .base_url }/desktop-login.php",
            json ={
            "username":username ,
            "password":password ,
            "device_name":device_name or self .device_name or "FitoDomik",
            "device_id":device_id or self .device_id,
            },
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            try :
                data =response .json ()
            except ValueError :
                return False ,None ,INVALID_SITE_RESPONSE
            message =str (data .get ("message","Не удалось привязать аккаунт"))
            token =data .get ("token")
            if response .status_code ==200 and data .get ("success")and token :
                return True ,data ,message
            return False ,None ,message
        except Exception as exc :
            self .logger .info ("Не удалось привязать аккаунт: %s",exc )
            return False ,None ,SITE_UNAVAILABLE
    def list_devices (self )->tuple [bool ,list [dict [str ,Any ]],str ]:
        if not self .token :
            return False ,[],ACCOUNT_NOT_LINKED
        self ._apply_headers ()
        try :
            response =self .session .get (
            f"{self .base_url }/device-list.php",
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Список устройств не получен"))
            if response .status_code ==200 and data .get ("success"):
                devices =data .get ("devices",[])
                return True ,devices if isinstance (devices ,list )else [],message
            return False ,[],message
        except Exception as exc :
            self .logger .info ("Не удалось получить список устройств: %s",exc )
            return False ,[],SITE_UNAVAILABLE
    def get_device_notification_settings (self )->dict [str ,Any ]|None :
        if not self .device_id :
            return None
        try :
            response =self .session .post (
            f"{self .base_url }/get_device_notification_settings.php",
            json ={"device_id":self .device_id ,"device_token":self .device_token },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            if response .status_code ==200 and data .get ("success"):
                return data
            self .logger .info ("Настройки уведомлений не получены: %s",data .get ("message",""))
        except Exception as exc :
            self .logger .info ("Ошибка получения настроек уведомлений: %s",exc )
        return None
    def get_device_config (self )->dict [str ,Any ]|None :
        if not self .device_id or not (self .token or self .device_token):
            return None
        try :
            response =self .session .post (
            f"{self .base_url }/device-config-get.php",
            json ={"device_id":self .device_id ,"device_token":self .device_token },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            if response .status_code ==200 and data .get ("success"):
                return data
            self .logger .info ("Конфигурация устройства не получена: %s",data .get ("message",""))
        except Exception as exc :
            self .logger .info ("Ошибка получения конфигурации устройства: %s",exc )
        return None
    def save_device_config (
    self ,
    sensors :list [dict [str ,Any ]]|None =None ,
    relays :list [dict [str ,Any ]]|None =None ,
    scenarios :list [dict [str ,Any ]]|None =None ,
    )->bool :
        if not self .device_id or not (self .token or self .device_token):
            return False
        try :
            response =self .session .post (
            f"{self .base_url }/device-config-save.php",
            json ={
            "device_id":self .device_id ,
            "device_token":self .device_token ,
            "device_sensors":sensors or [],
            "device_relays":relays or [],
            "scenarios":scenarios or [],
            },
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            return response .status_code ==200 and data .get ("success")is True
        except Exception as exc :
            self .logger .info ("Ошибка сохранения конфигурации устройства: %s",exc )
            return False
    def upload_dynamic_sensor_values (self ,values :list [dict [str ,Any ]])->bool :
        if not values or not self .device_id or not (self .device_token or self .token):
            return False
        try :
            response =self .session .post (
            f"{self .base_url }/device-sensor-values-upload.php",
            json ={"device_id":self .device_id ,"device_token":self .device_token ,"values":values },
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            return response .status_code ==200 and data .get ("success")is True
        except Exception as exc :
            self .logger .info ("Ошибка отправки динамических датчиков: %s",exc )
            return False
    def send_scenario_log (
    self ,
    scenario_id :str ,
    message :str ,
    payload :dict [str ,Any ]|None =None ,
    event_type :str ="triggered",
    )->bool :
        if not self .device_id or not (self .device_token or self .token):
            return False
        try :
            response =self .session .post (
            f"{self .base_url }/device-scenario-log.php",
            json ={
            "device_id":self .device_id ,
            "device_token":self .device_token ,
            "scenario_id":scenario_id ,
            "event_type":event_type ,
            "message":message ,
            "payload":payload or {},
            },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            return response .status_code ==200 and data .get ("success")is True
        except Exception as exc :
            self .logger .info ("Ошибка отправки журнала сценария: %s",exc )
            return False
    def create_device_pair (self ,device_name :str )->tuple [bool ,dict [str ,Any ]|None ,str ]:
        device_name =device_name .strip ()or "FitoDomik"
        try :
            response =self .session .post (
            f"{self .base_url }/device-pair-create.php",
            json ={"device_name":device_name },
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Не удалось создать QR-код"))
            if response .status_code ==200 and data .get ("success"):
                return True ,data ,message
            return False ,None ,message
        except Exception as exc :
            self .logger .info ("Не удалось создать QR-привязку: %s",exc )
            return False ,None ,SITE_UNAVAILABLE
    def get_device_pair_status (self ,pair_code :str ,pair_secret :str )->tuple [bool ,dict [str ,Any ]|None ,str ]:
        pair_code =pair_code .strip ()
        pair_secret =pair_secret .strip ()
        if not pair_code or not pair_secret :
            return False ,None ,"Код привязки пустой"
        try :
            response =self .session .post (
            f"{self .base_url }/device-pair-status.php",
            json ={"pair_code":pair_code ,"pair_secret":pair_secret },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Статус привязки не получен"))
            return response .status_code ==200 and data .get ("success")is not False ,data ,message
        except Exception as exc :
            self .logger .info ("Не удалось проверить QR-привязку: %s",exc )
            return False ,None ,SITE_UNAVAILABLE
    def get_device_pair_token (self ,pair_code :str ,pair_secret :str )->tuple [bool ,dict [str ,Any ]|None ,str ]:
        pair_code =pair_code .strip ()
        pair_secret =pair_secret .strip ()
        if not pair_code or not pair_secret :
            return False ,None ,"Код привязки пустой"
        try :
            response =self .session .post (
            f"{self .base_url }/device-pair-token.php",
            json ={"pair_code":pair_code ,"pair_secret":pair_secret },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Токен привязки не получен"))
            if response .status_code ==200 and data .get ("success")and data .get ("token"):
                return True ,data ,message
            return False ,None ,message
        except Exception as exc :
            self .logger .info ("Не удалось получить токен QR-привязки: %s",exc )
            return False ,None ,SITE_UNAVAILABLE
    def unlink_device (self ,device_id :str )->tuple [bool ,str ]:
        self ._apply_headers ()
        if not self .token :
            return True ,"Устройство отвязано локально"
        try :
            response =self .session .post (
            f"{self .base_url }/device-unlink.php",
            json ={"device_id":device_id },
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Устройство отвязано"))
            return response .status_code ==200 and data .get ("success")is True ,message
        except Exception as exc :
            self .logger .info ("Не удалось отвязать устройство на сервере: %s",exc )
            return False ,"Сервер временно недоступен, локальная привязка очищена"
    def get_max_sensor_id (self )->int :
        if not self .token :
            return self .last_used_id
        try :
            response =self .session .get (
            f"{self .base_url }/get-max-sensor-id.php",
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            if response .status_code ==200 :
                data =response .json ()
                if data .get ("success")and "max_id"in data :
                    return int (data ["max_id"])
        except Exception as exc :
            self .logger .info ("Ошибка get-max-sensor-id: %s",exc )
        return self .last_used_id
    def send_sensor_data (self ,snapshot :SensorSnapshot )->bool :
        if not self .token or not snapshot .has_api_required_values ():
            return False
        data ={
        "temperature":snapshot .temperature ,
        "humidity":snapshot .humidity ,
        "soil_moisture":snapshot .soil_moisture if snapshot .soil_moisture is not None else 0 ,
        "light_level":snapshot .light_level if snapshot .light_level is not None else 0 ,
        "co2":int (snapshot .co2 )if snapshot .co2 is not None else 400 ,
        "pressure":snapshot .pressure if snapshot .pressure is not None else 760.0 ,
        "lamp_state":int (snapshot .lamp_state ),
        "curtains_state":int (snapshot .curtains_state ),
        }
        if self .device_id :
            data ["device_id"]=self .device_id
        if self .device_token :
            data ["device_token"]=self .device_token
        if self .device_name :
            data ["device_name"]=self .device_name
        try :
            response =self .session .post (
            f"{self .base_url }/save-sensor-data.php",
            data =data ,
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            payload =response .json ()
            if response .status_code ==200 and payload .get ("success"):
                saved =payload .get ("data",{})
                if isinstance (saved ,dict )and saved .get ("id"):
                    self .last_used_id =_safe_int (saved .get ("id"),self .last_used_id )
                return True
            self .logger .info ("Загрузка датчика отклонена: %s %s",response .status_code ,response .text [:200 ])
        except Exception as exc :
            self .logger .info ("Не удалось загрузить датчик: %s",exc )
        return False
    def send_sensor_record (self ,record :dict [str ,Any ])->bool :
        if (not self .token and not self .device_token)or record .get ("temperature")is None or record .get ("air_humidity")is None:
            return False
        data ={
        "device_id":record .get ("device_id")or self .device_id ,
        "device_token":self .device_token ,
        "temperature":record .get ("temperature"),
        "humidity":record .get ("air_humidity",record .get ("humidity")),
        "soil_moisture":record .get ("soil_moisture")or 0 ,
        "light_level":record .get ("light",record .get ("light_level",0 )),
        "co2":record .get ("co2")or 400 ,
        "pressure":record .get ("pressure")or 760.0 ,
        "lamp_state":int (bool (record .get ("lamp_state",False ))),
        "curtains_state":int (bool (record .get ("curtains_state",False ))),
        }
        try :
            response =self .session .post (
            f"{self .base_url }/save-sensor-data.php",
            data =data ,
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            return response .status_code ==200 and response .json ().get ("success")is True
        except Exception as exc :
            self .logger .info ("Не удалось догрузить запись датчика: %s",exc )
            return False
    def get_thresholds (self )->dict [str ,Any ]|None :
        if not self .token :
            return None
        data =self ._get_json ("get-thresholds.php",raw_thresholds =True )
        if isinstance (data ,dict )and data .get ("success")is False :
            return None
        return data
    def get_schedule (self )->dict [str ,Any ]|list [dict [str ,Any ]]|None :
        if not self .token :
            return None
        data =self ._get_json ("get-schedule.php")
        if isinstance (data ,dict )and data .get ("success"):
            return data .get ("data")
        return data
    def get_latest_sensor_data (self )->dict [str ,Any ]|None :
        data =self ._get_json ("get-sensor-data.php")
        if isinstance (data ,dict )and data .get ("success"):
            return data .get ("data")
        return None
    def upload_plant_images (
    self ,
    original_path :Path ,
    analysis_path :Path ,
    report_text :str ,
    )->bool :
        ok ,_payload =self .upload_plant_images_result (original_path ,analysis_path ,report_text )
        return ok
    def upload_plant_images_result (
    self ,
    original_path :Path ,
    analysis_path :Path ,
    report_text :str ,
    )->tuple [bool ,dict [str ,Any ]]:
        if not self .token :
            return False ,{"message":"API-аккаунт не привязан"}
        try :
            with original_path .open ("rb")as orig ,analysis_path .open ("rb")as analysis :
                files ={
                "image":("original.jpg",orig .read (),JPEG_MIME),
                "analysis_image":("analysis.jpg",analysis .read (),JPEG_MIME),
                }
            data ={
            "text":report_text ,
            "has_analysis":"true",
            }
            if self .device_id :
                data ["device_id"]=self .device_id
            if self .device_token :
                data ["device_token"]=self .device_token
            if self .device_name :
                data ["device_name"]=self .device_name
            response =self .session .post (
            f"{self .base_url }/upload-image.php",
            data =data ,
            files =files ,
            timeout =60 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            payload =self ._decode_response (response )
            return response .status_code ==200 and payload .get ("success")is True ,payload
        except Exception as exc :
            self .logger .info ("Не удалось загрузить изображение: %s",exc )
            return False ,{"message":str (exc )}
    def upload_photo_result (self ,photo_path :Path ,text :str ="Фото с камеры")->tuple [bool ,dict [str ,Any ]]:
        if not (self .token or self .device_token ):
            return False ,{"message":"API-аккаунт не привязан"}
        try :
            with photo_path .open ("rb")as photo :
                files ={"image":("photo.jpg",photo .read (),JPEG_MIME)}
            data ={"text":text ,"has_analysis":"false"}
            if self .device_id :
                data ["device_id"]=self .device_id
            if self .device_token :
                data ["device_token"]=self .device_token
            if self .device_name :
                data ["device_name"]=self .device_name
            response =self .session .post (
            f"{self .base_url }/upload-image.php",
            data =data ,
            files =files ,
            timeout =60 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            payload =self ._decode_response (response )
            return response .status_code ==200 and payload .get ("success")is True ,payload
        except Exception as exc :
            self .logger .info ("Не удалось загрузить фото: %s",exc )
            return False ,{"message":str (exc )}
    def report_device_fault (self ,event_key :str ,fault_code :str ,photo_file :str ="")->bool :
        if not self .device_id or not self .device_token :
            return False
        try :
            response =self .session .post (
            f"{self .base_url }/device-fault-report.php",
            json ={ "device_id":self .device_id ,"device_token":self .device_token ,
                    "event_key":event_key ,"fault_code":fault_code ,"photo_file":photo_file },
            timeout =10 ,verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            return response .status_code ==200 and self ._decode_response (response ).get ("success")is True
        except Exception as exc :
            self .logger .warning ("Не удалось передать аварию устройства: %s",exc )
            return False
    def poll_device_commands (self ,limit :int =5 )->list [dict [str ,Any ]]:
        if not self .device_id or not self .device_token :
            return []
        try :
            response =self .session .post (
            f"{self .base_url }/device-command-poll.php",
            json ={"device_id":self .device_id ,"device_token":self .device_token ,"limit":limit ,"delivery_client":getattr (self ,"delivery_client","")},
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            if response .status_code ==200 and data .get ("success"):
                commands =data .get ("commands",[])
                return commands if isinstance (commands ,list )else []
            self .logger .info ("Очередь команд не получена: %s",data .get ("message",""))
        except Exception as exc :
            self .logger .info ("Не удалось получить команды устройства: %s",exc )
        return []
    def send_device_command_result (
    self ,
    command_id :int ,
    status :str ,
    message :str ,
    result_payload :dict [str ,Any ]|None =None ,
    started_at :str ="" ,
    finished_at :str ="" ,
    )->bool :
        if not self .device_id or not self .device_token :
            return False
        data ={
        "device_id":self .device_id ,
        "device_token":self .device_token ,
        "command_id":command_id ,
        "status":status ,
        "message":message ,
        "result_payload":result_payload or {},
        }
        if started_at :
            data ["started_at"]=started_at
        if finished_at :
            data ["finished_at"]=finished_at
        try :
            response =self .session .post (
            f"{self .base_url }/device-command-result.php",
            json =data ,
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            payload =self ._decode_response (response )
            return response .status_code ==200 and payload .get ("success")is True
        except Exception as exc :
            self .logger .info ("Не удалось отправить результат команды: %s",exc )
            return False
    def list_sensor_profiles (self ,connection :str ="" ,query :str ="")->tuple [bool ,list [dict [str ,Any ]],str ]:
        if not self .token :
            return False ,[],ACCOUNT_NOT_LINKED
        params :dict [str ,str ]={}
        if connection :
            params ["connection"]=connection
        if query :
            params ["q"]=query
        try :
            response =self .session .get (
            f"{self .base_url }/sensor-profiles-list.php",
            params =params ,
            timeout =12 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Каталог профилей загружен"))
            if response .status_code ==200 and data .get ("success"):
                profiles =data .get ("profiles",[])
                return True ,profiles if isinstance (profiles ,list )else [],message
            return False ,[],message
        except Exception as exc :
            self .logger .info ("Не удалось получить каталог профилей: %s",exc )
            return False ,[],SITE_UNAVAILABLE
    def download_sensor_profile (self ,profile_id :str )->tuple [bool ,dict [str ,Any ]|None ,str ]:
        if not self .token :
            return False ,None ,ACCOUNT_NOT_LINKED
        try :
            response =self .session .get (
            f"{self .base_url }/sensor-profile-download.php",
            params ={"profile_id":profile_id },
            timeout =12 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            message =str (data .get ("message","Профиль скачан"))
            if response .status_code ==200 and data .get ("success"):
                profile =data .get ("profile")
                return True ,profile if isinstance (profile ,dict )else None ,message
            return False ,None ,message
        except Exception as exc :
            self .logger .info ("Не удалось скачать профиль: %s",exc )
            return False ,None ,SITE_UNAVAILABLE
    def save_sensor_profile (self ,profile :dict [str ,Any ] ,is_public :bool =False )->tuple [bool ,str ]:
        if not self .token :
            return False ,ACCOUNT_NOT_LINKED
        try :
            response =self .session .post (
            f"{self .base_url }/sensor-profile-save.php",
            json ={"profile":profile ,"is_public":bool (is_public )},
            timeout =15 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            return response .status_code ==200 and data .get ("success")is True ,str (data .get ("message","Профиль сохранён"))
        except Exception as exc :
            self .logger .info ("Не удалось сохранить профиль на сайте: %s",exc )
            return False ,SITE_UNAVAILABLE
    def delete_sensor_profile (self ,profile_id :str )->tuple [bool ,str ]:
        if not self .token :
            return False ,ACCOUNT_NOT_LINKED
        try :
            response =self .session .post (
            f"{self .base_url }/sensor-profile-delete.php",
            json ={"profile_id":profile_id },
            timeout =12 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            data =self ._decode_response (response )
            return response .status_code ==200 and data .get ("success")is True ,str (data .get ("message","Профиль удалён"))
        except Exception as exc :
            self .logger .info ("Не удалось удалить профиль на сайте: %s",exc )
            return False ,SITE_UNAVAILABLE
    def _get_json (self ,endpoint :str ,raw_thresholds :bool =False )->Any :
        try :
            response =self .session .get (
            f"{self .base_url }/{endpoint }",
            timeout =10 ,
            verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
            )
            if response .status_code !=200 :
                self .logger .info ("%s вернул %s",endpoint ,response .status_code )
                return None
            data =response .json ()
            if raw_thresholds :
                return data
            return data
        except Exception as exc :
            self .logger .info ("Запрос %s не выполнен: %s",endpoint ,exc )
            return None
    def _decode_response (self ,response :Any )->dict [str ,Any ]:
        try :
            data =response .json ()
        except ValueError :
            return {"success":False ,"message":INVALID_SITE_RESPONSE}
        return data if isinstance (data ,dict )else {"success":False ,"message":INVALID_SITE_RESPONSE}
    def _apply_headers (self )->None :
        self ._apply_headers_to_session (self ._ensure_session ())
    def _apply_headers_to_session (self ,session )->None :
        session .headers .clear ()
        session .headers .update ({"Accept":"application/json"})
        if self .token :
            session .headers .update ({"X-Auth-Token":self .token })
        if self .device_id :
            session .headers .update ({"X-Device-ID":self .device_id })
        if self .device_token :
            session .headers .update ({"X-Device-Token":self .device_token })
