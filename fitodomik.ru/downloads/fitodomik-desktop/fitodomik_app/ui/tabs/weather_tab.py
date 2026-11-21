from __future__ import annotations
from datetime import datetime
from tkinter import ttk
from fitodomik_app .ui .style_names import ACCENT_BUTTON_STYLE ,APP_FRAME_STYLE ,MUTED_LABEL_STYLE ,SURFACE_FRAME_STYLE ,SURFACE_LABEL_STYLE
from fitodomik_app .core .models import WeatherData
from fitodomik_app .ui .widgets import Card
class WeatherTab (ttk .Frame ):
    def __init__ (self ,parent ,app )->None :
        super ().__init__ (parent ,style =APP_FRAME_STYLE)
        self .app =app
        self .weather_icon =None
        self ._weather_data :WeatherData |None =None
        self ._build ()
        self ._tick ()
    def _build (self )->None :
        palette =self .app .palette
        self .hero =ttk .Frame (self ,style =APP_FRAME_STYLE)
        self .hero .pack (fill ="both",expand =True ,padx =self .app .pad (14 ),pady =self .app .pad (14 ))
        self .hero .columnconfigure (0 ,weight =3 )
        self .hero .columnconfigure (1 ,weight =2 )
        self .hero .rowconfigure (0 ,weight =1 )
        self .clock_card =Card (self .hero ,"",palette ,padding =self .app .pad (14 ))
        self .clock_card .grid (row =0 ,column =0 ,sticky ="nsew",padx =(0 ,self .app .pad (10 )))
        self .time_label =ttk .Label (self .clock_card ,text ="00:00:00",style =SURFACE_LABEL_STYLE,font =self .app .theme .fonts ["clock"])
        self .time_label .pack (anchor ="center",expand =True )
        self .date_label =ttk .Label (self .clock_card ,text ="",style =MUTED_LABEL_STYLE,font =self .app .font_tuple (15 ,maximum =21 ))
        self .date_label .pack (anchor ="center",pady =(0 ,self .app .pad (12 )))
        self .weather_card =Card (self .hero ,"Погода",palette ,padding =self .app .pad (18 ),title_font =self .app .theme .fonts ["card_title"])
        self .weather_card .grid (row =0 ,column =1 ,sticky ="nsew",padx =(self .app .pad (10 ),0 ))
        self .weather_top_spacer =ttk .Frame (self .weather_card ,style =SURFACE_FRAME_STYLE,height =0 )
        self .weather_top_spacer .pack (fill ="both",expand =False )
        self .city_label =ttk .Label (self .weather_card ,text =self .app .settings .get ("weather_city","Moscow"),style =SURFACE_LABEL_STYLE,font =self .app .font_tuple (18 ,"bold",maximum =24 ))
        self .city_label .pack (anchor ="w")
        self .icon_label =ttk .Label (self .weather_card ,text ="",style =SURFACE_LABEL_STYLE)
        self .icon_label .pack (anchor ="center",pady =(self .app .pad (8 ),self .app .pad (2 )))
        self .temp_label =ttk .Label (self .weather_card ,text ="--°C",style =SURFACE_LABEL_STYLE,font =self .app .font_tuple (34 ,"bold",maximum =46 ))
        self .temp_label .pack (anchor ="center")
        self .condition_label =ttk .Label (self .weather_card ,text ="Загрузка...",style =MUTED_LABEL_STYLE,wraplength =self .app .sx (260 ,minimum =260 ,maximum =390 ))
        self .condition_label .pack (anchor ="center",pady =(self .app .pad (2 ),self .app .pad (16 )))
        self .details_label =ttk .Label (self .weather_card ,text ="Влажность: --    Ветер: --",style =SURFACE_LABEL_STYLE,font =self .app .font_tuple (12 ,maximum =17 ))
        self .details_label .pack (anchor ="center")
        self .refresh_button =ttk .Button (self .weather_card ,text ="Обновить погоду",style =ACCENT_BUTTON_STYLE,command =self .app .refresh_weather )
        self .refresh_button .pack (anchor ="center",pady =(self .app .pad (18 ),0 ))
        self .weather_bottom_spacer =ttk .Frame (self .weather_card ,style =SURFACE_FRAME_STYLE,height =0 )
        self .weather_bottom_spacer .pack (fill ="both",expand =False )
        self ._position_weather_content ()
    def set_weather (self ,data :WeatherData )->None :
        self ._weather_data =data
        self .city_label .configure (text =data .city )
        self .temp_label .configure (text =f"{data .temperature_c :.0f}°C")
        self .condition_label .configure (text =data .condition )
        self .details_label .configure (text =f"Влажность: {data .humidity }%    Ветер: {data .wind_ms } м/с")
        icon =self .app .icons .get (self ._weather_icon_name (data .condition ),self .app .icon_size (82 ,maximum =104 ))
        if icon :
            self .weather_icon =icon
            self .icon_label .configure (image =icon ,text ="")
            return
        if data .icon_bytes :
            photo =self .app .icons .from_bytes (data .icon_bytes ,self .app .icon_size (80 ,maximum =104 ))
            if photo :
                self .weather_icon =photo
                self .icon_label .configure (image =photo ,text ="")
                return
        icon =self .app .icons .get ("weather",self .app .icon_size (72 ,maximum =96 ))
        self .weather_icon =icon
        self .icon_label .configure (image =icon ,text =""if icon else "Погода")
    def refresh_scale (self )->None :
        self .hero .pack_configure (padx =self .app .pad (14 ),pady =self .app .pad (14 ))
        self .clock_card .set_padding (self .app .pad (14 ))
        self .clock_card .grid_configure (padx =(0 ,self .app .pad (10 )))
        self .time_label .configure (font =self .app .theme .fonts ["clock"])
        self .date_label .configure (font =self .app .font_tuple (15 ,maximum =21 ))
        self .date_label .pack_configure (pady =(0 ,self .app .pad (12 )))
        self .weather_card .set_padding (self .app .pad (18 ))
        self .weather_card .set_title_font (self .app .theme .fonts ["card_title"])
        self .weather_card .grid_configure (padx =(self .app .pad (10 ),0 ))
        self .city_label .configure (font =self .app .font_tuple (18 ,"bold",maximum =24 ))
        self .icon_label .pack_configure (pady =(self .app .pad (8 ),self .app .pad (2 )))
        self .temp_label .configure (font =self .app .font_tuple (34 ,"bold",maximum =46 ))
        self .condition_label .configure (wraplength =self .app .sx (260 ,minimum =260 ,maximum =390 ))
        self .condition_label .pack_configure (pady =(self .app .pad (2 ),self .app .pad (16 )))
        self .details_label .configure (font =self .app .font_tuple (12 ,maximum =17 ))
        self .refresh_button .pack_configure (pady =(self .app .pad (18 ),0 ))
        self ._position_weather_content ()
        if self ._weather_data :
            self .set_weather (self ._weather_data )
    def _position_weather_content (self )->None :
        centered =self .app .content_width ()>=900 or self .app .theme .scale .value >=1.2
        if centered :
            self .weather_top_spacer .configure (height =1 )
            self .weather_bottom_spacer .configure (height =1 )
            self .weather_top_spacer .pack_configure (fill ="both",expand =True )
            self .weather_bottom_spacer .pack_configure (fill ="both",expand =True )
        else :
            self .weather_top_spacer .configure (height =0 )
            self .weather_bottom_spacer .configure (height =0 )
            self .weather_top_spacer .pack_configure (fill ="both",expand =False )
            self .weather_bottom_spacer .pack_configure (fill ="both",expand =False )
    def _weather_icon_name (self ,condition :str )->str :
        text =condition .lower ()
        if any (word in text for word in ("гроза","thunder","storm")):
            return "weather_storm"
        if any (word in text for word in ("снег","метель","измороз","snow","sleet","blizzard")):
            return "weather_snow"
        if any (word in text for word in ("морось","drizzle")):
            return "weather_drizzle"
        if any (word in text for word in ("дожд","ливень","rain","shower")):
            return "weather_rain"
        if any (word in text for word in ("туман","дымка","mist","fog","haze")):
            return "weather_fog"
        if any (word in text for word in ("переменная","частично","partly")):
            return "weather_partly"
        if any (word in text for word in ("пасмур","облач","overcast","cloud")):
            return "weather_cloud"
        if any (word in text for word in ("ясно","солнечно","clear","sun")):
            return "weather_sun"
        return "weather"
    def set_error (self )->None :
        self .temp_label .configure (text ="--°C")
        self .condition_label .configure (text ="Погода временно недоступна")
        self .details_label .configure (text ="Влажность: --    Ветер: --")
    def _tick (self )->None :
        now =datetime .now ()
        self .time_label .configure (text =now .strftime ("%H:%M:%S"))
        self .date_label .configure (text =now .strftime ("%d.%m.%Y"))
        self .after (1000 ,self ._tick )
