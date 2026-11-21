from __future__ import annotations
import tkinter as tk
from typing import Any
from tkinter import ttk
from fitodomik_app .ui .style_names import ACCENT_BUTTON_STYLE ,APP_FRAME_STYLE ,MUTED_LABEL_STYLE ,SURFACE_FRAME_STYLE ,SURFACE_LABEL_STYLE
from fitodomik_app .ui .widgets import Card ,ScrollableFrame ,clear_frame
PARAMETERS =[
("temperature","Температура","°C",{"min":18 ,"max":28 ,"target":23 ,"tolerance":2 }),
("humidity_air","Влажность воздуха","%",{"min":40 ,"max":80 ,"target":60 ,"tolerance":5 }),
("humidity_soil","Влажность почвы","%",{"min":30 ,"max":70 ,"target":50 ,"tolerance":10 }),
("co2","CO2","ppm",{"min":400 ,"max":1200 ,"target":800 ,"tolerance":100 }),
]
class ThresholdsTab (ttk .Frame ):
    def __init__ (self ,parent ,app )->None :
        super ().__init__ (parent ,style =APP_FRAME_STYLE)
        self .app =app
        self .mode_var =tk .StringVar (value =self .app .settings .get ("thresholds_mode","auto"))
        self .manual_vars :dict [str ,dict [str ,tk .StringVar ]]={}
        self .lamp_hour_vars :dict [int ,tk .BooleanVar ]={}
        self .curtain_hour_vars :dict [int ,tk .BooleanVar ]={}
        self .auto_cards :list [Card ]=[]
        self ._build ()
    def _build (self )->None :
        self .top =ttk .Frame (self ,style =APP_FRAME_STYLE)
        self .top .pack (fill ="x",padx =self .app .pad (14 ),pady =(self .app .pad (14 ),self .app .pad (5 )))
        ttk .Label (self .top ,text ="Пороги",style ="Title.TLabel").pack (side ="left")
        self .actions =ttk .Frame (self ,style =APP_FRAME_STYLE)
        self .actions .pack (fill ="x",padx =self .app .pad (14 ),pady =(0 ,self .app .pad (4 )))
        self .refresh_button =ttk .Button (self .actions ,text ="Обновить пороги",style =ACCENT_BUTTON_STYLE,command =self .app .refresh_thresholds )
        self .refresh_button .pack (side ="left",padx =(0 ,self .app .pad (4 )))
        self .upload_button =ttk .Button (self .actions ,text ="Загрузить в Arduino",style ="Small.TButton",command =self .app .upload_thresholds_to_arduino )
        self .upload_button .pack (side ="left",padx =self .app .pad (4 ))
        self .mode_frame =ttk .Frame (self ,style =APP_FRAME_STYLE)
        self .mode_frame .pack (fill ="x",padx =self .app .pad (14 ),pady =self .app .pad (3 ))
        self .auto_radio =ttk .Radiobutton (self .mode_frame ,text ="Автоматический",variable =self .mode_var ,value ="auto",command =self ._mode_changed )
        self .auto_radio .pack (side ="left",padx =self .app .pad (6 ))
        self .manual_radio =ttk .Radiobutton (self .mode_frame ,text ="Ручной",variable =self .mode_var ,value ="manual",command =self ._mode_changed )
        self .manual_radio .pack (side ="left",padx =self .app .pad (6 ))
        self .status_label =ttk .Label (self .mode_frame ,text ="Ожидание данных",style =MUTED_LABEL_STYLE)
        self .status_label .pack (side ="right")
        self .scroll =ScrollableFrame (self ,self .app .palette )
        self .scroll .pack (fill ="both",expand =True ,padx =self .app .pad (14 ),pady =(self .app .pad (4 ),self .app .pad (12 )))
        self .content =self .scroll .inner
        self ._mode_changed ()
    def _mode_changed (self )->None :
        mode =self .mode_var .get ()
        self .app .settings .set ("thresholds_mode",mode ,save =True )
        clear_frame (self .content )
        self .auto_cards =[]
        if mode =="auto":
            self ._build_auto ()
            self .app .refresh_thresholds ()
        else :
            self ._build_manual ()
    def _build_auto (self )->None :
        self .thresholds_card =Card (self .content ,"Пороговые значения с сервера",self .app .palette ,padding =self .app .pad (12 ),title_font =self .app .theme .fonts ["card_title"])
        self .thresholds_body =ttk .Frame (self .thresholds_card ,style =SURFACE_FRAME_STYLE)
        self .thresholds_body .pack (fill ="x")
        ttk .Label (self .thresholds_body ,text ="Загрузка...",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
        self .schedule_card =Card (self .content ,"Расписание с сервера",self .app .palette ,padding =self .app .pad (12 ),title_font =self .app .theme .fonts ["card_title"])
        self .schedule_body =ttk .Frame (self .schedule_card ,style =SURFACE_FRAME_STYLE)
        self .schedule_body .pack (fill ="x")
        ttk .Label (self .schedule_body ,text ="Загрузка...",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
        self .auto_cards =[self .thresholds_card ,self .schedule_card ]
        self ._layout_auto_cards ()
    def _build_manual (self )->None :
        thresholds =self .app .settings .get ("manual_thresholds",{})
        self .manual_vars ={}
        card =Card (self .content ,"Ручные пороги",self .app .palette ,padding =self .app .pad (12 ),title_font =self .app .theme .fonts ["card_title"])
        card .pack (fill ="x",pady =self .app .pad (6 ))
        for key ,title ,unit ,defaults in PARAMETERS :
            saved =thresholds .get (key ,defaults )
            row =ttk .Frame (card ,style =SURFACE_FRAME_STYLE)
            row .pack (fill ="x",pady =4 )
            ttk .Label (row ,text =title ,style =SURFACE_LABEL_STYLE,width =18 ).pack (side ="left")
            self .manual_vars [key ]={}
            for field in ("min","max","target","tolerance"):
                ttk .Label (row ,text =field ,style =MUTED_LABEL_STYLE).pack (side ="left",padx =(8 ,2 ))
                var =tk .StringVar (value =str (saved .get (field ,defaults [field ])))
                entry =ttk .Entry (row ,width =7 ,textvariable =var )
                entry .pack (side ="left")
                self .manual_vars [key ][field ]=var
            ttk .Label (row ,text =unit ,style =MUTED_LABEL_STYLE).pack (side ="left",padx =6 )
        ttk .Button (card ,text ="Сохранить ручные пороги",style =ACCENT_BUTTON_STYLE,command =self .save_manual_thresholds ).pack (anchor ="e",pady =(8 ,0 ))
        schedule =self .app .settings .get ("manual_schedule",{})or {
        "lamp":{"active_hours":list (range (8 ,20 ))},
        "curtains":{"active_hours":list (range (8 ,20 ))},
        }
        schedule_card =Card (self .content ,"Ручное расписание",self .app .palette ,padding =self .app .pad (12 ),title_font =self .app .theme .fonts ["card_title"])
        schedule_card .pack (fill ="x",pady =self .app .pad (6 ))
        self .lamp_hour_vars =self ._hour_grid (schedule_card ,"Лампа",schedule .get ("lamp",{}).get ("active_hours",[]))
        self .curtain_hour_vars =self ._hour_grid (schedule_card ,"Шторы",schedule .get ("curtains",{}).get ("active_hours",[]))
        ttk .Button (schedule_card ,text ="Сохранить расписание",style =ACCENT_BUTTON_STYLE,command =self .save_manual_schedule ).pack (anchor ="e",pady =(8 ,0 ))
        self .status_label .configure (text ="Ручной режим: данные хранятся в settings.json")
    def set_thresholds (self ,thresholds :dict [str ,Any ]|None )->None :
        if self .mode_var .get ()!="auto"or not hasattr (self ,"thresholds_body"):
            return
        clear_frame (self .thresholds_body )
        if not thresholds :
            ttk .Label (self .thresholds_body ,text ="Не удалось получить пороги",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
            self .status_label .configure (text ="Ошибка загрузки порогов")
            return
        for key ,title ,unit ,_defaults in PARAMETERS :
            values =thresholds .get (key ,{})
            text =(
            f"{title }: {values .get ('min_limit','Н/Д')} - {values .get ('max_limit','Н/Д')} {unit }; "
            f"цель {values .get ('target_value','Н/Д')} {unit }; допуск {values .get ('tolerance','Н/Д')}"
            )
            ttk .Label (self .thresholds_body ,text =text ,style =SURFACE_LABEL_STYLE).pack (anchor ="w",pady =2 )
        self .status_label .configure (text ="Пороги обновлены")
    def set_schedule (self ,schedule_data :Any )->None :
        if self .mode_var .get ()!="auto"or not hasattr (self ,"schedule_body"):
            return
        clear_frame (self .schedule_body )
        if isinstance (schedule_data ,dict )and "schedule"in schedule_data :
            schedule =schedule_data ["schedule"]
        else :
            schedule =schedule_data if isinstance (schedule_data ,list )else []
        if not schedule :
            ttk .Label (self .schedule_body ,text ="Расписание не получено",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
            return
        lamp_hours =[self ._hour (item )for item in schedule if item .get ("lighting_active")]
        curtain_hours =[self ._hour (item )for item in schedule if item .get ("curtains_active")]
        ttk .Label (self .schedule_body ,text =f"Лампа: {self ._format_hours (lamp_hours )}",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
        ttk .Label (self .schedule_body ,text =f"Шторы: {self ._format_hours (curtain_hours )}",style =SURFACE_LABEL_STYLE).pack (anchor ="w")
    def set_upload_status (self ,message :str )->None :
        self .status_label .configure (text =message )
    def refresh_palette (self )->None :
        if hasattr (self ,"scroll"):
            self .scroll .set_palette (self .app .palette )
        self .update_idletasks ()
    def refresh_scale (self )->None :
        self .top .pack_configure (padx =self .app .pad (14 ),pady =(self .app .pad (14 ),self .app .pad (5 )))
        self .actions .pack_configure (padx =self .app .pad (14 ),pady =(0 ,self .app .pad (4 )))
        self .refresh_button .pack_configure (padx =(0 ,self .app .pad (4 )))
        self .upload_button .pack_configure (padx =self .app .pad (4 ))
        self .mode_frame .pack_configure (padx =self .app .pad (14 ),pady =self .app .pad (3 ))
        self .auto_radio .pack_configure (padx =self .app .pad (6 ))
        self .manual_radio .pack_configure (padx =self .app .pad (6 ))
        self .scroll .pack_configure (padx =self .app .pad (14 ),pady =(self .app .pad (4 ),self .app .pad (12 )))
        self ._refresh_cards (self .content )
        self ._layout_auto_cards ()
        self .refresh_palette ()
    def _refresh_cards (self ,parent )->None :
        for child in parent .winfo_children ():
            if isinstance (child ,Card ):
                child .set_padding (self .app .pad (12 ))
                child .set_title_font (self .app .theme .fonts ["card_title"])
            self ._refresh_cards (child )
    def _layout_auto_cards (self )->None :
        if not self .auto_cards :
            return
        for col in range (2 ):
            self .content .columnconfigure (col ,weight =0 )
        for card in self .auto_cards :
            card .grid_forget ()
            try :
                card .pack_forget ()
            except tk .TclError :
                pass
        for card in self .auto_cards :
            card .pack (fill ="x",expand =True ,pady =self .app .pad (6 ))
    def save_manual_thresholds (self )->None :
        data :dict [str ,dict [str ,float ]]={}
        try :
            for key ,fields in self .manual_vars .items ():
                data [key ]={field :float (var .get ())for field ,var in fields .items ()}
            self .app .settings .set ("manual_thresholds",data ,save =True )
            self .status_label .configure (text ="Ручные пороги сохранены")
            if self .app .settings .get ("control_mode")=="auto":
                self .app .upload_thresholds_to_arduino ()
        except ValueError :
            self .status_label .configure (text ="Введите числовые значения")
    def save_manual_schedule (self )->None :
        data ={
        "lamp":{"active_hours":[hour for hour ,var in self .lamp_hour_vars .items ()if var .get ()]},
        "curtains":{"active_hours":[hour for hour ,var in self .curtain_hour_vars .items ()if var .get ()]},
        }
        self .app .settings .set ("manual_schedule",data ,save =True )
        self .status_label .configure (text ="Ручное расписание сохранено")
        if self .app .settings .get ("control_mode")=="auto":
            self .app .upload_thresholds_to_arduino ()
    def _hour_grid (self ,parent ,title :str ,active_hours :list [int ])->dict [int ,tk .BooleanVar ]:
        ttk .Label (parent ,text =title ,style =SURFACE_LABEL_STYLE,font =("DejaVu Sans",10 ,"bold")).pack (anchor ="w",pady =(8 ,2 ))
        frame =ttk .Frame (parent ,style =SURFACE_FRAME_STYLE)
        frame .pack (fill ="x")
        result :dict [int ,tk .BooleanVar ]={}
        for hour in range (24 ):
            var =tk .BooleanVar (value =hour in active_hours )
            check =ttk .Checkbutton (frame ,text =f"{hour :02d}",variable =var )
            check .grid (row =hour //12 ,column =hour %12 ,sticky ="w",padx =4 ,pady =2 )
            result [hour ]=var
        return result
    def _hour (self ,item :dict [str ,Any ])->int :
        try :
            return int (str (item .get ("time_interval",item .get ("hour","0"))).split (":")[0 ])
        except Exception :
            return 0
    def _format_hours (self ,hours :list [int ])->str :
        if not hours :
            return "нет активных периодов"
        return ", ".join (f"{hour :02d}:00"for hour in sorted (set (hours )))
