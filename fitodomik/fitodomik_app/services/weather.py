from __future__ import annotations
import logging
import os
import time
from typing import Any
from fitodomik_app .core .models import WeatherData
from fitodomik_app .core .settings import SettingsManager
_REQUESTS_MODULE =None
def _requests_module ():
    global _REQUESTS_MODULE
    if _REQUESTS_MODULE is None :
        import requests
        _REQUESTS_MODULE =requests
    return _REQUESTS_MODULE
class WeatherService :
    def __init__ (self ,settings :SettingsManager ,logger :logging .Logger |None =None )->None :
        self .settings =settings
        self .logger =(logger or logging .getLogger ("fitodomik")).getChild ("weather")
        self .last_error =""
    def fetch_current (self ,max_seconds :float =10.0 )->WeatherData |None :
        keys =self .settings .get ("weather_api_keys",[])
        city =self .settings .get ("weather_city","Moscow")
        self .last_error =""
        deadline =time .monotonic ()+max_seconds
        weatherapi_deadline =time .monotonic ()+min (max_seconds *0.6 ,6.0 )
        cities =self ._candidate_cities (city )
        if keys :
            data =self ._fetch_weatherapi (keys ,cities ,deadline ,weatherapi_deadline )
            if data :
                return data
        data =self ._fetch_open_meteo_fallback (city ,deadline )
        if data :
            return data
        self .last_error =self .last_error or "Все WeatherAPI ключи не ответили"
        return None
    def _fetch_weatherapi (
    self ,
    keys :list [str ],
    cities :list [str ],
    deadline :float ,
    weatherapi_deadline :float ,
    )->WeatherData |None :
        start_index =int (self .settings .get ("weather_api_key_index",0 ))%len (keys )
        for city_candidate in cities :
            data =self ._try_weatherapi_city (keys ,start_index ,city_candidate ,deadline ,weatherapi_deadline )
            if data :
                return data
        return None
    def _try_weatherapi_city (
    self ,
    keys :list [str ],
    start_index :int ,
    city_candidate :str ,
    deadline :float ,
    weatherapi_deadline :float ,
    )->WeatherData |None :
        for offset in range (len (keys )):
            remaining =min (deadline ,weatherapi_deadline )-time .monotonic ()
            if remaining <=0 :
                return None
            index =(start_index +offset )%len (keys )
            try :
                data =self ._request_weather (keys [index ],city_candidate ,timeout =max (1.0 ,min (1.5 ,remaining )))
                if data :
                    self .settings .set ("weather_api_key_index",index ,save =True )
                    return data
            except Exception as exc :
                self .last_error =str (exc )
                self .logger .info ("Ошибка ключа погоды %s, город %s: %s",index ,city_candidate ,exc )
        return None
    def _fetch_open_meteo_fallback (self ,city :str ,deadline :float )->WeatherData |None :
        remaining =deadline -time .monotonic ()
        if remaining <=1 :
            return None
        try :
            return self ._request_open_meteo (city ,timeout =max (1.0 ,min (remaining ,4.0 )))
        except Exception as exc :
            self .last_error =str (exc )
            self .logger .info ("Не удалось выполнить резервный переход Open-Meteo: %s",exc )
            return None
    def check_api_keys (self ,city :str |None =None ,timeout_per_key :float =2.0 )->tuple [bool ,str ]:
        keys =self .settings .get ("weather_api_keys",[])
        target_city =str (city or self .settings .get ("weather_city","Moscow")).strip ()or "Moscow"
        if not keys :
            return False ,"В settings.json нет WeatherAPI токенов"
        errors :list [str ]=[]
        for index ,key in enumerate (keys ):
            try :
                response =_requests_module ().get (
                "https://api.weatherapi.com/v1/current.json",
                params ={"key":key ,"q":target_city ,"lang":"ru"},
                timeout =timeout_per_key ,
                verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
                )
                if response .status_code ==200 :
                    self .settings .set ("weather_api_key_index",index ,save =True )
                    return True ,f"Токены работают: ключ {index +1 } получил погоду для {target_city }"
                try :
                    details =response .json ().get ("error",{}).get ("message",response .text [:100 ])
                except Exception :
                    details =response .text [:100 ]
                errors .append (f"ключ {index +1 }: HTTP {response .status_code } {details }")
            except Exception as exc :
                errors .append (f"ключ {index +1 }: {exc }")
        details ="; ".join (errors [:3 ])
        if len (errors )>3 :
            details +=f"; ещё ошибок: {len (errors )-3 }"
        return False ,f"WeatherAPI токены не ответили для {target_city }. {details }"
    def _request_weather (self ,key :str ,city :str ,timeout :float )->WeatherData |None :
        response =_requests_module ().get (
        "https://api.weatherapi.com/v1/current.json",
        params ={"key":key ,"q":city ,"lang":"ru"},
        timeout =timeout ,
        verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
        )
        if response .status_code !=200 :
            try :
                details =response .json ().get ("error",{}).get ("message",response .text [:120 ])
            except Exception :
                details =response .text [:120 ]
            self .last_error =f"HTTP {response .status_code }: {details }"
            return None
        payload :dict [str ,Any ]=response .json ()
        current =payload ["current"]
        condition =current ["condition"]
        icon_bytes =None
        icon_url =condition .get ("icon")
        if icon_url :
            try :
                icon_response =_requests_module ().get (
                f"https:{icon_url }",
                timeout =min (2.0 ,timeout ),
                verify =os .environ .get ("REQUESTS_CA_BUNDLE",True ),
                )
                if icon_response .status_code ==200 :
                    icon_bytes =icon_response .content
            except Exception :
                icon_bytes =None
        return WeatherData (
        city =city ,
        temperature_c =float (current ["temp_c"]),
        condition =str (condition ["text"]),
        humidity =int (current ["humidity"]),
        wind_ms =round (float (current ["wind_kph"])*0.277778 ,1 ),
        icon_bytes =icon_bytes ,
        )
    def _candidate_cities (self ,city :str )->list [str ]:
        result =[]
        for value in (city ,"Moscow","Moscow,Russia","auto:ip"):
            clean =str (value ).strip ()
            if clean and clean not in result :
                result .append (clean )
        return result
    def _request_open_meteo (self ,city :str ,timeout :float )->WeatherData |None :
        latitude ,longitude ,display_city =self ._resolve_city_coordinates (city ,timeout =min (2.0 ,timeout ))
        response =_requests_module ().get (
        "https://api.open-meteo.com/v1/forecast",
        params ={
        "latitude":latitude ,
        "longitude":longitude ,
        "current":"temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code",
        "timezone":"auto",
        },
        timeout =timeout ,
        )
        if response .status_code !=200 :
            self .last_error =f"Open-Meteo HTTP {response .status_code }: {response .text [:120 ]}"
            return None
        current =response .json ().get ("current",{})
        return WeatherData (
        city =display_city ,
        temperature_c =float (current .get ("temperature_2m",0.0 )),
        condition =self ._weather_code_text (current .get ("weather_code")),
        humidity =int (current .get ("relative_humidity_2m",0 )),
        wind_ms =round (float (current .get ("wind_speed_10m",0.0 ))*0.277778 ,1 ),
        icon_bytes =None ,
        )
    def _resolve_city_coordinates (self ,city :str ,timeout :float )->tuple [float ,float ,str ]:
        clean =str (city ).strip ()
        if clean .lower ()in {"moscow","москва","moscow,russia",""}:
            return 55.7558 ,37.6173 ,"Moscow"
        response =_requests_module ().get (
        "https://geocoding-api.open-meteo.com/v1/search",
        params ={"name":clean ,"count":1 ,"language":"ru","format":"json"},
        timeout =timeout ,
        )
        if response .status_code ==200 :
            results =response .json ().get ("results",[])
            if results :
                item =results [0 ]
                return float (item ["latitude"]),float (item ["longitude"]),str (item .get ("name",clean ))
        return 55.7558 ,37.6173 ,"Moscow"
    def _weather_code_text (self ,code :Any )->str :
        try :
            value =int (code )
        except (TypeError ,ValueError ):
            return "Погода получена через Open-Meteo"
        mapping ={
        0 :"Ясно",
        1 :"Преимущественно ясно",
        2 :"Переменная облачность",
        3 :"Пасмурно",
        45 :"Туман",
        48 :"Изморозь",
        51 :"Слабая морось",
        53 :"Морось",
        55 :"Сильная морось",
        61 :"Слабый дождь",
        63 :"Дождь",
        65 :"Сильный дождь",
        71 :"Слабый снег",
        73 :"Снег",
        75 :"Сильный снег",
        80 :"Слабый ливень",
        81 :"Ливень",
        82 :"Сильный ливень",
        95 :"Гроза",
        }
        return mapping .get (value ,"Погода получена через Open-Meteo")