from __future__ import annotations
import tkinter as tk
from dataclasses import dataclass
from datetime import datetime ,time
from tkinter import ttk
from typing import Any
from fitodomik_app .core .settings import SettingsManager
BASE_UI_WIDTH =800
BASE_UI_HEIGHT =480
MIN_UI_SCALE =1.0
MAX_UI_SCALE =1.5
def _clamp (value :float ,minimum :float ,maximum :float )->float :
    return max (minimum ,min (maximum ,value ))
def safe_float (value :Any ,default :float =1.0 )->float :
    try :
        if value is None :
            return default
        return float (value )
    except (TypeError ,ValueError ):
        return default
def resolve_ui_scale (setting :object ,width :int |float ,height :int |float )->float :
    if str (setting ).strip ().lower ()!="auto":
        return round (_clamp (safe_float (setting ),MIN_UI_SCALE ,MAX_UI_SCALE ),3 )
    safe_width =max (BASE_UI_WIDTH ,int (width or BASE_UI_WIDTH ))
    safe_height =max (BASE_UI_HEIGHT ,int (height or BASE_UI_HEIGHT ))
    scale =min (safe_width /BASE_UI_WIDTH ,safe_height /BASE_UI_HEIGHT )
    return round (_clamp (scale ,MIN_UI_SCALE ,MAX_UI_SCALE ),3 )
@dataclass (frozen =True ,slots =True )
class UIScale :
    value :float =1.0
    font_scale :float =1.0
    padding_scale :float =1.0
    card_scale :float =1.0
    @classmethod
    def from_value (cls ,value :float )->"UIScale":
        value =_clamp (safe_float (value ),MIN_UI_SCALE ,MAX_UI_SCALE )
        font_scale =_clamp (1.0 +(value -1.0 )*0.78 ,1.0 ,1.36 )
        padding_scale =_clamp (1.0 +(value -1.0 )*0.9 ,1.0 ,1.42 )
        card_scale =_clamp (1.0 +(value -1.0 )*0.95 ,1.0 ,1.48 )
        return cls (value =round (value ,3 ),font_scale =font_scale ,padding_scale =padding_scale ,card_scale =card_scale )
    def sx (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        result =int (round (float (value )*self .card_scale ))
        if minimum is not None :
            result =max (minimum ,result )
        if maximum is not None :
            result =min (maximum ,result )
        return result
    def sy (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        return self .sx (value ,minimum =minimum ,maximum =maximum )
    def pad (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        result =int (round (float (value )*self .padding_scale ))
        if minimum is not None :
            result =max (minimum ,result )
        if maximum is not None :
            result =min (maximum ,result )
        return result
    def font (self ,size :int |float ,minimum :int =8 ,maximum :int =64 )->int :
        return max (minimum ,min (maximum ,int (round (float (size )*self .font_scale ))))
    @property
    def sidebar_width (self )->int :
        return self .sx (138 ,minimum =138 ,maximum =200 )
@dataclass (frozen =True ,slots =True )
class Palette :
    name :str
    bg :str
    surface :str
    surface_2 :str
    text :str
    muted :str
    accent :str
    accent_2 :str
    good :str
    warning :str
    danger :str
    border :str
    chart_grid :str
LIGHT =Palette (
name ="light",
bg ="#EEF4ED",
surface ="#FFFFFF",
surface_2 ="#DDE9D7",
text ="#1F2A24",
muted ="#637267",
accent ="#2E7D52",
accent_2 ="#C8782A",
good ="#2E8B57",
warning ="#B8791A",
danger ="#B53D2E",
border ="#C8D8C4",
chart_grid ="#B9C9B6",
)
DARK =Palette (
name ="dark",
bg ="#141414",
surface ="#1E1E1E",
surface_2 ="#252526",
text ="#FFFFFF",
muted ="#BDBDBD",
accent ="#6FCF97",
accent_2 ="#4FB477",
good ="#6FCF97",
warning ="#F2C94C",
danger ="#EB5757",
border ="#333333",
chart_grid ="#3A3A3A",
)
class ThemeManager :
    def __init__ (self ,settings :SettingsManager ,ui_scale :float =1.0 )->None :
        self .settings =settings
        self .scale =UIScale .from_value (ui_scale )
        self .fonts =self ._build_fonts ()
        self .palette =self .resolve_palette ()
    def set_scale (self ,ui_scale :float )->None :
        self .scale =UIScale .from_value (ui_scale )
        self .fonts =self ._build_fonts ()
    def _build_fonts (self )->dict [str ,tuple ]:
        return {
        "small":("DejaVu Sans",self .scale .font (9 )),
        "body":("DejaVu Sans",self .scale .font (10 )),
        "button":("DejaVu Sans",self .scale .font (10 )),
        "title":("DejaVu Sans",self .scale .font (18 ),"bold"),
        "card_title":("DejaVu Sans",self .scale .font (11 ),"bold"),
        "value":("DejaVu Sans",self .scale .font (21 ),"bold"),
        "big":("DejaVu Sans",self .scale .font (24 ),"bold"),
        "clock":("DejaVu Sans",self .scale .font (44 ,maximum =58 ),"bold"),
        "status":("DejaVu Sans",self .scale .font (10 ),"bold"),
        }
    def font_tuple (self ,size :int |float ,weight :str |None =None ,maximum :int =64 )->tuple :
        scaled =self .scale .font (size ,maximum =maximum )
        if weight :
            return ("DejaVu Sans",scaled ,weight )
        return ("DejaVu Sans",scaled )
    def resolve_palette (self )->Palette :
        mode =self .settings .get ("theme_mode",self .settings .get ("theme","light"))
        if mode =="auto":
            return DARK if self ._is_dark_time ()else LIGHT
        return DARK if mode =="dark"else LIGHT
    def refresh (self )->Palette :
        self .palette =self .resolve_palette ()
        return self .palette
    def apply (self ,root :tk .Tk |tk .Toplevel )->Palette :
        palette =self .refresh ()
        root .configure (bg =palette .bg )
        style =ttk .Style (root )
        try :
            style .theme_use ("clam")
        except tk .TclError :
            style .theme_use ("default")
        s =self .scale
        style .configure (".",font =self .fonts ["body"])
        style .configure ("App.TFrame",background =palette .bg )
        style .configure ("Surface.TFrame",background =palette .surface )
        style .configure ("Card.TFrame",background =palette .surface ,relief ="flat")
        style .configure ("Tile.TFrame",background =palette .surface_2 ,relief ="flat")
        style .configure ("TileHover.TFrame",background =palette .border ,relief ="flat")
        style .configure ("Sidebar.TFrame",background =palette .surface_2 )
        style .configure ("TLabel",background =palette .bg ,foreground =palette .text )
        style .configure ("Surface.TLabel",background =palette .surface ,foreground =palette .text )
        style .configure ("Muted.TLabel",background =palette .surface ,foreground =palette .muted )
        style .configure ("Tile.TLabel",background =palette .surface_2 ,foreground =palette .text )
        style .configure ("TileTitle.TLabel",background =palette .surface_2 ,foreground =palette .text ,font =self .font_tuple (12 ,"bold",maximum =17 ))
        style .configure ("TileMuted.TLabel",background =palette .surface_2 ,foreground =palette .muted ,font =self .font_tuple (9 ,maximum =13 ))
        style .configure ("Title.TLabel",background =palette .bg ,foreground =palette .text ,font =self .fonts ["title"])
        style .configure ("Big.TLabel",background =palette .surface ,foreground =palette .text ,font =self .fonts ["big"])
        style .configure ("Sidebar.TButton",background =palette .surface_2 ,foreground =palette .text ,padding =(s .pad (6 ),s .pad (7 )),anchor ="w")
        style .map ("Sidebar.TButton",background =[("active",palette .border ),("pressed",palette .border )],foreground =[("active",palette .text ),("pressed",palette .text )])
        style .configure ("Accent.TButton",background =palette .accent ,foreground ="#FFFFFF",padding =(s .pad (10 ),s .pad (8 )))
        style .map ("Accent.TButton",background =[("active",palette .accent_2 ),("pressed",palette .accent_2 )],foreground =[("active","#FFFFFF"),("pressed","#FFFFFF"),("disabled",palette .muted )])
        style .configure (
        "Control.TButton",
        background =palette .accent ,
        foreground ="#FFFFFF",
        padding =(s .pad (12 ),s .pad (5 )),
        font =self .font_tuple (10 ,"bold",maximum =14 ),
        )
        style .map (
        "Control.TButton",
        background =[("active",palette .accent_2 ),("pressed",palette .accent_2 ),("disabled",palette .border )],
        foreground =[("disabled",palette .muted ),("active","#FFFFFF"),("pressed","#FFFFFF")],
        )
        style .configure ("Small.TButton",background =palette .surface_2 ,foreground =palette .text ,padding =(s .pad (7 ),s .pad (6 )))
        style .configure ("TButton",background =palette .surface_2 ,foreground =palette .text ,padding =(s .pad (8 ),s .pad (7 )))
        style .map ("TButton",background =[("active",palette .border ),("pressed",palette .border ),("disabled",palette .surface_2 )],foreground =[("active",palette .text ),("pressed",palette .text ),("disabled",palette .muted )])
        style .configure (
        "Vertical.TScrollbar",
        background =palette .surface_2 ,
        troughcolor =palette .bg ,
        bordercolor =palette .border ,
        arrowcolor =palette .text ,
        lightcolor =palette .surface_2 ,
        darkcolor =palette .surface_2 ,
        )
        style .map ("Vertical.TScrollbar",background =[("active",palette .border ),("pressed",palette .border )])
        style .configure (
        "Horizontal.TScrollbar",
        background =palette .surface_2 ,
        troughcolor =palette .bg ,
        bordercolor =palette .border ,
        arrowcolor =palette .text ,
        lightcolor =palette .surface_2 ,
        darkcolor =palette .surface_2 ,
        )
        style .map ("Horizontal.TScrollbar",background =[("active",palette .border ),("pressed",palette .border )])
        input_padding =(s .pad (6 ),s .pad (4 ))
        style .configure ("TEntry",fieldbackground =palette .surface ,foreground =palette .text ,insertcolor =palette .text ,padding =input_padding )
        style .map ("TEntry",fieldbackground =[("readonly",palette .surface ),("disabled",palette .surface_2 ),("active",palette .surface )],foreground =[("readonly",palette .text ),("disabled",palette .muted ),("active",palette .text )])
        style .configure ("TCombobox",fieldbackground =palette .surface ,background =palette .surface_2 ,foreground =palette .text ,arrowcolor =palette .text ,padding =input_padding )
        style .map (
        "TCombobox",
        fieldbackground =[("readonly",palette .surface ),("disabled",palette .surface_2 ),("active",palette .surface )],
        foreground =[("readonly",palette .text ),("disabled",palette .muted ),("active",palette .text )],
        selectbackground =[("readonly",palette .surface ),("active",palette .surface )],
        selectforeground =[("readonly",palette .text ),("active",palette .text )],
        )
        style .configure ("TSpinbox",fieldbackground =palette .surface ,background =palette .surface_2 ,foreground =palette .text ,arrowcolor =palette .text ,padding =input_padding )
        style .map (
        "TSpinbox",
        fieldbackground =[("readonly",palette .surface ),("disabled",palette .surface_2 ),("active",palette .surface )],
        foreground =[("readonly",palette .text ),("disabled",palette .muted ),("active",palette .text )],
        )
        style .configure ("TCheckbutton",background =palette .bg ,foreground =palette .text )
        style .configure ("TRadiobutton",background =palette .bg ,foreground =palette .text )
        style .map ("TCheckbutton",background =[("active",palette .bg )],foreground =[("active",palette .text ),("disabled",palette .muted )])
        style .map ("TRadiobutton",background =[("active",palette .bg )],foreground =[("active",palette .text ),("disabled",palette .muted )])
        style .configure ("TLabelframe",background =palette .bg ,foreground =palette .text )
        style .configure ("TLabelframe.Label",background =palette .bg ,foreground =palette .text ,font =self .font_tuple (10 ,"bold"))
        style .configure ("Treeview",background =palette .surface ,fieldbackground =palette .surface ,foreground =palette .text ,bordercolor =palette .border ,lightcolor =palette .border ,darkcolor =palette .border )
        style .map ("Treeview",background =[("selected",palette .accent )],foreground =[("selected","#FFFFFF")])
        style .configure ("Treeview.Heading",background =palette .surface_2 ,foreground =palette .text ,relief ="flat")
        style .map ("Treeview.Heading",background =[("active",palette .border )],foreground =[("active",palette .text )])
        style .configure ("TNotebook",background =palette .bg ,borderwidth =0 )
        style .configure ("TNotebook.Tab",background =palette .surface_2 ,foreground =palette .text ,padding =(s .pad (8 ),s .pad (5 )))
        style .map ("TNotebook.Tab",background =[("selected",palette .surface ),("active",palette .border )],foreground =[("selected",palette .text ),("active",palette .text )])
        root .option_add ("*TCombobox*Listbox.background",palette .surface )
        root .option_add ("*TCombobox*Listbox.foreground",palette .text )
        root .option_add ("*TCombobox*Listbox.selectBackground",palette .accent )
        root .option_add ("*TCombobox*Listbox.selectForeground","#FFFFFF")
        return palette
    def _is_dark_time (self )->bool :
        now =datetime .now ().time ()
        dark_time =self ._parse_time (self .settings .get ("dark_theme_time","20:00"),time (20 ,0 ))
        light_time =self ._parse_time (self .settings .get ("light_theme_time","07:00"),time (7 ,0 ))
        if dark_time <light_time :
            return dark_time <=now <light_time
        return now >=dark_time or now <light_time
    def _parse_time (self ,value :str ,default :time )->time :
        try :
            hour ,minute =value .split (":")[:2 ]
            return time (int (hour ),int (minute ))
        except Exception :
            return default
