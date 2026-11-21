from __future__ import annotations
import logging
import socket
import threading
import tkinter as tk
import time as time_module
from concurrent .futures import ThreadPoolExecutor ,TimeoutError
from datetime import date ,datetime ,time ,timedelta
from pathlib import Path
from tkinter import messagebox ,ttk
from typing import Any, Callable, cast
from fitodomik_app .core .dependencies import missing_dependencies
from fitodomik_app .core .config import UPDATE_METADATA_URL
from fitodomik_app .core .event_bus import GuiEventBus
from fitodomik_app .core .models import AnalysisResult ,ExtraSensorValue ,SensorSnapshot ,WeatherData
from fitodomik_app .core .paths import BASE_DIR
from fitodomik_app .core .performance import log_perf ,measure_perf
from fitodomik_app .core .settings import SettingsManager
from fitodomik_app .core .task_manager import TaskManager
from fitodomik_app .services .api_client import FitoDomikApiClient ,setup_ssl_and_network
from fitodomik_app .services .alert_rules import AlertRulesEngine
from fitodomik_app .services .arduino import ArduinoService
from fitodomik_app .services .arduino_cli import ArduinoCliService
from fitodomik_app .services .arduino_code_generator import ArduinoCodeGenerator
from fitodomik_app .services .device_config import DeviceConfigManager
from fitodomik_app .services .extra_sensors import ExtraSensorConfigManager ,ExtraSensorHistory ,ExtraSensorLibraryManager ,ExtraSensorRegistry
from fitodomik_app .services .local_queue import LocalCommandResultQueue ,LocalDynamicSensorQueue ,LocalFaultQueue ,LocalPhotoQueue ,LocalScenarioLogQueue ,LocalSensorQueue
from fitodomik_app .services .notification_center import NotificationCenter
from fitodomik_app.services.command_journal import CommandJournal
from fitodomik_app .services .plant_analyzer import PlantAnalyzer
from fitodomik_app .services .sensor_profiles import SensorProfileManager
from fitodomik_app.services.screen_power import ScreenPowerManager
from fitodomik_app .services .updater import DesktopUpdater
from fitodomik_app .services .sensor_history import SensorHistory
from fitodomik_app .services .weather import WeatherService
from fitodomik_app.services.wifi_manager import WifiManager
from fitodomik_app .ui .theme import ThemeManager ,resolve_ui_scale
from fitodomik_app .ui .style_names import APP_FRAME_STYLE ,MUTED_LABEL_STYLE ,SIDEBAR_FRAME_STYLE
from fitodomik_app .ui .widgets import IconStore ,ScrollableFrame ,prepare_resized_image
ARDUINO_CLI_TITLE ="Arduino CLI"
ARDUINO_STATUS_MAX_CHARS =10
APP_TITLE ="ФитоДомик"
ARDUINO_SKETCH_NAME ="fitodomik.ino"
ACCOUNT_LINKED_TEXT ="Аккаунт привязан"
API_ACCOUNT_LINKED_TEXT ="API: аккаунт привязан"
class DeviceCommandExecutionError (RuntimeError ):
    def __init__ (self ,message :str ,payload :dict [str ,object ])->None :
        super ().__init__ (message )
        self .payload =payload
def _optional_float (value :Any )->float |None :
    try :
        if value is None :
            return None
        return float (value )
    except (TypeError ,ValueError ):
        return None
def shorten_status_text (value :str ,max_chars :int =ARDUINO_STATUS_MAX_CHARS )->str :
    text =str (value or "" ).strip ()
    if max_chars <=0 or len (text )<=max_chars :
        return text
    return f"...{text [-max_chars :]}"
def _load_weather_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.weather_tab import WeatherTab
    return WeatherTab
def _load_monitoring_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.monitoring_tab import MonitoringTab
    return MonitoringTab
def _load_charts_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.charts_tab import ChartsTab
    return ChartsTab
def _load_control_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.control_tab import ControlTab
    return ControlTab
def _load_analysis_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.analysis_tab import AnalysisTab
    return AnalysisTab
def _load_extra_devices_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.extra_devices_tab import ExtraDevicesTab
    return ExtraDevicesTab
def _load_automation_scenarios_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.automation_scenarios_tab import AutomationScenariosTab
    return AutomationScenariosTab
def _load_thresholds_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.thresholds_tab import ThresholdsTab
    return ThresholdsTab
def _load_notifications_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.notifications_tab import NotificationsTab
    return NotificationsTab
def _load_settings_tab ()->type [Any ]:
    from fitodomik_app.ui.tabs.settings_tab import SettingsTab
    return SettingsTab
class FitoDomikApplication :
    TAB_DEFS =[
    ("weather","Часы","clock"),
    ("monitoring","Мониторинг","monitor"),
    ("charts","Графики","chart"),
    ("control","Управление","control"),
    ("analysis","Анализ","plant"),
    ("extra_devices","Доп. устройства","control"),
    ("automation_scenarios","Сценарии","settings"),
    ("thresholds","Пороги","thresholds"),
    ("notifications","Уведомления","settings"),
    ("settings","Настройки","settings"),
    ]
    TAB_FACTORIES :dict [str ,Callable [[],type [Any ]]]={
    "weather":_load_weather_tab,
    "monitoring":_load_monitoring_tab,
    "charts":_load_charts_tab,
    "control":_load_control_tab,
    "analysis":_load_analysis_tab,
    "extra_devices":_load_extra_devices_tab,
    "automation_scenarios":_load_automation_scenarios_tab,
    "thresholds":_load_thresholds_tab,
    "notifications":_load_notifications_tab,
    "settings":_load_settings_tab,
    }
    def __init__ (self ,root :tk .Tk ,logger :logging .Logger )->None :
        self ._app_started_at =time_module .perf_counter ()
        self .root =root
        self .logger =logger .getChild ("app")
        self .settings =SettingsManager ()
        self .event_bus =GuiEventBus ()
        self .task_manager =TaskManager (self .event_bus ,logger )
        self .ui_scale =self ._resolve_ui_scale ()
        try :
            self .root .tk .call ("tk","scaling",1.0 )
        except tk .TclError :
            pass
        theme_started =time_module .perf_counter ()
        self .theme =ThemeManager (self .settings ,self .ui_scale )
        self .palette =self .theme .apply (root )
        log_perf ("theme loaded",time_module .perf_counter ()-theme_started )
        self .icons =IconStore (size =self .icon_size (30 ))
        services_started =time_module .perf_counter ()
        self .api =FitoDomikApiClient (self .settings .get ("token",""),logger )
        self .weather_service =WeatherService (self .settings ,logger )
        self .wifi_manager =WifiManager (logger )
        self .arduino =ArduinoService (self .settings ,self .event_bus ,logger )
        self .screen_power =ScreenPowerManager (root ,self .logger )
        self .plant_analyzer =PlantAnalyzer (self .settings ,logger )
        self .sensor_history =SensorHistory ()
        self .extra_registry =ExtraSensorRegistry ()
        self .extra_config =ExtraSensorConfigManager (self .settings ,self .extra_registry )
        self .device_config =DeviceConfigManager (self .settings ,self .extra_config )
        self .extra_history =ExtraSensorHistory (int (self .settings .get ("extra_sensors_history_limit",2000 )))
        self .extra_libraries =ExtraSensorLibraryManager (BASE_DIR /str (self .settings .get ("extra_sensors_user_libraries_dir","arduino_user_libraries")))
        self .sensor_profiles =SensorProfileManager (
        BASE_DIR /"fitodomik_app"/"data"/"sensor_profiles",
        BASE_DIR /str (self .settings .get ("sensor_profiles_user_dir","sensor_profiles")),
        )
        self .extra_code_generator =ArduinoCodeGenerator (self .extra_config ,self .extra_registry ,self .sensor_profiles )
        self .arduino_cli =ArduinoCliService ()
        self .updater =DesktopUpdater (
        str (self .settings .get ("updates_latest_url","")or UPDATE_METADATA_URL),
        BASE_DIR /"updates",
        )
        self .last_update_info =None
        self .notifications =NotificationCenter ()
        self .sensor_queue =LocalSensorQueue (int (self .settings .get ("local_sensor_queue_max_records",10000 )))
        self .dynamic_sensor_queue =LocalDynamicSensorQueue (int (self .settings .get ("local_dynamic_sensor_queue_max_records",10000 )))
        self .photo_queue =LocalPhotoQueue ()
        self .command_result_queue =LocalCommandResultQueue (int (self .settings .get ("local_command_result_queue_max_records",5000 )))
        self .fault_queue =LocalFaultQueue ()
        self .command_journal =CommandJournal ()
        self .api .delivery_client =self .command_journal .client_id ()
        self .scenario_log_queue =LocalScenarioLogQueue (int (self .settings .get ("local_scenario_log_queue_max_records",5000 )))
        self .alert_engine =AlertRulesEngine (
        self .settings ,
        self .notifications ,
        self ._handle_alert_relay_action ,
        )
        log_perf ("services initialized",time_module .perf_counter ()-services_started )
        self .latest_snapshot =SensorSnapshot ()
        self ._last_smart_photo_snapshot =SensorSnapshot ()
        self .latest_weather :WeatherData |None =None
        self .latest_thresholds :dict |None =None
        self .latest_schedule =None
        self .active_tab ="weather"
        self .analysis_running =False
        self .main_frame :ttk .Frame |None =None
        self .sidebar :ttk .Frame |None =None
        self .menu_scroll :ScrollableFrame |None =None
        self .tabs :dict [str ,Any ]={}
        self .sidebar_buttons :dict [str ,ttk .Button ]={}
        self .sidebar_button_icons :dict [str ,str ]={}
        self ._main_built =False
        self ._startup_skipped =False
        self ._closing =False
        self ._event_after_id :str |None =None
        self ._resize_after_id :str |None =None
        self ._last_resize_size =(0 ,0 )
        self ._last_layout_size =(0 ,0 )
        self ._device_pair_code =""
        self ._device_pair_secret =""
        self ._device_pair_poll_after_id :str |None =None
        self ._scenario_condition_since :dict [str ,datetime ]={}
        self ._network_backoff_until =0.0
        self ._network_backoff_seconds =0.0
        with measure_perf ("root configured"):
            self ._configure_root ()
        self .screen_power .start ()
        with measure_perf ("splash created"):
            self ._build_splash ()
        self ._drain_events ()
        self .task_manager .run_once ("startup",self ._startup_sequence )
    def _resolve_ui_scale (self ,width :int |None =None ,height :int |None =None )->float :
        if width is None or height is None :
            width =self .root .winfo_width ()
            height =self .root .winfo_height ()
            if width <=1 or height <=1 :
                width =self .root .winfo_screenwidth ()
                height =self .root .winfo_screenheight ()
        return resolve_ui_scale (self .settings .get ("interface_scale","auto"),width ,height )
    def sx (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        return self .theme .scale .sx (value ,minimum =minimum ,maximum =maximum )
    def sy (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        return self .theme .scale .sy (value ,minimum =minimum ,maximum =maximum )
    def pad (self ,value :int |float ,minimum :int |None =None ,maximum :int |None =None )->int :
        return self .theme .scale .pad (value ,minimum =minimum ,maximum =maximum )
    def font_tuple (self ,size :int |float ,weight :str |None =None ,maximum :int =64 )->tuple :
        return self .theme .font_tuple (size ,weight ,maximum =maximum )
    def icon_size (self ,base_size :int ,maximum :int |None =None )->int :
        max_size =maximum if maximum is not None else max (base_size ,int (base_size *1.45 ))
        return self .sx (base_size ,minimum =base_size ,maximum =max_size )
    def content_width (self )->int :
        if hasattr (self ,"content")and self .content .winfo_exists ():
            width =self .content .winfo_width ()
            if width >1 :
                return width
        return max (0 ,self .root .winfo_width ()-self .theme .scale .sidebar_width )
    def adaptive_columns (self ,item_min_width :int ,max_columns :int )->int :
        width =max (1 ,self .content_width ())
        return max (1 ,min (max_columns ,width //max (1 ,item_min_width )))
    def _configure_root (self )->None :
        self .root .title (APP_TITLE )
        width =self .root .winfo_screenwidth ()
        height =self .root .winfo_screenheight ()
        fullscreen =self .settings .is_first_run or bool (self .settings .get ("fullscreen",True ))
        if not fullscreen :
            self .root .geometry (f"{min (1180 ,width )}x{min (760 ,height )}")
        else :
            self .root .attributes ("-fullscreen",True )
            if self .settings .get ("kiosk_mode",True ):
                self .root .overrideredirect (True )
        self .root .minsize (800 ,480 )
        self .root .bind ("<F11>",lambda _event :self .toggle_fullscreen ())
        self .root .bind ("<Configure>",self ._schedule_resize ,add ="+")
        self .root .protocol ("WM_DELETE_WINDOW",self .close )
    def _schedule_resize (self ,event )->None :
        if self ._closing or event .widget is not self .root :
            return
        if event .width <200 or event .height <200 :
            return
        self ._last_resize_size =(event .width ,event .height )
        if self ._resize_after_id :
            try :
                self .root .after_cancel (self ._resize_after_id )
            except tk .TclError :
                pass
        self ._resize_after_id =self .root .after (180 ,self ._apply_debounced_resize )
    def _apply_debounced_resize (self )->None :
        self ._resize_after_id =None
        width ,height =self ._last_resize_size
        if width <200 or height <200 :
            return
        new_scale =self ._resolve_ui_scale (width ,height )
        scale_changed =abs (new_scale -self .ui_scale )>=0.02
        layout_changed =abs (width -self ._last_layout_size [0 ])>=80 or abs (height -self ._last_layout_size [1 ])>=60
        if not scale_changed and not layout_changed :
            return
        if scale_changed :
            self .ui_scale =new_scale
            self .theme .set_scale (new_scale )
            self .palette =self .theme .apply (self .root )
            self .icons .set_size (self .icon_size (30 ))
            self ._refresh_sidebar_palette ()
        self ._last_layout_size =(width ,height )
        self ._apply_scaled_layout ()
    def _apply_scaled_layout (self )->None :
        if hasattr (self ,"splash")and self .splash .winfo_exists ():
            self ._refresh_splash_scale ()
        if self ._main_built :
            self ._refresh_main_scale ()
            for tab_id ,tab in self .tabs .items ():
                if hasattr (tab ,"refresh_scale"):
                    if tab_id ==self .active_tab :
                        tab .refresh_scale ()
                    else :
                        setattr (tab ,"_scale_dirty",True )
    def _refresh_splash_scale (self )->None :
        try :
            if hasattr (self ,"splash_title"):
                self .splash_title .configure (font =self .font_tuple (34 ,"bold",maximum =46 ))
                self .splash_title .pack_configure (pady =(0 ,self .pad (8 )))
            if hasattr (self ,"splash_subtitle"):
                self .splash_subtitle .pack_configure (pady =(0 ,self .pad (18 )))
            if hasattr (self ,"splash_status"):
                self .splash_status .pack_configure (pady =self .pad (6 ))
            if hasattr (self ,"splash_progress"):
                self .splash_progress .configure (length =self .sx (360 ,minimum =360 ,maximum =520 ))
                self .splash_progress .pack_configure (pady =self .pad (12 ))
            if hasattr (self ,"splash_log"):
                self .splash_log .configure (
                width =self .sx (72 ,minimum =72 ,maximum =92 ),
                height =self .sx (8 ,minimum =8 ,maximum =12 ),
                font =self .font_tuple (9 ),
                bg =self .palette .surface ,
                fg =self .palette .text ,
                insertbackground =self .palette .text ,
                highlightbackground =self .palette .border ,
                )
        except tk .TclError :
            pass
    def _refresh_main_scale (self )->None :
        if self .sidebar :
            self .sidebar .configure (width =self .theme .scale .sidebar_width )
        if hasattr (self ,"logo_label"):
            self .logo_label .configure (font =self .font_tuple (12 ,"bold",maximum =16 ))
            self .logo_label .pack_configure (padx =self .pad (8 ),pady =(self .pad (12 ),self .pad (8 )))
        for tab_id ,button in self .sidebar_buttons .items ():
            icon_name =self .sidebar_button_icons .get (tab_id )
            if icon_name :
                icon =self .icons .get (icon_name ,self .icon_size (20 ,maximum =28 ))
                button .configure (image =cast (Any ,icon ))
                setattr (button ,"_image_ref",icon )
            button .pack_configure (padx =self .pad (4 ),pady =self .pad (3 ))
        for label in ("status_label","api_status_label","arduino_status_label"):
            if hasattr (self ,label):
                getattr (self ,label ).pack_configure (padx =self .pad (12 ),pady =self .pad (4 ))
    def _tab_defs (self )->list [tuple [str ,str ,str ]]:
        defs ={tab_id :(tab_id ,label ,icon_name )for tab_id ,label ,icon_name in self .TAB_DEFS }
        return [defs [tab_id ]for tab_id in self ._configured_tab_order ()if tab_id in defs and self ._is_tab_visible (tab_id )]
    def _default_tab_order (self )->list [str ]:
        return [tab_id for tab_id ,_label ,_icon_name in self .TAB_DEFS ]
    def _configured_tab_order (self )->list [str ]:
        return self ._normalize_tab_order (self .settings .get ("tab_order",self ._default_tab_order ()))
    def _normalize_tab_order (self ,value )->list [str ]:
        default_order =self ._default_tab_order ()
        known =set (default_order )
        if isinstance (value ,str ):
            raw =[item .strip ()for item in value .replace (";",",").split (",")]
        elif isinstance (value ,list ):
            raw =[str (item ).strip ()for item in value ]
        else :
            raw =[]
        ordered :list [str ]=[]
        for tab_id in raw :
            if tab_id in known and tab_id not in ordered :
                ordered .append (tab_id )
        for tab_id in default_order :
            if tab_id not in ordered :
                ordered .append (tab_id )
        return ordered
    def _is_tab_visible (self ,tab_id :str )->bool :
        return bool (self .device_config .feature_flags ().get (tab_id ,True ))
    def _build_splash (self )->None :
        self .splash =ttk .Frame (self .root ,style =APP_FRAME_STYLE )
        self .splash .pack (fill ="both",expand =True )
        box =ttk .Frame (self .splash ,style =APP_FRAME_STYLE )
        box .place (relx =0.5 ,rely =0.5 ,anchor ="center")
        self .splash_title =ttk .Label (box ,text =APP_TITLE ,style ="Title.TLabel",font =self .font_tuple (34 ,"bold",maximum =46 ))
        self .splash_title .pack (pady =(0 ,self .pad (8 )))
        self .splash_subtitle =ttk .Label (box ,text ="Инициализация умной мини-фермы",style =MUTED_LABEL_STYLE )
        self .splash_subtitle .pack (pady =(0 ,self .pad (18 )))
        self .splash_status =ttk .Label (box ,text ="Загрузка настроек...",style =MUTED_LABEL_STYLE ,width =60 ,anchor ="center")
        self .splash_status .pack (pady =self .pad (6 ))
        self .splash_progress =ttk .Progressbar (box ,mode ="indeterminate",length =self .sx (360 ,minimum =360 ,maximum =520 ))
        self .splash_progress .pack (pady =self .pad (12 ))
        self .splash_progress .start (12 )
        log_frame =ttk .Frame (box ,style =APP_FRAME_STYLE )
        log_frame .pack (fill ="both",pady =(self .pad (8 ),0 ))
        self .splash_log =tk .Text (
        log_frame ,
        width =self .sx (72 ,minimum =72 ,maximum =92 ),
        height =self .sx (8 ,minimum =8 ,maximum =12 ),
        bd =0 ,
        highlightthickness =1 ,
        highlightbackground =self .palette .border ,
        bg =self .palette .surface ,
        fg =self .palette .text ,
        insertbackground =self .palette .text ,
        font =self .font_tuple (9 ),
        state ="disabled",
        wrap ="word",
        )
        self .splash_log .pack (fill ="both",expand =True )
        ttk .Button (box ,text ="Пропустить загрузку",command =self .skip_startup ).pack (pady =(self .pad (12 ),0 ))
    def _build_main (self )->None :
        if self ._main_built :
            return
        main_started =time_module .perf_counter ()
        self ._main_built =True
        if hasattr (self ,"splash")and self .splash .winfo_exists ():
            self .splash .destroy ()
        self .palette =self .theme .apply (self .root )
        self .main_frame =ttk .Frame (self .root ,style =APP_FRAME_STYLE )
        self .main_frame .pack (fill ="both",expand =True )
        self .main_frame .columnconfigure (1 ,weight =1 )
        self .main_frame .rowconfigure (0 ,weight =1 )
        self .sidebar =ttk .Frame (self .main_frame ,style =SIDEBAR_FRAME_STYLE ,width =self .theme .scale .sidebar_width )
        self .sidebar .grid (row =0 ,column =0 ,sticky ="ns")
        self .sidebar .grid_propagate (False )
        self .sidebar .pack_propagate (False )
        self .logo_label =ttk .Label (self .sidebar ,text =APP_TITLE ,style ="Surface.TLabel",font =self .font_tuple (12 ,"bold",maximum =16 ))
        self .logo_label .pack (anchor ="w",padx =self .pad (8 ),pady =(self .pad (12 ),self .pad (8 )))
        self .menu_scroll =ScrollableFrame (
        self .sidebar ,
        self .palette ,
        canvas_bg =self .palette .surface_2 ,
        inner_style =SIDEBAR_FRAME_STYLE ,
        )
        self .menu_scroll .pack_propagate (False )
        self .menu_scroll .scrollbar .pack_forget ()
        self .menu_scroll .pack (fill ="both",expand =True )
        self .sidebar_buttons ={}
        self .sidebar_button_icons ={}
        self .content =ttk .Frame (self .main_frame ,style =APP_FRAME_STYLE )
        self .content .grid (row =0 ,column =1 ,sticky ="nsew")
        self .content .rowconfigure (0 ,weight =1 )
        self .content .columnconfigure (0 ,weight =1 )
        self .status_bar =ttk .Frame (self .main_frame ,style ="Surface.TFrame")
        self .status_bar .grid (row =1 ,column =0 ,columnspan =2 ,sticky ="ew")
        self .status_label =ttk .Label (self .status_bar ,text ="Готово",style ="Surface.TLabel")
        self .status_label .pack (side ="left",padx =self .pad (12 ),pady =self .pad (4 ))
        self .api_status_label =ttk .Label (self .status_bar ,text ="API: не проверено",style =MUTED_LABEL_STYLE )
        self .api_status_label .pack (side ="right",padx =self .pad (12 ),pady =self .pad (4 ))
        self .arduino_status_label =ttk .Label (self .status_bar ,text ="Arduino: ожидание",style =MUTED_LABEL_STYLE ,width =22 ,anchor ="e")
        self .arduino_status_label .pack (side ="right",padx =self .pad (12 ),pady =self .pad (4 ))
        self .tabs ={}
        tab_started =time_module .perf_counter ()
        visible_ids =[tab_id for tab_id ,_label ,_icon_name in self ._tab_defs ()]
        if self .active_tab not in visible_ids :
            self .active_tab =visible_ids [0 ]if visible_ids else "settings"
        if self .active_tab in visible_ids :
            self ._create_tab (self .active_tab )
        log_perf ("tabs created",time_module .perf_counter ()-tab_started )
        self ._rebuild_sidebar_buttons ()
        if self .latest_weather and "weather"in self .tabs :
            self ._call_tab_method ("weather","set_weather",self .latest_weather )
        if (self .latest_thresholds or self .latest_schedule )and "thresholds"in self .tabs :
            self ._call_tab_method ("thresholds","set_thresholds",self .latest_thresholds )
            self ._call_tab_method ("thresholds","set_schedule",self .latest_schedule )
        self .show_tab (self .active_tab )
        self ._start_background_tasks ()
        self .root .after (700 ,self ._maybe_open_first_run_wizard )
        log_perf ("first screen ready",time_module .perf_counter ()-self ._app_started_at )
        log_perf ("ui created",time_module .perf_counter ()-main_started )
    def show_tab (self ,tab_id :str )->None :
        visible_ids ={item [0 ]for item in self ._tab_defs ()}
        if tab_id not in visible_ids :
            tab_id =self ._fallback_tab_id ()
        if tab_id not in self .tabs and tab_id in visible_ids :
            with measure_perf (f"tab {tab_id } created"):
                self ._create_tab (tab_id )
        self .active_tab =tab_id
        for key ,tab in self .tabs .items ():
            if key ==tab_id :
                tab .grid ()
                if getattr (tab ,"_scale_dirty",False ):
                    self ._call_tab_method (key ,"refresh_scale")
                    setattr (tab ,"_scale_dirty",False )
                if key in {"monitoring","control"}:
                    self ._call_tab_method (key ,"update_snapshot",self .latest_snapshot )
                if key =="monitoring":
                    self ._call_tab_method (key ,"update_extra_values",self .extra_history .latest_values ())
                elif key =="extra_devices":
                    self ._call_tab_method (key ,"update_values",self .extra_history .latest_values ())
                self ._call_tab_method (key ,"on_show")
            else :
                tab .grid_remove ()
    def _fallback_tab_id (self )->str :
        for tab_id ,_label ,_icon_name in self ._tab_defs ():
            if tab_id in self .tabs :
                return tab_id
        defs =self ._tab_defs ()
        if defs :
            return defs [0 ][0 ]
        if self .tabs :
            return next (iter (self .tabs ))
        return "settings"
    def _create_tab (self ,tab_id :str )->Any :
        factory =self ._tab_factory (tab_id )
        tab =factory (self .content ,self )
        tab .grid (row =0 ,column =0 ,sticky ="nsew")
        tab .grid_remove ()
        self .tabs [tab_id ]=tab
        if tab_id =="weather"and self .latest_weather :
            self ._call_tab_method (tab_id ,"set_weather",self .latest_weather )
        if tab_id =="thresholds":
            if self .latest_thresholds or self .latest_schedule :
                self ._call_tab_method (tab_id ,"set_thresholds",self .latest_thresholds )
                self ._call_tab_method (tab_id ,"set_schedule",self .latest_schedule )
        if tab_id =="extra_devices":
            self ._call_tab_method (tab_id ,"refresh")
        if tab_id in {"monitoring","control"}:
            self ._call_tab_method (tab_id ,"update_snapshot",self .latest_snapshot )
        return tab
    def _call_tab_method (self ,tab_id :str ,method_name :str ,*args :Any ,**kwargs :Any )->Any :
        tab =self .tabs .get (tab_id )
        if tab is None :
            return None
        method =getattr (tab ,method_name ,None )
        if not callable (method ):
            return None
        return method (*args ,**kwargs )
    def _tab_factory (self ,tab_id :str ):
        try :
            factory =self .TAB_FACTORIES [tab_id ]
        except KeyError :
            raise KeyError (tab_id )from None
        return factory ()
    def _maybe_open_first_run_wizard (self )->None :
        if self ._closing or not self ._main_built :
            return
        if bool (self .settings .get ("first_run_completed",False )):
            return
        self .open_first_run_wizard ()
    def open_first_run_wizard (self )->None :
        self .show_tab ("settings")
        self ._call_tab_method ("settings","open_first_run_wizard")
    def _rebuild_sidebar_buttons (self )->None :
        if not self .menu_scroll :
            return
        for child in self .menu_scroll .inner .winfo_children ():
            child .destroy ()
        self .sidebar_buttons ={}
        self .sidebar_button_icons ={}
        for tab_id ,label ,icon_name in self ._tab_defs ():
            icon =self .icons .get (icon_name ,self .icon_size (20 ,maximum =28 ))
            button =ttk .Button (
            self .menu_scroll .inner ,
            text =label ,
            image =cast (Any ,icon ),
            compound ="left",
            style ="Sidebar.TButton",
            command =lambda current =tab_id :self .show_tab (current ),
            )
            setattr (button ,"_image_ref",icon )
            button .pack (fill ="x",padx =self .pad (4 ),pady =self .pad (3 ))
            self .sidebar_buttons [tab_id ]=button
            self .sidebar_button_icons [tab_id ]=icon_name
    def _startup_sequence (self )->None :
        startup_started =time_module .perf_counter ()
        self ._startup_step ("Открываю интерфейс...")
        self .event_bus .post ("startup_complete")
        self ._startup_step ("Проверка зависимостей и Arduino CLI...")
        missing =missing_dependencies ()
        if missing :
            self .event_bus .post ("dependency_warning",missing ,level ="warning",message =", ".join (missing ))
        self ._startup_step ("Настройка SSL и сети в фоне...")
        self .task_manager .run_once ("network_setup",self ._network_setup_task )
        self ._startup_step ("Инициализация Ардуино...")
        arduino_started =time_module .perf_counter ()
        self .arduino .start ()
        log_perf ("arduino init",time_module .perf_counter ()-arduino_started )
        self ._startup_step ("Запуск фоновых задач...")
        log_perf ("startup sequence",time_module .perf_counter ()-startup_started )
    def skip_startup (self )->None :
        self ._startup_skipped =True
        self .event_bus .post ("startup_status",message ="Загрузка пропущена пользователем. Открываю интерфейс...")
        self .event_bus .post ("startup_complete")
    def _startup_step (self ,message :str )->None :
        if self ._startup_skipped :
            return
        self .event_bus .post ("startup_status",message =message )
    def _run_startup_limited (self ,name :str ,callback ,timeout_message :str )->None :
        if self ._startup_skipped :
            return
        executor =ThreadPoolExecutor (max_workers =1 ,thread_name_prefix =name )
        future =executor .submit (callback )
        try :
            future .result (timeout =10 )
        except TimeoutError :
            self .event_bus .post ("startup_status",level ="warning",message =timeout_message )
        except Exception as exc :
            self .event_bus .post ("startup_status",level ="warning",message =f"{timeout_message }: {exc }")
        finally :
            executor .shutdown (wait =False ,cancel_futures =False )
    def _network_setup_task (self )->None :
        started =time_module .perf_counter ()
        ok =setup_ssl_and_network (self .logger )
        log_perf ("network ssl setup",time_module .perf_counter ()-started )
        self .event_bus .post (
        "network_ready",
        ok ,
        level ="info"if ok else "warning",
        message ="Сеть и SSL готовы"if ok else "SSL-сертификаты не настроены, сеть будет использовать системные настройки",
        )
        self .task_manager .run_once ("weather_refresh",self ._weather_task )
        self .task_manager .run_once ("token_check_now",self ._token_check_task )
        self .task_manager .run_once ("threshold_refresh",self ._thresholds_task )
    def _start_background_tasks (self )->None :
        started =time_module .perf_counter ()
        self .task_manager .start_periodic (
        "weather",
        self ._weather_task ,
        lambda :self ._network_interval (self .settings .get ("weather_update_interval",3600 )),
        )
        self .task_manager .start_periodic (
        "token_check",
        self ._token_check_task ,
        lambda :self ._network_interval (self .settings .get ("api_token_check_interval",1800 )),
        )
        self .task_manager .start_periodic (
        "thresholds",
        self ._thresholds_task ,
        lambda :self ._network_interval (self .settings .get ("threshold_reload_interval",900 )),
        )
        self .task_manager .start_periodic (
        "history",
        self ._history_task ,
        self ._adaptive_history_interval ,
        )
        self .task_manager .start_periodic (
        "server_send",
        self ._server_send_task ,
        lambda :self ._network_interval (self .settings .get ("data_send_interval",1800 )),
        )
        self .task_manager .start_periodic (
        "device_settings_sync",
        self ._device_settings_sync_task ,
        lambda :self ._network_interval (self .settings .get ("alert_check_interval_minutes",60 )*60 ),
        )
        self .task_manager .start_periodic (
        "alert_rules",
        self ._alert_rules_task ,
        lambda :self .settings .get ("alert_check_interval_minutes",60 )*60 ,
        )
        self .task_manager .start_periodic (
        "automation_scenarios",
        self ._automation_scenarios_task ,
        lambda :max (5 ,int (self .settings .get ("automation_scenario_check_interval_seconds",10 ))),
        )
        self .task_manager .start_periodic (
        "local_queue_sync",
        self ._local_queue_sync_task ,
        lambda :self ._network_interval (max (30 ,self .settings .get ("failed_upload_retry_interval_seconds",120 ))),
        )
        self .task_manager .start_periodic (
        "device_command_poll",
        self ._device_command_poll_task ,
        lambda :self ._network_interval (self .settings .get ("command_poll_interval_seconds",15 )),
        )
        self .task_manager .start_periodic (
        "photo_schedule",
        self ._photo_schedule_task ,
        lambda :self .settings .get ("photo_check_interval",60 ),
        run_immediately =True ,
        )
        self .task_manager .start_periodic (
        "chart_refresh",
        self ._chart_refresh_task ,
        lambda :self .settings .get ("chart_update_interval",30 ),
        )
        self .task_manager .start_periodic ("auto_theme",self ._auto_theme_task ,lambda :60 )
        log_perf ("background tasks started",time_module .perf_counter ()-started )
    def refresh_weather (self )->None :
        self .task_manager .run_once ("weather_refresh",self ._weather_task )
    def check_api_token (self )->None :
        self ._sync_api_token ()
        self .task_manager .run_once ("token_check_now",self ._token_check_task )
    def link_account (self ,username :str ,password :str )->None :
        self ._set_status ("Привязка аккаунта...")
        self .task_manager .run_once ("account_link",lambda :self ._link_account_worker (username ,password ))
    def refresh_device_list (self )->None :
        self ._set_status ("Загружаю список устройств...")
        self .task_manager .run_once ("device_list",self ._device_list_worker )
    def select_local_device (self ,device_id :str ,device_name :str ,device_token :str ="" )->None :
        values ={"device_id":device_id ,"device_name":device_name }
        if device_token :
            values ["device_token"]=device_token
        self .settings .update (values ,save =True )
        self ._sync_api_token ()
        if "settings"in self .tabs and hasattr (self .tabs ["settings"],"refresh_account_info"):
            self .tabs ["settings"].refresh_account_info ()
        self ._set_status (f"Выбрано устройство: {device_name }")
    def start_device_pair_qr (self )->None :
        self ._set_status ("Создаю QR-код привязки...")
        self .task_manager .run_once ("device_pair_create",self ._device_pair_create_worker )
    def cancel_device_pair_poll (self )->None :
        self ._device_pair_code =""
        self ._device_pair_secret =""
        if self ._device_pair_poll_after_id :
            try :
                self .root .after_cancel (self ._device_pair_poll_after_id )
            except tk .TclError :
                pass
            self ._device_pair_poll_after_id =None
    def unlink_device (self )->None :
        self ._set_status ("Отвязываю устройство...")
        self .task_manager .run_once ("device_unlink",self ._unlink_device_worker )
    def check_weather_tokens (self )->None :
        self ._set_status ("Проверяю токены погоды...")
        self .task_manager .run_once ("weather_tokens_check",self ._weather_tokens_check_worker )
    def refresh_wifi_networks (self )->None :
        self ._set_status ("Сканирую Wi-Fi сети...")
        self .task_manager .run_once ("wifi_scan",self ._wifi_scan_worker )
    def connect_wifi (self ,ssid :str ,password :str )->None :
        self ._set_status (f"Подключаюсь к Wi-Fi: {ssid }")
        self .task_manager .run_once ("wifi_connect",lambda :self ._wifi_connect_worker (ssid ,password ))
    def disconnect_wifi (self )->None :
        self ._set_status ("Отключаю Wi-Fi...")
        self .task_manager .run_once ("wifi_disconnect",self ._wifi_disconnect_worker )
    def forget_wifi (self ,ssid :str )->None :
        self ._set_status (f"Удаляю Wi-Fi сеть: {ssid }")
        self .task_manager .run_once ("wifi_forget",lambda :self ._wifi_forget_worker (ssid ))
    def check_wifi_internet (self )->None :
        self ._set_status ("Проверяю интернет...")
        self .task_manager .run_once ("wifi_internet",self ._wifi_internet_worker )
    def refresh_thresholds (self )->None :
        self ._sync_api_token ()
        self .task_manager .run_once ("threshold_refresh",self ._thresholds_task )
    def start_arduino (self )->None :
        self .task_manager .run_once ("arduino_start",self .arduino .start )
    def stop_arduino (self )->None :
        self .arduino .stop ()
    def toggle_device (self ,device_type :str )->None :
        if self .settings .get ("control_mode","manual")!="manual":
            messagebox .showinfo ("Режим управления","В автоматическом режиме ручное управление отключено.")
            return
        command_name =str (device_type ).upper ()
        self ._set_status (f"Отправляю команду Arduino: {command_name }")
        self .task_manager .run_once (
        f"toggle_device_{command_name }_{time_module .monotonic_ns ()}",
        lambda :self ._toggle_device_worker (command_name ),
        )
    def reset_pump_fault (self )->None :
        if messagebox .askyesno ("Сброс аварии насоса","Проверьте причину аварии и датчик. Сбросить блокировку? Насос останется выключенным."):
            self .task_manager .run_once ("pump_fault_reset",lambda :self ._command_pump_fault_reset ({},""))
    def _toggle_device_worker (self ,device_type :str )->None :
        state_fields ={
            "FAN":"fan_state",
            "PUMP":"pump_state",
            "CURTAINS":"curtains_state",
            "LAMP":"lamp_state",
            "LED":"lamp_state",
        }
        field =state_fields .get (device_type )
        if field is None :
            states =self .latest_snapshot .extra_relay_states
            if device_type not in states :
                self .event_bus .post ("arduino_command",False ,level ="warning",message =f"Для {device_type} неизвестно текущее состояние реле")
                return
            desired =not states [device_type]
        else :
            desired =not bool (getattr (self .latest_snapshot ,field ))
        ok =self .arduino .send_command (device_type ,desired )
        message =f"Команда Arduino отправлена: {device_type }"if ok else "Arduino недоступна или команда не поддерживается"
        self .event_bus .post (
        "arduino_command",
        ok ,
        level ="info"if ok else "warning",
        message =message ,
        )
    def upload_thresholds_to_arduino (self )->None :
        self .task_manager .run_once ("upload_thresholds",self ._upload_thresholds_worker )
    def run_device_test (self ,device :str )->None :
        if device =="pump"and not messagebox .askyesno ("Тест насоса","Насос включится на короткое время. Продолжить?"):
            return
        self ._set_status ("Запускаю тест устройства...")
        self .task_manager .run_once (f"device_test_{device }",lambda :self ._device_test_worker (device ))
    def run_plant_analysis (self ,is_auto :bool =False )->None :
        if self .analysis_running :
            return
        self .analysis_running =True
        if "analysis"in self .tabs :
            self .tabs ["analysis"].set_busy ()
        self .task_manager .run_once ("plant_analysis",lambda :self ._analysis_worker (is_auto ))
    def test_camera (self )->None :
        self .task_manager .run_once ("camera_test",self ._camera_test_worker )
    def open_extra_sensors_settings (self )->None :
        self .show_tab ("settings")
        if "settings"in self .tabs and hasattr (self .tabs ["settings"],"open_extra_sensors_settings"):
            self .tabs ["settings"].open_extra_sensors_settings ()
    def refresh_extra_devices_tab (self )->None :
        self ._sync_optional_tabs ()
        if "extra_devices"in self .tabs and hasattr (self .tabs ["extra_devices"],"refresh"):
            self .tabs ["extra_devices"].refresh ()
        self .task_manager .run_once ("device_config_save",self ._save_dynamic_device_config_task )
    def check_extra_sensor_now (self )->None :
        self .arduino .request_status ()
        self .event_bus .post ("extra_sensor_status",True ,message ="Запрошен статус Arduino. Данные появятся после строки EXTRA_SENSORS.")
    def import_extra_sensor_library (self ,path :Path ):
        result =self .extra_libraries .import_library (path )
        if result .warnings:
            level ="warning"
        elif result .status =="error":
            level ="error"
        else:
            level ="info"
        self .notifications .add ("Arduino-библиотека",result .message ,event_type ="extra_sensors",level =level )
        return result
    def install_extra_sensor_library_to_arduino (self ,path :Path ):
        result =self .extra_libraries .install_to_arduino_libraries (path )
        if result .warnings:
            level ="warning"
        elif result .status =="error":
            level ="error"
        else:
            level ="info"
        self .notifications .add ("Arduino-библиотека",result .message ,event_type ="extra_sensors",level =level )
        return result
    def import_sensor_profile (self ,path :Path ):
        result =self .sensor_profiles .import_profile (path )
        if result .status =="ok":
            level ="info"
        elif result .status =="exists":
            level ="warning"
        else:
            level ="error"
        self .notifications .add ("Профиль датчика",result .message ,event_type ="extra_sensors",level =level )
        return result
    def list_firmware_backups (self ):
        return self .extra_code_generator .list_backups (BASE_DIR /ARDUINO_SKETCH_NAME )
    def generate_extra_sensor_firmware (self )->None :
        result =self .extra_code_generator .generate (BASE_DIR /ARDUINO_SKETCH_NAME )
        message =result .message
        if result .backup_path :
            message +=f". Backup: {result .backup_path .name}"
        if result .warnings :
            message +=f". Предупреждения: {'; '.join (result .warnings)}"
        self .notifications .add ("Прошивка доп. датчиков",message ,event_type ="extra_sensors",level ="info"if result .ok else "error")
        self .event_bus .post ("extra_sensor_status",result .ok ,level ="info"if result .ok else "error",message =message )
    def compile_extra_sensor_firmware (self )->None :
        self .task_manager .run_once ("extra_sensor_compile",lambda :self ._extra_firmware_worker ("compile"))
    def upload_extra_sensor_firmware (self )->None :
        self .task_manager .run_once ("extra_sensor_upload",lambda :self ._extra_firmware_worker ("upload"))
    def rollback_extra_sensor_firmware (self ,backup_path :Path |None =None )->None :
        self .task_manager .run_once ("extra_sensor_rollback",lambda :self ._extra_firmware_worker ("rollback",backup_path ))
    def sync_device_settings_now (self )->None :
        self .task_manager .run_once ("device_settings_sync_now",self ._device_settings_sync_task )
    def apply_remote_device_config (self ,data :dict )->None :
        sensors =data .get ("device_sensors",[])
        relays =data .get ("device_relays",[])
        scenarios =data .get ("scenarios",[])
        if isinstance (sensors ,list ):
            self .settings .set ("device_sensors",sensors ,save =False )
        if isinstance (relays ,list ):
            self .settings .set ("device_relays",relays ,save =False )
        if isinstance (scenarios ,list ):
            self .settings .set ("automation_scenarios",scenarios ,save =False )
        self .settings .set ("device_config_mode","custom",save =True )
        self .device_config =DeviceConfigManager (self .settings ,self .extra_config )
        self ._after_device_config_changed ("Конфигурация с сайта применена")
    def check_desktop_update (self )->None :
        self .task_manager .run_once ("desktop_update_check",self ._desktop_update_check_task )
    def download_desktop_update (self )->None :
        self .task_manager .run_once ("desktop_update_download",self ._desktop_update_download_task )
    def _desktop_update_check_task (self )->None :
        info =self .updater .check ()
        self .last_update_info =info
        if info .ok :
            self .settings .update (
            {
            "updates_last_checked_at":datetime .now ().isoformat (timespec ="seconds"),
            "updates_last_version":info .latest_version,
            },
            save =True ,
            )
            detail =info .message
            if info .latest_version :
                detail +=f": {info .latest_version }"
            if info .changelog :
                detail +=". Изменения: "+"; ".join (info .changelog [:5 ])
            self .event_bus .post ("desktop_update_status",True ,level ="info",message =detail )
        else :
            self .event_bus .post ("desktop_update_status",False ,level ="warning",message =info .message )
    def _desktop_update_download_task (self )->None :
        info =self .last_update_info or self .updater .check ()
        self .last_update_info =info
        if not info .ok :
            self .event_bus .post ("desktop_update_status",False ,level ="warning",message =info .message )
            return
        result =self .updater .download (info )
        self .event_bus .post ("desktop_update_status",result .ok ,level ="info"if result .ok else "error",message =result .message )
    def use_standard_device_config (self )->None :
        self .device_config .use_standard_config (save =True )
        self ._after_device_config_changed ("Включена стандартная конфигурация ФитоДомик")
    def use_empty_device_config (self )->None :
        if not messagebox .askyesno ("Пустая конфигурация","Скрыть датчики, графики и реле до добавления своей конфигурации?"):
            return
        self .device_config .use_empty_config (save =True )
        self .sensor_history .clear ()
        self ._after_device_config_changed ("Включена пустая конфигурация устройства")
    def _after_device_config_changed (self ,message :str )->None :
        self ._sync_optional_tabs ()
        if "monitoring"in self .tabs and hasattr (self .tabs ["monitoring"],"rebuild"):
            self .tabs ["monitoring"].rebuild ()
        if "charts"in self .tabs and hasattr (self .tabs ["charts"],"rebuild"):
            self .tabs ["charts"].rebuild ()
        if "control"in self .tabs and hasattr (self .tabs ["control"],"rebuild"):
            self .tabs ["control"].rebuild ()
        if "automation_scenarios"in self .tabs and hasattr (self .tabs ["automation_scenarios"],"rebuild"):
            self .tabs ["automation_scenarios"].rebuild ()
        settings_tab =self .tabs .get ("settings")
        if settings_tab is not None and hasattr (settings_tab ,"rebuild_sections"):
            first_run =getattr (settings_tab ,"first_run_view",None )
            try :
                first_run_open =bool (first_run and first_run .winfo_exists ()and getattr (first_run ,"active",True ))
            except tk .TclError :
                first_run_open =False
            if not first_run_open :
                settings_tab .rebuild_sections ()
        self .task_manager .run_once ("device_config_save",self ._save_dynamic_device_config_task )
        self ._set_status (message )
    def _save_dynamic_device_config_task (self )->None :
        self ._sync_api_token ()
        sensors =self .device_config .sensors (include_disabled =True )+self .device_config .extra_sensors ()
        relays =self .device_config .relays (include_disabled =True )
        scenarios =self .settings .get ("automation_scenarios",[])
        if not isinstance (scenarios ,list ):
            scenarios =[]
        ok =self .api .save_device_config (sensors ,relays ,scenarios )
        if ok :
            self .event_bus .post ("device_config_sync",True ,message ="Конфигурация устройства синхронизирована")
    def toggle_fullscreen (self )->None :
        current =bool (self .root .attributes ("-fullscreen"))
        self .root .attributes ("-fullscreen",not current )
        self .root .overrideredirect (bool (not current and self .settings .get ("kiosk_mode",True )))
        self .settings .set ("fullscreen",not current ,save =True )
    def close (self )->None :
        if self ._closing :
            return
        self ._closing =True
        self .screen_power .stop ()
        try :
            if self ._event_after_id :
                self .root .after_cancel (self ._event_after_id )
        except tk .TclError :
            pass
        try :
            if self ._resize_after_id :
                self .root .after_cancel (self ._resize_after_id )
        except tk .TclError :
            pass
        self .cancel_device_pair_poll ()
        try :
            self .root .withdraw ()
        except tk .TclError :
            pass
        try :
            self .task_manager .request_stop_all ()
        except Exception :
            self .logger .exception ("Не удалось отправить сигнал остановки фоновым задачам")
        try :
            self .arduino .request_stop ()
        except Exception :
            self .logger .exception ("Не удалось отправить сигнал остановки Arduino")
        self ._shutdown_done =threading .Event ()
        threading .Thread (target =self ._shutdown_services ,name ="shutdown-services",daemon =False ).start ()
        self .root .after (100 ,self ._finish_shutdown )
    def _finish_shutdown (self )->None :
        if not self ._shutdown_done .is_set ():
            self .root .after (100 ,self ._finish_shutdown )
            return
        try :
            self .root .quit ()
            self .root .destroy ()
        except tk .TclError :
            pass
    def _shutdown_services (self )->None :
        try :
            self .task_manager .stop_all ()
        except Exception :
            self .logger .exception ("Не удалось остановить фоновые задачи")
        try :
            self .arduino .stop ()
        except Exception :
            self .logger .exception ("Не удалось остановить службу Arduino")
        try :
            self .plant_analyzer .release_camera ()
            self .settings .save ()
        except Exception :
            self .logger .exception ("Не удалось завершить сохранение состояния")
        finally :
            self ._shutdown_done .set ()
    def apply_settings_changes (self )->None :
        self ._sync_api_token ()
        new_scale =self ._resolve_ui_scale ()
        if abs (new_scale -self .ui_scale )>=0.02 :
            self .ui_scale =new_scale
            self .theme .set_scale (new_scale )
            self .icons .set_size (self .icon_size (30 ))
        self .palette =self .theme .apply (self .root )
        self ._refresh_sidebar_palette ()
        self ._apply_scaled_layout ()
        self ._sync_optional_tabs ()
        for tab in self .tabs .values ():
            if hasattr (tab ,"refresh_palette"):
                tab .refresh_palette ()
        if "settings"in self .tabs and hasattr (self .tabs ["settings"],"refresh_settings_values"):
            self .tabs ["settings"].refresh_settings_values ()
        try :
            self .root .attributes ("-fullscreen",bool (self .settings .get ("fullscreen",True )))
            self .root .overrideredirect (bool (self .settings .get ("fullscreen",True )and self .settings .get ("kiosk_mode",True )))
        except tk .TclError :
            pass
        self .refresh_weather ()
    def _refresh_sidebar_palette (self )->None :
        if self .sidebar :
            self .sidebar .configure (style =SIDEBAR_FRAME_STYLE )
        if self .menu_scroll :
            self .menu_scroll .canvas_bg =self .palette .surface_2
            self .menu_scroll .set_palette (self .palette )
    def _sync_optional_tabs (self )->None :
        if not self ._main_built or not hasattr (self ,"content"):
            return
        visible_ids =[tab_id for tab_id ,_label ,_icon_name in self ._tab_defs ()]
        visible_set =set (visible_ids )
        for tab_id in self .tabs .keys ()-visible_set :
            if tab_id not in visible_set :
                tab =self .tabs .pop (tab_id )
                tab .destroy ()
        self ._rebuild_sidebar_buttons ()
        if self .active_tab not in visible_set :
            self .show_tab (self ._fallback_tab_id ())
    def clear_remote_data (self )->None :
        self .latest_thresholds =None
        self .latest_schedule =None
        if "thresholds"in self .tabs :
            self .tabs ["thresholds"].set_thresholds (None )
            self .tabs ["thresholds"].set_schedule (None )
            self .tabs ["thresholds"].set_upload_status ("API-аккаунт не привязан")
        if hasattr (self ,"api_status_label"):
            self .api_status_label .configure (text ="API: аккаунт не привязан")
    def _weather_task (self )->None :
        started =time_module .perf_counter ()
        try :
            data =self .weather_service .fetch_current (max_seconds =10 )
            if data :
                self ._note_network_result (True )
                self .event_bus .post ("weather_update",data )
            else :
                self ._note_network_result (False ,"weather")
                message =self .weather_service .last_error or "Погода недоступна"
                self .event_bus .post ("weather_error",level ="warning",message =message )
        finally :
            log_perf ("weather loaded",time_module .perf_counter ()-started )
    def _token_check_task (self )->None :
        started =time_module .perf_counter ()
        try :
            self ._sync_api_token ()
            has_token =bool (self .settings .get ("token","").strip ())
            ok =self .api .check_token ()
            if has_token :
                self ._note_network_result (ok ,"api token")
            if ok :
                self ._save_dynamic_device_config_task ()
            self .event_bus .post ("api_status",ok ,level ="info"if ok else "warning")
        finally :
            log_perf ("api token checked",time_module .perf_counter ()-started )
    def _link_account_worker (self ,username :str ,password :str )->None :
        ok ,data ,message =self .api .link_account (
        username ,
        password ,
        self ._device_name (),
        str (self .settings .get ("device_id","")),
        )
        if ok and data :
            token =str (data .get ("token",""))
            device_id =str (data .get ("device_id",self .settings .get ("device_id","")))
            device_token =str (data .get ("device_token",self .settings .get ("device_token","")))
            device_name =str (data .get ("device_name",self ._device_name ()))
            self .settings .update (
            {
            "token":token ,
            "device_id":device_id ,
            "device_token":device_token ,
            "device_name":device_name ,
            "account_username":username .strip (),
            },
            save =True ,
            )
            self ._sync_api_token ()
            self ._save_dynamic_device_config_task ()
            self .event_bus .post ("account_link",True ,message =message or ACCOUNT_LINKED_TEXT )
            return
        self .event_bus .post ("account_link",False ,level ="warning",message =message )
    def _device_list_worker (self )->None :
        self ._sync_api_token ()
        ok ,devices ,message =self .api .list_devices ()
        self .event_bus .post (
        "device_list",
        {"devices":devices },
        level ="info"if ok else "warning",
        message =message ,
        )
    def _device_pair_create_worker (self )->None :
        device_name =self ._device_name ()
        ok ,data ,message =self .api .create_device_pair (device_name )
        if ok and data :
            self .settings .set ("device_name",device_name ,save =True )
            self .event_bus .post ("device_pair_created",data ,message =message )
            return
        self .event_bus .post ("device_pair_error",level ="warning",message =message )
    def _device_pair_status_worker (self ,pair_code :str ,pair_secret :str )->None :
        ok ,data ,message =self .api .get_device_pair_status (pair_code ,pair_secret )
        if not ok or not data :
            self .event_bus .post ("device_pair_status",{"status":"error","pair_code":pair_code },level ="warning",message =message )
            return
        status =str (data .get ("status","pending"))
        if status =="approved":
            token_ok ,token_data ,token_message =self .api .get_device_pair_token (pair_code ,pair_secret )
            if token_ok and token_data :
                token =str (token_data .get ("token",""))
                device_id =str (token_data .get ("device_id",""))
                device_token =str (token_data .get ("device_token",""))
                device_name =str (token_data .get ("device_name",self ._device_name ()))
                username =str (token_data .get ("username",self .settings .get ("account_username","")))
                self .settings .update (
                {"token":token ,"device_id":device_id ,"device_token":device_token ,"device_name":device_name ,"account_username":username },
                save =True ,
                )
                self ._sync_api_token ()
                self .event_bus .post ("device_pair_approved",token_data ,message =token_message or ACCOUNT_LINKED_TEXT )
                return
            self .event_bus .post ("device_pair_status",{"status":"error","pair_code":pair_code },level ="warning",message =token_message )
            return
        self .event_bus .post ("device_pair_status",data ,message =message ,level ="warning"if status in {"expired","cancelled"}else "info")
    def _unlink_device_worker (self )->None :
        self ._sync_api_token ()
        device_id =str (self .settings .get ("device_id",""))
        ok ,message =self .api .unlink_device (device_id )
        self .settings .update ({"token":"","device_id":"","device_token":""},save =True )
        self ._sync_api_token ()
        self .event_bus .post ("device_unlink",ok ,level ="info"if ok else "warning",message =message )
    def _weather_tokens_check_worker (self )->None :
        city =self .settings .get ("weather_city","Moscow")
        ok ,message =self .weather_service .check_api_keys (city )
        self .event_bus .post ("weather_tokens_check",ok ,level ="info"if ok else "warning",message =message )
    def _wifi_scan_worker (self )->None :
        result =self .wifi_manager .scan ()
        level ="info"if result .status .available or result .status .connected else "warning"
        self .event_bus .post ("wifi_scan",result ,level =level ,message =result .status .message )
    def _wifi_connect_worker (self ,ssid :str ,password :str )->None :
        result =self .wifi_manager .connect (ssid ,password )
        self .event_bus .post ("wifi_connect",result ,level ="info"if result .ok else "warning",message =result .message )
    def _wifi_disconnect_worker (self )->None :
        result =self .wifi_manager .disconnect ()
        self .event_bus .post ("wifi_disconnect",result ,level ="info"if result .ok else "warning",message =result .message )
    def _wifi_forget_worker (self ,ssid :str )->None :
        result =self .wifi_manager .forget (ssid )
        self .event_bus .post ("wifi_forget",result ,level ="info"if result .ok else "warning",message =result .message )
    def _wifi_internet_worker (self )->None :
        result =self .wifi_manager .check_internet ()
        self .event_bus .post ("wifi_internet",result ,level ="info"if result .ok else "warning",message =result .message )
    def _thresholds_task (self )->None :
        started =time_module .perf_counter ()
        try :
            self ._sync_api_token ()
            if not self .settings .get ("token","").strip ():
                self .event_bus .post (
                "thresholds_update",
                {"thresholds":None ,"schedule":None ,"skipped":True },
                message ="API-аккаунт не привязан, серверные пороги не загружаются",
                )
                return
            thresholds =self .api .get_thresholds ()
            schedule =self .api .get_schedule ()
            self ._note_network_result (bool (thresholds or schedule ),"thresholds")
            if thresholds :
                self .latest_thresholds =thresholds
            if schedule :
                self .latest_schedule =schedule
            self .event_bus .post ("thresholds_update",{"thresholds":thresholds ,"schedule":schedule })
            if thresholds and schedule and self .settings .get ("auto_upload_thresholds",True ):
                self ._upload_thresholds_worker ()
        finally :
            log_perf ("thresholds loaded",time_module .perf_counter ()-started )
    def _history_task (self )->None :
        snapshot =self ._calibrated_snapshot (self .arduino .current_snapshot )
        if snapshot .has_sensor_values ()and self .device_config .snapshot_has_configured_values (snapshot ):
            self .sensor_history .add (snapshot )
            self .sensor_queue .add_snapshot (snapshot ,str (self .settings .get ("device_id","")))
            dynamic_values =self ._dynamic_values_from_snapshot (snapshot )
            if dynamic_values :
                self .dynamic_sensor_queue .add_values (dynamic_values ,str (self .settings .get ("device_id","")))
            self ._smart_photo_check (snapshot )
            if self .active_tab in {"charts","monitoring"}:
                self .event_bus .post ("history_update")
    def _dynamic_values_from_snapshot (self ,snapshot :SensorSnapshot )->list [dict [str ,object ]]:
        values :list [dict [str ,object ]]=[]
        measured_at =snapshot .timestamp .strftime ("%Y-%m-%d %H:%M:%S")
        for sensor in self .device_config .server_sensors ():
            if self .device_config .is_extra_sensor (sensor ):
                continue
            value =self .device_config .sensor_value (sensor ,snapshot )
            if value is None :
                continue
            values .append ({
            "sensor_uid":str (sensor .get ("sensor_uid","")),
            "value":value ,
            "raw_value":value ,
            "unit":str (sensor .get ("unit","")),
            "measured_at":measured_at ,
            })
        return values
    def _dynamic_value_from_extra (self ,sensor_value :ExtraSensorValue )->dict [str ,object ]|None :
        if sensor_value .status !="работает":
            return None
        sensor =None
        for item in self .device_config .extra_sensors ():
            if str (item .get ("sensor_uid",""))==sensor_value .sensor_id :
                sensor =item
                break
        if not sensor or not sensor .get ("send_to_server",False ):
            return None
        raw =sensor_value .as_float ()
        value :float |str |bool |None =raw
        if raw is not None :
            value =self ._apply_extra_calibration (raw ,sensor .get ("calibration",{}))
        elif isinstance (sensor_value .value ,(str ,bool )):
            value =sensor_value .value
        if value is None :
            return None
        return {
        "sensor_uid":sensor_value .sensor_id ,
        "value":value if isinstance (value ,(int ,float ))and not isinstance (value ,bool )else raw ,
        "raw_value":sensor_value .value ,
        "unit":sensor_value .unit or str (sensor .get ("unit","")),
        "measured_at":sensor_value .timestamp .strftime ("%Y-%m-%d %H:%M:%S"),
        }
    def _apply_extra_calibration (self ,raw :float ,calibration )->float :
        if not isinstance (calibration ,dict )or not calibration .get ("enabled",True ):
            return raw
        calibration_type =str (calibration .get ("calibration_type",calibration .get ("type","raw"))).lower ()
        if calibration_type =="linear_percent":
            dry =_optional_float (calibration .get ("dry_raw"))
            wet =_optional_float (calibration .get ("wet_raw"))
            min_value =_optional_float (calibration .get ("min_value",0 ))
            max_value =_optional_float (calibration .get ("max_value",100 ))
            if dry is None or wet is None or min_value is None or max_value is None :
                return raw
            if dry ==wet :
                return raw
            ratio =(raw -dry )/(wet -dry )
            value =min_value +ratio *(max_value -min_value )
            low ,high =sorted ((min_value ,max_value ))
            return max (low ,min (high ,value ))
        if calibration_type =="linear_voltage":
            reference =_optional_float (calibration .get ("reference_voltage",5.0 ))or 5.0
            resolution =_optional_float (calibration .get ("adc_max",1023 ))or 1023.0
            return raw *reference /resolution
        return raw
    def _smart_photo_check (self ,snapshot :SensorSnapshot )->None :
        if not self .settings .get ("smart_photo_enabled",True )or self .analysis_running :
            self ._last_smart_photo_snapshot =snapshot
            return
        now =datetime .now ()
        last_at =self ._setting_datetime ("last_smart_photo_at")
        min_interval =int (self .settings .get ("smart_photo_min_interval_minutes",120 ))
        if last_at and (now -last_at ).total_seconds ()<min_interval *60 :
            self ._last_smart_photo_snapshot =snapshot
            return
        previous =self ._last_smart_photo_snapshot
        reasons =self ._smart_photo_change_reasons (previous ,snapshot )
        no_analysis_hours =int (self .settings .get ("smart_photo_if_no_analysis_hours",48 )or 0 )
        if self ._smart_photo_analysis_overdue (last_at ,now ,no_analysis_hours ):
            reasons .append ("давно не было анализа")
        alert_reason =self ._smart_photo_alert_reason (snapshot )
        if alert_reason :
            reasons .append (alert_reason )
        self ._last_smart_photo_snapshot =snapshot
        if reasons :
            self .settings .set ("last_smart_photo_at",now .isoformat (timespec ="seconds"),save =True )
            self .notifications .add ("Умное автофото","Запуск: "+", ".join (reasons ),event_type ="photo",level ="info")
            self .run_plant_analysis (is_auto =True )
    def _setting_datetime (self ,key :str )->datetime |None :
        text =str (self .settings .get (key ,""))
        try :
            return datetime .fromisoformat (text )if text else None
        except ValueError :
            return None
    def _smart_photo_change_reasons (self ,previous :SensorSnapshot ,snapshot :SensorSnapshot )->list [str ]:
        reasons :list [str ]=[]
        if self .settings .get ("smart_photo_on_temperature_jump",True )and previous .temperature is not None and snapshot .temperature is not None :
            delta =abs (float (snapshot .temperature )-float (previous .temperature ))
            if delta >=float (self .settings .get ("smart_photo_temperature_jump_delta",4.0 )):
                reasons .append (f"скачок температуры {delta :.1f} C")
        if self .settings .get ("smart_photo_on_soil_drop",True )and previous .soil_moisture is not None and snapshot .soil_moisture is not None :
            delta =float (previous .soil_moisture )-float (snapshot .soil_moisture )
            if delta >=float (self .settings .get ("smart_photo_soil_drop_delta",15.0 )):
                reasons .append (f"падение влажности почвы {delta :.1f}%")
        if self .settings .get ("smart_photo_on_light_on",True )and not previous .lamp_state and snapshot .lamp_state :
            reasons .append ("включилось освещение")
        return reasons
    def _smart_photo_analysis_overdue (self ,last_at :datetime |None ,now :datetime ,hours :int )->bool :
        return hours >0 and (last_at is None or (now -last_at ).total_seconds ()>=hours *3600 )
    def _smart_photo_alert_reason (self ,snapshot :SensorSnapshot )->str |None :
        if not self .settings .get ("smart_photo_if_alert",True )or not isinstance (self .latest_thresholds ,dict ):
            return None
        checks =[
        ("temperature",snapshot .temperature ,"температура вне порога"),
        ("humidity_air",snapshot .humidity ,"влажность воздуха вне порога"),
        ("humidity_soil",snapshot .soil_moisture ,"влажность почвы вне порога"),
        ("co2",snapshot .co2 ,"CO2 вне порога"),
        ]
        for key ,value ,reason in checks :
            limits =self .latest_thresholds .get (key ,{})
            if value is None or not isinstance (limits ,dict ):
                continue
            min_value =limits .get ("min_limit",limits .get ("min"))
            max_value =limits .get ("max_limit",limits .get ("max"))
            below =min_value is not None and float (value )<float (min_value )
            above =max_value is not None and float (value )>float (max_value )
            if below or above :
                return reason
        return None
    def _server_send_task (self )->None :
        self ._sync_api_token ()
        had_unsynced =any (not item .get ("synced")for item in self .sensor_queue .all ())
        had_dynamic_unsynced =any (not item .get ("synced")for item in self .dynamic_sensor_queue .all ())
        synced =self ._sync_sensor_queue ()
        dynamic_synced =self ._sync_dynamic_sensor_queue ()
        total_synced =synced +dynamic_synced
        if total_synced >0 :
            self ._note_network_result (True )
            self .event_bus .post ("server_send_status",True ,message =f"Синхронизировано записей: {total_synced }")
        elif had_unsynced or had_dynamic_unsynced :
            self ._note_network_result (False ,"server send")
            self .event_bus .post ("server_send_status",False ,level ="warning",message ="Сервер временно недоступен")
        else :
            self ._note_network_result (True )
            self .event_bus .post ("server_send_status",True ,message ="Нет новых локальных записей")
    def _sync_sensor_queue (self )->int :
        items =self .sensor_queue .all ()
        synced =0
        changed =False
        for item in items :
            if item .get ("synced")or str (item .get ("device_id",""))!=str (self .api .device_id ):
                continue
            if self .api .send_sensor_record (item ):
                item ["synced"]=True
                synced +=1
                changed =True
        if changed :
            self .sensor_queue .update_records (items )
            self .notifications .add ("Синхронизация данных",f"Синхронизировано {synced } локальных записей",event_type ="sync",level ="info")
            self .event_bus .post ("notifications_update",message =f"Синхронизировано локальных записей: {synced }")
        elif any (not item .get ("synced")for item in items ):
            self .notifications .add ("Интернет недоступен","Данные датчиков сохраняются локально",event_type ="sync",level ="warning")
            self .event_bus .post ("notifications_update",message ="Данные сохраняются локально")
        return synced
    def _local_queue_sync_task (self )->None :
        self ._sync_api_token ()
        self ._sync_sensor_queue ()
        self ._sync_dynamic_sensor_queue ()
        self ._sync_photo_queue ()
        self ._sync_command_result_queue ()
        self ._sync_fault_queue ()
        self ._sync_scenario_log_queue ()
    def _sync_dynamic_sensor_queue (self )->int :
        items =self .dynamic_sensor_queue .all ()
        synced =0
        changed =False
        for item in items :
            if item .get ("synced")or str (item .get ("device_id",""))!=str (self .api .device_id ):
                continue
            values =item .get ("values",[])
            if not isinstance (values ,list )or not values :
                item ["synced"]=True
                changed =True
                continue
            if self .api .upload_dynamic_sensor_values (values ):
                item ["synced"]=True
                synced +=len (values )
                changed =True
        if changed :
            self .dynamic_sensor_queue .update_and_remove_synced (items )
        return synced
    def _sync_photo_queue (self )->None :
        items =self .photo_queue .all ()
        changed =False
        for item in items :
            changed =self ._sync_photo_item (item )or changed
        if changed :
            removed =self .photo_queue .update_and_remove_synced (items ,keys =("server_sent",))
            if removed :
                self .notifications .add ("Синхронизация фото",f"Отправлено фото: {removed }",event_type ="photo",level ="info")
                self .event_bus .post ("notifications_update",message =f"Отправлено фото из очереди: {removed }")
    def _sync_photo_item (self ,item :dict )->bool :
        if str (item .get ("device_id",""))!=str (self .api .device_id ):
            return False
        original =Path (str (item .get ("file_path","")))
        analysis =Path (str (item .get ("analysis_path","")))if item .get ("analysis_path")else original
        report =Path (str (item .get ("report_path","")))if item .get ("report_path")else None
        ready =item .get ("send_to_server")and not item .get ("server_sent")
        if not ready or not original .exists ()or not analysis .exists ():
            return False
        text =report .read_text (encoding ="utf-8")if report and report .exists ()else "Фото с камеры"
        if not self .api .upload_plant_images (original ,analysis ,text ):
            return False
        item ["server_sent"]=True
        return True
    def _sync_fault_queue (self )->int :
        items =self .fault_queue .all ()
        changed =False
        sent =0
        for item in items :
            if item .get ("synced")or item .get ("device_id")!=self .api .device_id :
                continue
            if item .get ("capture_pending"):
                try :
                    if (datetime .now ()-datetime .fromisoformat (item ["created_at"])).total_seconds ()<300:
                        continue
                except (ValueError ,KeyError ):
                    continue
            if item .get ("photo_path")and not item .get ("photo_file"):
                photo_path =Path (item ["photo_path"])
                if photo_path .is_file ():
                    ok ,upload =self .api .upload_photo_result (photo_path ,"Аварийное отключение насоса")
                    if ok :
                        item ["photo_file"]=Path (str (upload .get ("file_path")or "")).name
                        changed =True
            if self .api .report_device_fault (
                str (item ["id"]),str (item ["fault_code"]),str (item .get ("photo_file")or "")
            ):
                item ["synced"]=not item .get ("photo_path")or bool (item .get ("photo_file"))
                changed =True
                sent +=1
        if changed :
            self .fault_queue .update_and_remove_synced (items )
        return sent
    def _report_pump_fault_worker (self ,record :dict )->None :
        try :
            photo_path =self .plant_analyzer .capture_photo_file ("pump_fault")
            record ["photo_path"]=str (photo_path )
            self .fault_queue .update_records ([record ])
            ok ,upload =self .api .upload_photo_result (photo_path ,"Аварийное отключение насоса")
            if ok :
                file_path =str (upload .get ("file_path")or "")
                if file_path :
                    record ["photo_file"]=Path (file_path ).name
                    self .fault_queue .update_records ([record ])
        except Exception :
            self .logger .exception ("Не удалось снять фото после аварии насоса")
        finally :
            record ["capture_pending"]=False
            self .fault_queue .update_records ([record ])
        self ._sync_fault_queue ()
    def _sync_command_result_queue (self )->int :
        items =self .command_result_queue .all ()
        synced =0
        changed =False
        for item in items :
            if item .get ("synced")or str (item .get ("device_id",""))!=str (self .api .device_id ):
                continue
            ok =self .api .send_device_command_result (
            int (item .get ("command_id",0 )),
            str (item .get ("status","error")),
            str (item .get ("message","")),
            item .get ("result_payload",{}),
            str (item .get ("started_at","")),
            str (item .get ("finished_at","")),
            )
            if ok :
                item ["synced"]=True
                synced +=1
                changed =True
        if changed :
            self .command_result_queue .update_and_remove_synced (items )
        return synced
    def _sync_scenario_log_queue (self )->int :
        items =self .scenario_log_queue .all ()
        synced =0
        changed =False
        for item in items :
            if item .get ("synced")or str (item .get ("device_id",""))!=str (self .api .device_id ):
                continue
            if self .api .send_scenario_log (
            str (item .get ("scenario_id","")),
            str (item .get ("message","")),
            item .get ("payload",{}),
            str (item .get ("event_type","triggered")),
            ):
                item ["synced"]=True
                synced +=1
                changed =True
        if changed :
            self .scenario_log_queue .update_and_remove_synced (items )
        return synced
    def _device_settings_sync_task (self )->None :
        self ._sync_api_token ()
        data =self .api .get_device_notification_settings ()
        if not data :
            return
        self ._apply_remote_notification_settings (data )
        self ._apply_remote_automation_scenarios (self .api .get_device_config ())
        self .notifications .add ("Настройки синхронизированы","Настройки уведомлений устройства загружены с сайта",event_type ="sync",level ="info")
        self .event_bus .post ("notifications_update",message ="Настройки уведомлений загружены с сайта")
    def _apply_remote_notification_settings (self ,data :dict )->None :
        notification =data .get ("telegram",{})
        if isinstance (notification ,dict ):
            check_interval =self ._notification_int (notification ,"check_interval_minutes","alert_check_interval_minutes",60 )
            cooldown =self ._notification_int (notification ,"alert_repeat_cooldown_minutes","alert_repeat_cooldown_minutes",180 )
            self .settings .set ("alert_check_interval_minutes",check_interval ,save =True )
            self .settings .set ("alert_repeat_cooldown_minutes",cooldown ,save =True )
        rules =data .get ("alert_rules",[])
        if isinstance (rules ,list )and rules :
            self .settings .set ("alert_rules",rules ,save =True )
    def _notification_int (self ,data :dict ,remote_key :str ,setting_key :str ,default :int )->int :
        fallback =int (self .settings .get (setting_key ,default ))
        try :
            return int (data .get (remote_key ,fallback ))
        except (TypeError ,ValueError ):
            return fallback
    def _apply_remote_automation_scenarios (self ,config )->None :
        if not isinstance (config ,dict ):
            return
        scenarios =config .get ("scenarios",[])
        if not isinstance (scenarios ,list ):
            return
        previous =self .settings .get ("automation_scenarios",[])
        last_triggered ={
            str (item .get ("scenario_id")or item .get ("id")):item .get ("last_triggered_at")
            for item in previous if isinstance (item ,dict )
        }if isinstance (previous ,list )else {}
        for scenario in scenarios :
            if isinstance (scenario ,dict ):
                key =str (scenario .get ("scenario_id")or scenario .get ("id"))
                if key in last_triggered and last_triggered [key]:
                    scenario ["last_triggered_at"]=last_triggered [key]
        self .settings .set ("automation_scenarios",scenarios ,save =True )
        tab =self .tabs .get ("automation_scenarios")
        if tab is not None and hasattr (tab ,"rebuild"):
            self .root .after (0,tab .rebuild )
    def _alert_rules_task (self )->None :
        self .alert_engine .check (self .latest_snapshot )
    def _automation_scenarios_task (self )->None :
        scenarios =self .settings .get ("automation_scenarios",[])
        if not isinstance (scenarios ,list )or not scenarios :
            return
        values =self ._automation_sensor_values ()
        changed =False
        now =datetime .now ()
        for scenario in scenarios :
            if isinstance (scenario ,dict )and self ._process_automation_scenario (scenario ,values ,now ):
                changed =True
        if changed :
            self .settings .set ("automation_scenarios",scenarios ,save =True )
    def _process_automation_scenario (self ,scenario :dict ,values :dict [str ,float |None ],now :datetime )->bool :
        if not scenario .get ("enabled",True ):
            return False
        scenario_id =str (scenario .get ("scenario_id")or scenario .get ("id")or "")
        if not scenario_id or self ._scenario_in_cooldown (scenario ,now ):
            return False
        if not self ._scenario_conditions_met (scenario ,values ,now ):
            return False
        ok ,message =self ._run_scenario_actions (scenario )
        scenario ["last_triggered_at"]=now .isoformat (timespec ="seconds")
        level ="info"if ok else "warning"
        self .notifications .add ("Сценарий",message ,event_type ="automation_scenario",level =level )
        self .event_bus .post ("notifications_update",message =message )
        if not self .api .send_scenario_log (scenario_id ,message ,{"ok":ok },"triggered"):
            self .scenario_log_queue .add_log (device_id =str (self .api .device_id ),scenario_id =scenario_id ,message =message ,payload ={"ok":ok })
        return True
    def _scenario_in_cooldown (self ,scenario :dict ,now :datetime )->bool :
        cooldown =max (60 ,int (scenario .get ("cooldown_seconds",0 )or 0 ))
        last_text =str (scenario .get ("last_triggered_at",""))
        try :
            last_at =datetime .fromisoformat (last_text )if last_text else None
        except ValueError :
            last_at =None
        return bool (last_at and (now -last_at ).total_seconds ()<cooldown )
    def _automation_sensor_values (self )->dict [str ,float |None ]:
        values :dict [str ,float |None ]={}
        snapshot =self .latest_snapshot
        for sensor in self .device_config .monitoring_sensors ():
            uid =str (sensor .get ("sensor_uid",""))
            if not uid :
                continue
            if self .device_config .is_extra_sensor (sensor ):
                latest =self .extra_history .latest_for (uid )
                values [uid]=latest .as_float ()if latest else None
            else :
                values [uid]=self .device_config .sensor_value (sensor ,snapshot )
        return values
    def _scenario_conditions_met (self ,scenario :dict ,values :dict [str ,float |None ],now :datetime )->bool :
        conditions =scenario .get ("conditions",[])
        if not isinstance (conditions ,list )or not conditions :
            return False
        scenario_id =str (scenario .get ("scenario_id")or scenario .get ("id")or "")
        for index ,condition in enumerate (conditions ):
            if not isinstance (condition ,dict ):
                return False
            sensor_uid =str (condition .get ("sensor_uid",""))
            key =f"{scenario_id}:{index}"
            if not self ._condition_matches (condition ,values .get (sensor_uid ),now ):
                self ._scenario_condition_since .pop (key ,None )
                return False
            if not self ._condition_duration_elapsed (key ,condition ,now ):
                return False
        return True
    def _condition_matches (self ,condition :dict ,current :float |None ,now :datetime )->bool :
        operator =str (condition .get ("operator","")).strip ().lower ()
        if operator =="no_data":
            return current is None
        if operator =="time_between":
            start =str (condition .get ("from",condition .get ("start","00:00")))
            end =str (condition .get ("to",condition .get ("end","23:59")))
            current_text =now .strftime ("%H:%M")
            return bool (start <=current_text <=end if start <=end else current_text >=start or current_text <=end )
        if operator in {"value","has_data"}:
            return current is not None
        if current is None :
            return False
        target =self ._float_or_none (condition .get ("value"))
        target_to =self ._float_or_none (condition .get ("value_to"))
        comparisons ={
        "<":lambda :target is not None and current <target ,
        ">":lambda :target is not None and current >target ,
        "=":lambda :target is not None and abs (current -target )<0.0001 ,
        "==":lambda :target is not None and abs (current -target )<0.0001 ,
        "between":lambda :target is not None and target_to is not None and min (target ,target_to )<=current <=max (target ,target_to ),
        "not_between":lambda :target is not None and target_to is not None and not (min (target ,target_to )<=current <=max (target ,target_to )),
        }
        comparison =comparisons .get (operator )
        return bool (comparison and comparison ())
    def _condition_duration_elapsed (self ,key :str ,condition :dict ,now :datetime )->bool :
        duration =max (0 ,int (condition .get ("duration_seconds",0 )or 0 ))
        if duration <=0 :
            return True
        since =self ._scenario_condition_since .setdefault (key ,now )
        return (now -since ).total_seconds ()>=duration
    def _run_scenario_actions (self ,scenario :dict )->tuple [bool ,str ]:
        actions =scenario .get ("actions",[])
        if not isinstance (actions ,list )or not actions :
            return False ,"У сценария нет действий"
        ok_all =True
        messages :list [str ]=[]
        for action in actions :
            if not isinstance (action ,dict ):
                continue
            try :
                ok ,action_message =self ._run_scenario_action (action )
                messages .append (action_message )
                ok_all =ok_all and ok
            except Exception as exc :
                ok_all =False
                messages .append (str (exc ))
        name =str (scenario .get ("name")or scenario .get ("scenario_id")or "Сценарий")
        return ok_all ,f"{name}: "+("; ".join (messages )if messages else "выполнен")
    def _run_scenario_action (self ,action :dict )->tuple [bool ,str ]:
        action_type =str (action .get ("type",""))
        relay_uid =str (action .get ("relay_uid",""))
        relay_number =self ._relay_number_from_uid (relay_uid )
        if not relay_number and action_type in {"relay_on","relay_off","relay_pulse"}:
            known =any (str (relay .get ("relay_uid","")).upper ()==relay_uid .upper ()and relay .get ("enabled",True )for relay in self .device_config .relays ())
            if not known :
                return False ,"Неизвестное дополнительное реле"
            if action_type !="relay_pulse":
                desired =action_type =="relay_on"
                return self .arduino .send_command (relay_uid ,desired ),f"{relay_uid}: {action_type}"
            seconds =max (0.2 ,min (30.0 ,float (action .get ("duration_seconds",3 )or 3 )))
            if not self .arduino .send_command (relay_uid ,True ):
                return False ,"Arduino не подтвердила включение реле"
            off_ok =False
            try :
                time_module .sleep (seconds )
            finally :
                off_ok =self .arduino .send_command (relay_uid ,False )
            return off_ok ,f"{relay_uid}: импульс {seconds :.1f} сек."
        if action_type in {"relay_on","relay_off"}and relay_number :
            desired =action_type =="relay_on"
            ok =self ._set_relay_state (relay_number ,desired )
            return ok ,f"{relay_uid}: {'вкл' if desired else 'выкл'}"
        if action_type =="relay_pulse"and relay_number :
            seconds =max (0.2 ,min (30.0 ,float (action .get ("duration_seconds",3 )or 3 )))
            return self ._pulse_relay (relay_number ,seconds ),f"{relay_uid}: импульс {seconds :.1f} сек."
        if action_type in {"take_photo","run_analysis"}:
            self .run_plant_analysis (is_auto =True )
            return True ,"запущено фото/анализ"
        if action_type in {"send_notification","notify"}:
            return True ,str (action .get ("message")or "уведомление")
        if action_type in {"create_command","device_command"}:
            return self ._run_scenario_device_command (str (action .get ("command_type","")))
        return False ,f"{relay_uid or action_type}: действие пока не поддерживается прошивкой"
    def _run_scenario_device_command (self ,command_type :str )->tuple [bool ,str ]:
        handlers ={
        "sync_device_config":self ._save_dynamic_device_config_task ,
        "request_device_config":self ._save_dynamic_device_config_task ,
        "take_photo":lambda :self .run_plant_analysis (is_auto =True ),
        "analyze_plant":lambda :self .run_plant_analysis (is_auto =True ),
        "refresh_thresholds":self ._thresholds_task ,
        }
        handler =handlers .get (command_type )
        if handler :
            handler ()
        return handler is not None ,f"команда устройства: {command_type}"
    def _relay_number_from_uid (self ,relay_uid :str )->int |None :
        mapping ={
        "fan_D7":1,
        "pump_D6":2,
        "curtains_D5":3,
        "lamp_D4":4,
        }
        if relay_uid in mapping :
            return mapping [relay_uid ]
        for index ,relay in enumerate (self .device_config .control_relays (),start =1 ):
            if str (relay .get ("relay_uid",""))==relay_uid and relay .get ("core",False ):
                return index if 1 <=index <=4 else None
        return None
    def _float_or_none (self ,value )->float |None :
        try :
            return float (value )
        except (TypeError ,ValueError ):
            return None
    def _photo_schedule_task (self )->None :
        if not self .settings .get ("photo_schedule_enabled",True ):
            return
        now =datetime .now ()
        interval_minutes =int (self .settings .get ("photo_interval_minutes",0 )or 0 )
        if interval_minutes >0 :
            self ._run_interval_photo_schedule (now ,interval_minutes )
            return
        hours =self ._scheduled_photo_hours ()
        next_text =self ._next_photo_text (now ,hours )
        self .event_bus .post ("next_photo",next_text )
        self ._run_hourly_photo_schedule (now ,hours )
    def _run_interval_photo_schedule (self ,now :datetime ,interval_minutes :int )->None :
        last_at =self ._setting_datetime ("last_interval_photo_at")
        elapsed_minutes =int ((now -last_at ).total_seconds ()//60 )if last_at else interval_minutes
        remaining =max (0 ,interval_minutes -elapsed_minutes )
        self .event_bus .post ("next_photo",f"До следующего фото: {remaining } мин.")
        if last_at and (now -last_at ).total_seconds ()<interval_minutes *60 :
            return
        self .settings .set ("last_interval_photo_at",now .isoformat (timespec ="seconds"),save =True )
        self .run_plant_analysis (is_auto =True )
    def _scheduled_photo_hours (self )->list [int ]:
        hours =[int (self .settings .get ("photo_time",12 ))]
        if int (self .settings .get ("photo_count",1 ))==2 :
            hours .append (int (self .settings .get ("photo_time2",18 )))
        return hours
    def _run_hourly_photo_schedule (self ,now :datetime ,hours :list [int ])->None :
        if now .minute >=5 or now .hour not in hours :
            return
        last_date =self .settings .get ("last_auto_photo_date","")
        last_hour =self .settings .get ("last_auto_photo_hour",None )
        today =date .today ().isoformat ()
        if last_date ==today and last_hour ==now .hour :
            return
        self .settings .update ({"last_auto_photo_date":today ,"last_auto_photo_hour":now .hour },save =True )
        self .run_plant_analysis (is_auto =True )
    def _chart_refresh_task (self )->None :
        if self .active_tab =="charts":
            self .event_bus .post ("history_update")
    def _auto_theme_task (self )->None :
        if self .settings .get ("theme_mode")=="auto":
            old =self .palette .name
            new =self .theme .resolve_palette ().name
            if old !=new :
                self .event_bus .post ("theme_changed")
    def _upload_thresholds_worker (self )->None :
        mode =self .settings .get ("thresholds_mode","auto")
        if mode =="manual":
            payload =self .arduino .protocol .build_manual_payload (
            self .settings .get ("manual_thresholds",{}),
            self .settings .get ("manual_schedule",{}),
            )
        else :
            thresholds =self .latest_thresholds or self .api .get_thresholds ()
            schedule =self .latest_schedule or self .api .get_schedule ()
            if not thresholds :
                self .event_bus .post ("arduino_upload",False ,level ="warning",message ="Нет порогов для загрузки")
                return
            payload =self .arduino .protocol .build_threshold_payload (thresholds ,schedule )
        ok =self .arduino .upload_thresholds (payload )
        if not ok :
            self .event_bus .post ("arduino_upload",False ,level ="warning",message ="Ошибка загрузки данных в Arduino")
    def _extra_firmware_worker (self ,action :str ,backup_path :Path |None =None )->None :
        sketch_path =BASE_DIR /ARDUINO_SKETCH_NAME
        fqbn =self .extra_config .fqbn ()
        sketch_dir =BASE_DIR
        library_paths =[BASE_DIR /"libraries",self .extra_libraries .destination_root]
        library_names =self .extra_code_generator .required_libraries (include_supported =True )
        if not self ._extra_firmware_prerequisites_ok (fqbn ):
            return
        if action =="rollback":
            self ._rollback_extra_firmware (sketch_path ,backup_path ,fqbn ,library_paths ,library_names )
            return
        if not self ._extra_sensor_config_valid ():
            return
        if action =="compile":
            result =self .arduino_cli .compile (sketch_dir ,fqbn ,library_paths =library_paths ,library_names =library_names )
            upload_started =0.0
        else :
            prepared =self ._compile_and_upload_extra_firmware (sketch_path ,fqbn ,library_paths ,library_names )
            if prepared is None :
                return
            result ,upload_started =prepared
        detail =self ._arduino_cli_result_detail (result )
        self ._post_extra_sensor_status (ARDUINO_CLI_TITLE ,detail ,result .ok )
        if action =="upload":
            self ._verify_extra_firmware_serial (result .ok ,upload_started )
    def _extra_firmware_prerequisites_ok (self ,fqbn :str )->bool :
        if not self .arduino_cli .available ():
            detail =self .arduino_cli .install_instructions ()
            self ._post_extra_sensor_status (ARDUINO_CLI_TITLE ,detail ,False ,"warning")
            return False
        fqbn_check =self .arduino_cli .validate_fqbn (fqbn )
        if not fqbn_check .ok:
            self .event_bus .post ("extra_sensor_status",False ,level ="error",message =fqbn_check .message )
            return False
        return True
    def _rollback_extra_firmware (self ,sketch_path :Path ,backup_path :Path |None ,fqbn :str ,library_paths :list [Path ],library_names :list [str ])->None :
        rollback =self .extra_code_generator .rollback_latest (sketch_path ,backup_path )
        if not rollback .ok :
            self .event_bus .post ("extra_sensor_status",False ,level ="error",message =rollback .message )
            return
        result =self .arduino_cli .compile (BASE_DIR ,fqbn ,library_paths =library_paths ,library_names =library_names )
        detail =rollback .message +". Проверка сборки: "+self ._arduino_cli_result_detail (result )
        self ._post_extra_sensor_status ("Откат прошивки",detail ,result .ok )
    def _extra_sensor_config_valid (self )->bool :
        validation =self .extra_config .validate_sensors ()
        if validation .ok:
            return True
        detail ="Прошивка не собрана: "+ "; ".join (validation .errors )
        self ._post_extra_sensor_status ("Прошивка доп. датчиков",detail ,False )
        return False
    def _compile_and_upload_extra_firmware (self ,sketch_path :Path ,fqbn :str ,library_paths :list [Path ],library_names :list [str ]):
        port =self .arduino .port or str (self .settings .get ("port",""))
        if not port:
            self ._post_extra_sensor_status (ARDUINO_CLI_TITLE ,"Upload отменён: Serial-порт Arduino не выбран",False ,"warning")
            return None
        backup =self .extra_code_generator .create_backup (sketch_path )
        if not backup .ok:
            self ._post_extra_sensor_status (ARDUINO_CLI_TITLE ,"Upload отменён: не удалось создать backup прошивки",False )
            return None
        compile_result =self .arduino_cli .compile (BASE_DIR ,fqbn ,library_paths =library_paths ,library_names =library_names )
        if not compile_result .ok :
            detail ="Upload отменён: сборка не прошла. Можно выбрать backup и выполнить откат прошивки: "+self ._arduino_cli_result_detail (compile_result )
            self ._post_extra_sensor_status (ARDUINO_CLI_TITLE ,detail ,False )
            return None
        upload_started =time_module .time ()
        self .arduino .clear_runtime_values ()
        self .arduino .request_stop ()
        self .arduino .disconnect (wait =False )
        return self .arduino_cli .upload (BASE_DIR ,fqbn ,port ),upload_started
    def _arduino_cli_result_detail (self ,result )->str :
        detail =result .message
        if result .stderr :
            detail +=f": {result .stderr [-700:]}"
        elif result .stdout :
            detail +=f": {result .stdout [-700:]}"
        return detail
    def _post_extra_sensor_status (self ,title :str ,detail :str ,ok :bool ,failure_level :str ="error")->None :
        level ="info"if ok else failure_level
        self .notifications .add (title ,detail ,event_type ="extra_sensors",level =level )
        self .event_bus .post ("extra_sensor_status",ok ,level =level ,message =detail )
    def _verify_extra_firmware_serial (self ,upload_ok :bool ,upload_started :float )->None :
        time_module .sleep (2 )
        self .arduino .start ()
        if not upload_ok :
            return
        extra_expected =any (item .get ("enabled",True )for item in self .extra_config .sensors ())
        self ._wait_for_uploaded_firmware (upload_started ,extra_expected )
        main_ok =self .arduino .last_main_serial_at >=upload_started
        extra_ok =(not extra_expected )or self .arduino .last_extra_serial_at >=upload_started
        serial_message =self ._firmware_serial_message (main_ok ,extra_ok ,extra_expected )
        self .event_bus .post ("extra_sensor_status",main_ok and extra_ok ,level ="info"if main_ok and extra_ok else "warning",message =serial_message )
    def _wait_for_uploaded_firmware (self ,upload_started :float ,extra_expected :bool )->None :
        deadline =time_module .time ()+24
        requested_status =False
        while time_module .time ()<deadline:
            if not requested_status and time_module .time ()-upload_started >6:
                self .arduino .request_status ()
                requested_status =True
            main_ok =self .arduino .last_main_serial_at >=upload_started
            extra_ok =(not extra_expected )or self .arduino .last_extra_serial_at >=upload_started
            if main_ok and extra_ok:
                return
            time_module .sleep (0.5 )
    def _firmware_serial_message (self ,main_ok :bool ,extra_ok :bool ,extra_expected :bool )->str :
        message ="Serial проверен: старый протокол/основной JSON получен"if main_ok else "Serial открыт, но старый протокол/основной JSON пока не получен"
        if not extra_expected:
            return message +"; дополнительных датчиков в конфигурации нет"
        if extra_ok:
            return message +"; EXTRA_SENSORS получен"
        return message +"; EXTRA_SENSORS не получен. Проверьте GeneratedConfig.h, пины, адреса, библиотеки и подключение датчика"
    def _handle_alert_relay_action (self ,action :str )->None :
        mapping ={
        "relay_fan_on":("FAN",True ),
        "relay_fan_off":("FAN",False ),
        "relay_light_on":("LAMP",True ),
        "relay_light_off":("LAMP",False ),
        "relay_pump_on":("PUMP",True ),
        "relay_pump_off":("PUMP",False ),
        "relay_curtains_open":("CURTAINS",True ),
        "relay_curtains_close":("CURTAINS",False ),
        }
        command =mapping .get (action )
        if not command :
            return
        device ,state =command
        if not self .arduino .send_command (device ,state ):
            self .notifications .add ("Ошибка управления",f"Не удалось выполнить действие тревоги: {action }",event_type ="alert",level ="error")
    def _analysis_worker (self ,is_auto :bool )->None :
        try :
            self ._sync_api_token ()
            result =self .plant_analyzer .run_analysis (None )
            report_text =result .report_path .read_text (encoding ="utf-8")
            send_to_server =bool (self .settings .get ("photo_send_to_server",True )and self .settings .get ("analysis_upload_enabled",True ))
            server_sent =False
            if send_to_server :
                server_sent =self .api .upload_plant_images (result .original_path ,result .detection_path ,report_text )
                result .uploaded =server_sent
            if send_to_server and not server_sent :
                self .photo_queue .add_photo (
                device_id =str (self .settings .get ("device_id","")),
                file_path =result .original_path ,
                analysis_path =result .detection_path ,
                report_path =result .report_path ,
                send_to_server =send_to_server and not server_sent ,
                )
            self .notifications .add (
            "Фото растения",
            "Фото сделано и обработано",
            event_type ="photo",
            level ="info",
            extra ={"server_sent":server_sent },
            )
            self .event_bus .post ("analysis_result",{"result":result ,"is_auto":is_auto })
            self .event_bus .post ("notifications_update",message ="Фото растения обработано")
        except Exception as exc :
            self .notifications .add ("Ошибка камеры",str (exc ),event_type ="camera",level ="error")
            self .event_bus .post ("analysis_error",level ="error",message =str (exc ))
        finally :
            self .analysis_running =False
    def _camera_test_worker (self )->None :
        try :
            if not self .plant_analyzer .initialize_camera ():
                raise RuntimeError ("Камера недоступна")
            image =self .plant_analyzer .capture_image ()
            if image is None :
                raise RuntimeError ("Не удалось получить кадр")
            cv2 ,_ =self .plant_analyzer ._cv ()
            output =Path (self .settings .get ("analysis_output_dir"))/"camera_test.jpg"
            output .parent .mkdir (parents =True ,exist_ok =True )
            cv2 .imwrite (str (output ),image )
            self .event_bus .post ("camera_test",output ,message ="Камера работает")
        except Exception as exc :
            self .event_bus .post ("analysis_error",level ="error",message =str (exc ))
        finally :
            self .plant_analyzer .release_camera ()
    def _device_command_poll_task (self )->None :
        self ._sync_api_token ()
        commands =self .api .poll_device_commands ()
        for command in commands :
            self ._execute_device_command (command )
    def _execute_device_command (self ,command :dict )->None :
        command_id =int (command .get ("command_id",0 ))
        command_type =str (command .get ("command_type",""))
        first ,journal_record =self .command_journal .begin (str (self .api .device_id ),command_id )
        if not first :
            previous =journal_record .get ("result")
            if not isinstance (previous ,dict ):
                previous ={"status":"error","message":"Предыдущее выполнение прервано; действие автоматически не повторяется","payload":{},"started_at":"","finished_at":""}
            self ._submit_device_command_result (command_id ,previous ["status"],previous ["message"],previous ["payload"],previous ["started_at"],previous ["finished_at"])
            return
        payload =command .get ("payload",{})if isinstance (command .get ("payload"),dict )else {}
        started_at =datetime .now ().strftime ("%Y-%m-%d %H:%M:%S")
        status ="done"
        message ="Команда выполнена"
        result_payload :dict [str ,object ]={}
        try :
            message ,result_payload =self ._run_device_command (command_type ,payload ,started_at )
        except DeviceCommandExecutionError as exc :
            status ="error"
            message =str (exc )
            result_payload =exc .payload
        except Exception as exc :
            status ="error"
            message =str (exc )
        finished_at =datetime .now ().strftime ("%Y-%m-%d %H:%M:%S")
        self .command_journal .complete (journal_record ,{"status":status ,"message":message ,"payload":result_payload ,"started_at":started_at ,"finished_at":finished_at })
        self ._submit_device_command_result (command_id ,status ,message ,result_payload ,started_at ,finished_at )
        self .notifications .add ("Удалённая команда",message ,event_type ="device_command",level ="info"if status =="done"else "error")
        self .event_bus .post ("notifications_update",message =message )
    def _run_device_command (self ,command_type :str ,payload :dict ,started_at :str )->tuple [str ,dict [str ,object ]]:
        handlers ={
        "ping":self ._command_ping ,
        "sync_device_config":self ._command_sync_device_config ,
        "request_device_config":self ._command_sync_device_config ,
        "apply_device_config":self ._command_apply_device_config ,
        "take_photo":self ._command_take_photo ,
        "analyze_plant":self ._command_analyze_plant ,
        "refresh_thresholds":self ._command_refresh_thresholds ,
        "refresh_schedule":self ._command_refresh_schedule ,
        "upload_thresholds_to_arduino":self ._command_upload_thresholds ,
        "reconnect_arduino":self ._command_reconnect_arduino ,
        "relay_on":self ._command_relay_on ,
        "relay_off":self ._command_relay_off ,
        "relay_pulse":self ._command_relay_pulse ,
        "pump_fault_reset":self ._command_pump_fault_reset ,
        "device_test":self ._command_device_test ,
        }
        handler =handlers .get (command_type )
        if handler is None :
            raise RuntimeError (f"Неизвестная команда: {command_type }")
        return handler (payload ,started_at )
    def _command_ping (self ,_payload :dict ,started_at :str )->tuple [str ,dict [str ,object ]]:
        result ={"desktop_time":started_at ,"arduino_connected":self .arduino .is_connected ,"device_name":self ._device_name ()}
        return "Desktop-приложение на связи",result
    def _command_sync_device_config (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        sensors =self .device_config .sensors (include_disabled =True )+self .device_config .extra_sensors ()
        relays =self .device_config .relays (include_disabled =True )
        scenarios =self .settings .get ("automation_scenarios",[])
        if not self .api .save_device_config (sensors ,relays ,scenarios ):
            raise RuntimeError ("Не удалось отправить конфигурацию на сайт")
        result :dict [str ,object ]={"sensors":len (sensors ),"relays":len (relays ),"scenarios":len (scenarios )if isinstance (scenarios ,list )else 0}
        return "Конфигурация устройства отправлена на сайт",result
    def _command_apply_device_config (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        data =self .api .get_device_config ()
        if not data :
            raise RuntimeError ("Конфигурация с сайта не получена")
        sensors =data .get ("device_sensors",[])
        relays =data .get ("device_relays",[])
        scenarios =data .get ("scenarios",[])
        if not isinstance (sensors ,list )or not isinstance (relays ,list )or not isinstance (scenarios ,list ):
            raise RuntimeError ("Сайт вернул неверный формат конфигурации")
        self .settings .update ({"device_config_mode":"custom","device_sensors":sensors ,"device_relays":relays ,"automation_scenarios":scenarios },save =True )
        self ._after_device_config_changed ("Конфигурация применена с сайта")
        return "Конфигурация устройства применена",{"sensors":len (sensors ),"relays":len (relays ),"scenarios":len (scenarios )}
    def _command_take_photo (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        photo_path =self .plant_analyzer .capture_photo_file ("command_photo")
        ok ,upload =self .api .upload_photo_result (photo_path ,"Фото по удалённой команде")
        result ={"local_path":str (photo_path ),**upload }
        if not ok :
            self .photo_queue .add_photo (device_id =str (self .settings .get ("device_id","")),file_path =photo_path ,send_to_server =True )
            raise DeviceCommandExecutionError ("Фото сделано локально, но сайт временно недоступен",result )
        return "Фото сделано и загружено",result
    def _command_analyze_plant (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        result =self .plant_analyzer .run_analysis (None )
        report_text =result .report_path .read_text (encoding ="utf-8")
        ok ,upload =self .api .upload_plant_images_result (result .original_path ,result .detection_path ,report_text )
        result_payload ={"local_path":str (result .original_path ),"report_path":str (result .report_path ),**upload }
        if not ok :
            self .photo_queue .add_photo (device_id =str (self .settings .get ("device_id","")),file_path =result .original_path ,analysis_path =result .detection_path ,report_path =result .report_path ,send_to_server =True )
            raise DeviceCommandExecutionError ("Анализ выполнен локально, но сайт временно недоступен",result_payload )
        self .event_bus .post ("analysis_result",{"result":result ,"is_auto":False })
        return "Анализ растения выполнен и загружен",result_payload
    def _command_refresh_thresholds (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        thresholds =self .api .get_thresholds ()
        if not thresholds :
            raise RuntimeError ("Пороги не получены")
        self .latest_thresholds =thresholds
        self .event_bus .post ("thresholds_update",{"thresholds":thresholds ,"schedule":self .latest_schedule })
        return "Пороги обновлены",{}
    def _command_refresh_schedule (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        schedule =self .api .get_schedule ()
        if not schedule :
            raise RuntimeError ("Расписание не получено")
        self .latest_schedule =schedule
        self .event_bus .post ("thresholds_update",{"thresholds":self .latest_thresholds ,"schedule":schedule })
        return "Расписание обновлено",{}
    def _command_upload_thresholds (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        self ._upload_thresholds_worker ()
        return "Загрузка порогов и расписания в Arduino запущена",{}
    def _command_reconnect_arduino (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        self .arduino .disconnect (wait =False )
        if not self .arduino .connect ():
            raise RuntimeError ("Arduino не подключилась")
        return "Arduino переподключена",{}
    def _command_relay_on (self ,payload :dict ,started_at :str )->tuple [str ,dict [str ,object ]]:
        return self ._command_set_relay (payload ,started_at ,True )
    def _command_relay_off (self ,payload :dict ,started_at :str )->tuple [str ,dict [str ,object ]]:
        return self ._command_set_relay (payload ,started_at ,False )
    def _command_set_relay (self ,payload :dict ,_started_at :str ,desired :bool )->tuple [str ,dict [str ,object ]]:
        relay =int (payload .get ("relay",0 ))
        if not self ._set_relay_state (relay ,desired ):
            raise RuntimeError ("Не удалось отправить команду реле")
        return f"Реле {relay } {'включено' if desired else 'выключено'}",{}
    def _command_relay_pulse (self ,payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        relay =int (payload .get ("relay",0 ))
        seconds =float (payload .get ("seconds",3 ))
        if not self ._pulse_relay (relay ,seconds ):
            raise RuntimeError ("Не удалось выполнить импульс реле")
        return f"Реле {relay } включалось на {seconds :.1f} сек.",{}
    def _command_pump_fault_reset (self ,_payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        if not self .arduino .send_command ("PUMP_FAULT_RESET"):
            raise RuntimeError ("Arduino не подтвердила сброс аварии насоса")
        return "Блокировка насоса сброшена; насос остаётся выключенным",{}
    def _command_device_test (self ,payload :dict ,_started_at :str )->tuple [str ,dict [str ,object ]]:
        return self ._run_device_test (payload ),{}
    def _submit_device_command_result (self ,command_id :int ,status :str ,message :str ,payload :dict ,started_at :str ,finished_at :str )->None :
        ok =self .api .send_device_command_result (command_id ,status ,message ,payload ,started_at ,finished_at )
        if not ok :
            self .command_result_queue .add_result (
            device_id =str (self .api .device_id ),
            command_id =command_id ,
            status =status ,
            message =message ,
            result_payload =payload ,
            started_at =started_at ,
            finished_at =finished_at ,
            )
    def _relay_spec (self ,relay :int )->tuple [str ,str ,bool ]:
        mapping ={
        1:("FAN","fan_state",self .latest_snapshot .fan_state ),
        2:("PUMP","pump_state",self .latest_snapshot .pump_state ),
        3:("CURTAINS","curtains_state",self .latest_snapshot .curtains_state ),
        4:("LAMP","lamp_state",self .latest_snapshot .lamp_state ),
        }
        if relay not in mapping :
            raise RuntimeError ("Реле должно быть 1-4")
        return mapping [relay ]
    def _set_relay_state (self ,relay :int ,desired :bool )->bool :
        device_type ,_field ,_current =self ._relay_spec (relay )
        return self .arduino .send_command (device_type ,desired )
    def _pulse_relay (self ,relay :int ,seconds :float )->bool :
        _device_type ,_field ,was_on =self ._relay_spec (relay )
        seconds =max (0.2 ,min (30.0 ,seconds ))
        if not self ._set_relay_state (relay ,True ):
            return False
        off_ok =True
        try :
            time_module .sleep (seconds )
        finally :
            if not was_on or relay ==2 :
                off_ok =self ._set_relay_state (relay ,False )
        return off_ok
    def _run_device_test (self ,payload :dict )->str :
        device =str (payload .get ("device","all"))
        seconds =float (payload .get ("seconds",self .settings .get ("device_test_pulse_seconds",3 )))
        tests ={
        "fan":[1 ,seconds ],
        "pump":[2 ,float (self .settings .get ("pump_test_pulse_seconds",1 ))],
        "curtains":[3 ,seconds ],
        "lamp":[4 ,seconds ],
        }
        if device =="all":
            for relay ,duration in tests .values ():
                if not self ._pulse_relay (relay ,float (duration )):
                    raise RuntimeError (f"Тест реле {relay } не выполнен")
            return "Тест всех устройств выполнен"
        if device not in tests :
            raise RuntimeError ("Неизвестное устройство для теста")
        relay ,duration =tests [device ]
        if not self ._pulse_relay (int (relay ),float (duration )):
            raise RuntimeError ("Тест устройства не выполнен")
        return f"Тест {device } выполнен"
    def _device_test_worker (self ,device :str )->None :
        try :
            message =self ._run_device_test ({"device":device })
            self .notifications .add ("Тест устройства",message ,event_type ="device_test",level ="info")
            self .event_bus .post ("server_send_status",True ,message =message )
        except Exception as exc :
            message =f"Тест устройства не выполнен: {exc }"
            self .notifications .add ("Тест устройства",message ,event_type ="device_test",level ="error")
            self .event_bus .post ("server_send_status",False ,level ="error",message =message )
    def _sync_api_token (self )->None :
        token =self .settings .get ("token","")
        self .api .set_token (token )
        self .api .set_device (
        str (self .settings .get ("device_id","")),
        self ._device_name (),
        str (self .settings .get ("device_token","")),
        )
    def _device_name (self )->str :
        configured =str (self .settings .get ("device_name","")).strip ()
        if configured :
            return configured
        hostname =socket .gethostname ()or "RaspberryPi"
        return f"FitoDomik-{hostname }"
    def _schedule_device_pair_poll (self ,pair_code :str ,delay_ms :int =2500 )->None :
        if self ._closing or not pair_code or pair_code !=self ._device_pair_code :
            return
        if self ._device_pair_poll_after_id :
            try :
                self .root .after_cancel (self ._device_pair_poll_after_id )
            except tk .TclError :
                pass
        self ._device_pair_poll_after_id =self .root .after (
        delay_ms ,
        lambda code =pair_code ,secret =self ._device_pair_secret :self .task_manager .run_once (
        "device_pair_status",
        lambda :self ._device_pair_status_worker (code ,secret ),
        ),
        )
    def _adaptive_history_interval (self )->float :
        if self .active_tab in {"monitoring","charts"}:
            return float (self .settings .get ("active_polling_interval",10 ))
        return float (self .settings .get ("background_polling_interval",600 ))
    def _network_interval (self ,base_interval )->float :
        try :
            base =max (1.0 ,float (base_interval ))
        except (TypeError ,ValueError ):
            base =60.0
        if time_module .time ()<self ._network_backoff_until :
            return max (base ,self ._network_backoff_seconds )
        return base
    def _note_network_result (self ,ok :bool ,reason :str ="")->None :
        if ok :
            self ._network_backoff_until =0.0
            self ._network_backoff_seconds =0.0
            return
        next_delay =self ._network_backoff_seconds *2 if self ._network_backoff_seconds else 60.0
        self ._network_backoff_seconds =min (600.0 ,max (60.0 ,next_delay ))
        self ._network_backoff_until =time_module .time ()+self ._network_backoff_seconds
        if reason :
            self .logger .info ("Сетевой backoff %.0f сек: %s",self ._network_backoff_seconds ,reason )
    def _calibrated_snapshot (self ,snapshot :SensorSnapshot )->SensorSnapshot :
        cfg =self .settings .get ("calibration",{})
        if not isinstance (cfg ,dict ):
            cfg ={}
        def shifted (value ,key ):
            return None if value is None else float (value )+float (cfg .get (key ,0 )or 0 )
        soil =snapshot .soil_moisture
        if soil is not None :
            soil_min =float (cfg .get ("soil_min",0 )or 0 )
            soil_max =float (cfg .get ("soil_max",100 )or 100 )
            if soil_max !=soil_min and (soil_min !=0 or soil_max !=100 ):
                soil =((float (soil )-soil_min )/(soil_max -soil_min ))*100
            if cfg .get ("soil_invert",False ):
                soil =100 -float (soil )
            soil =max (0 ,min (100 ,float (soil )+float (cfg .get ("soil_offset",0 )or 0 )))
        return SensorSnapshot (
        timestamp =snapshot .timestamp ,
        temperature =shifted (snapshot .temperature ,"temperature_offset"),
        humidity =shifted (snapshot .humidity ,"humidity_offset"),
        soil_moisture =soil ,
        light_level =snapshot .light_level ,
        co2 =shifted (snapshot .co2 ,"co2_offset"),
        pressure =shifted (snapshot .pressure ,"pressure_offset"),
        lamp_state =snapshot .lamp_state ,
        curtains_state =snapshot .curtains_state ,
        pump_state =snapshot .pump_state ,
        fan_state =snapshot .fan_state ,
        extra_relay_states =dict (snapshot .extra_relay_states ),
        pump_fault =snapshot .pump_fault ,
        protocol_version =snapshot .protocol_version ,
        source =snapshot .source ,
        raw =snapshot .raw ,
        )
    def _next_photo_text (self ,now :datetime ,hours :list [int ])->str :
        candidates =[]
        for day_offset in (0 ,1 ):
            base =now .date ()+timedelta (days =day_offset )
            for hour in hours :
                candidates .append (datetime .combine (base ,time (hour =hour )))
        future =min (item for item in candidates if item >now )
        delta =future -now
        total_minutes =max (0 ,int (delta .total_seconds ()//60 ))
        return f"До следующего фото: {total_minutes //60 } ч. {total_minutes %60 } мин. ({future .hour :02d}:00)"
    def _drain_events (self )->None :
        if self ._closing :
            return
        deadline =time_module .perf_counter ()+0.008
        delay =20
        for event in self .event_bus .drain ():
            try :
                self ._handle_event (event .name ,event .payload ,event .message ,event .level )
            except Exception :
                self .logger .exception ("Не удалось выполнить событие графического интерфейса: %s",event .name )
            if time_module .perf_counter ()>=deadline :
                delay =1
                break
        if not self ._closing :
            self ._event_after_id =self .root .after (delay ,self ._drain_events )
    def _handle_event (self ,name :str ,payload ,message :str ,level :str )->None :
        groups ={
        "startup_status":self ._handle_startup_event ,
        "startup_complete":self ._handle_startup_event ,
        "dependency_warning":self ._handle_startup_event ,
        "network_ready":self ._handle_startup_event ,
        "desktop_update_status":self ._handle_startup_event ,
        "sensor_update":self ._handle_sensor_event ,
        "arduino_status":self ._handle_sensor_event ,
        "arduino_command":self ._handle_sensor_event ,
        "arduino_fault":self ._handle_sensor_event ,
        "arduino_fault_reset":self ._handle_sensor_event ,
        "arduino_pump_event":self ._handle_sensor_event ,
        "weather_update":self ._handle_weather_event ,
        "weather_error":self ._handle_weather_event ,
        "api_status":self ._handle_weather_event ,
        "account_link":self ._handle_account_event ,
        "device_list":self ._handle_account_event ,
        "device_unlink":self ._handle_account_event ,
        "weather_tokens_check":self ._handle_account_event ,
        "device_pair_created":self ._handle_pair_event ,
        "device_pair_status":self ._handle_pair_event ,
        "device_pair_approved":self ._handle_pair_event ,
        "device_pair_error":self ._handle_pair_event ,
        "thresholds_update":self ._handle_sync_event ,
        "arduino_upload":self ._handle_sync_event ,
        "history_update":self ._handle_sync_event ,
        "charts_backend_ready":self ._handle_sync_event ,
        "charts_backend_error":self ._handle_sync_event ,
        "charts_data_loaded":self ._handle_sync_event ,
        "charts_data_error":self ._handle_sync_event ,
        "server_send_status":self ._handle_sync_event ,
        "device_config_sync":self ._handle_sync_event ,
        "wifi_scan":self ._handle_sync_event ,
        "wifi_connect":self ._handle_sync_event ,
        "wifi_disconnect":self ._handle_sync_event ,
        "wifi_forget":self ._handle_sync_event ,
        "wifi_internet":self ._handle_sync_event ,
        "analysis_result":self ._handle_media_event ,
        "analysis_error":self ._handle_media_event ,
        "next_photo":self ._handle_media_event ,
        "camera_test":self ._handle_media_event ,
        "camera_preview_ready":self ._handle_camera_preview ,
        "camera_preview_error":self ._handle_camera_preview ,
        "extra_sensors_update":self ._handle_extra_event ,
        "extra_sensor_status":self ._handle_extra_event ,
        "notifications_update":self ._handle_extra_event ,
        "theme_changed":self ._handle_extra_event ,
        "error":self ._handle_extra_event ,
        }
        handler =groups .get (name )
        if handler :
            handler (name ,payload ,message ,level )
    def _handle_startup_event (self ,name :str ,_payload ,message :str ,level :str )->None :
        if name =="startup_status":
            try :
                if hasattr (self ,"splash_status")and self .splash_status .winfo_exists ():
                    self .splash_status .configure (text =message )
            except tk .TclError :
                pass
            self ._append_splash_log (message ,level )
        elif name =="startup_complete":
            if not self ._closing :
                self ._build_main ()
        elif name =="dependency_warning":
            self ._set_status (f"Не найдены зависимости: {message }")
        elif name =="network_ready":
            self ._set_status (message )
        elif name =="desktop_update_status":
            if "settings"in self .tabs and hasattr (self .tabs ["settings"],"set_update_status"):
                self .tabs ["settings"].set_update_status (message )
            self .notifications .add ("Обновления",message ,event_type ="desktop_update",level =level )
            self ._set_status (message )
    def _handle_sensor_event (self ,name :str ,payload ,message :str ,level :str )->None :
        if name =="sensor_update":
            self ._handle_sensor_update_event (payload )
        elif name =="arduino_status":
            self ._handle_arduino_status_event (payload ,message )
        elif name =="arduino_command":
            self ._handle_arduino_command_event (payload ,message ,level )
        elif name =="arduino_fault":
            self .notifications .add ("Авария насоса",message ,event_type ="pump_fault",level ="error")
            self .event_bus .post ("notifications_update",message =message )
            self .logger .error ("Авария насоса: %s",payload )
            device_id =str (self .settings .get ("device_id",""))
            active =self .settings .get ("active_pump_faults",{})
            if device_id not in active :
                pending =next ((item for item in self .fault_queue .all ()if item .get ("device_id")==device_id and item .get ("fault_code")==str (payload )),None )
                record =pending or self .fault_queue .add_fault (str (payload ),device_id )
                active [device_id]=record ["id"]
                self .settings .set ("active_pump_faults",active ,save =True )
                self .task_manager .run_once ("pump_fault_report",lambda :self ._report_pump_fault_worker (record ))
        elif name =="arduino_fault_reset":
            active =self .settings .get ("active_pump_faults",{})
            active .pop (str (self .settings .get ("device_id","")),None )
            self .settings .set ("active_pump_faults",active ,save =True )
        elif name =="arduino_pump_event":
            self .logger .info ("Событие насоса: %s",message )
    def _handle_sensor_update_event (self ,payload )->None :
        snapshot =self ._calibrated_snapshot (payload )if isinstance (payload ,SensorSnapshot )else payload
        self .latest_snapshot =snapshot
        self ._update_sensor_views (snapshot )
        should_refresh =isinstance (snapshot ,SensorSnapshot )and self .device_config .snapshot_has_configured_values (snapshot )
        history_changed =should_refresh and self .sensor_history .add (snapshot )
        if history_changed and self .active_tab =="charts":
            self .event_bus .post ("history_update")
    def _handle_arduino_status_event (self ,payload ,message :str )->None :
        connected =bool (payload )
        text =message or ("подключена"if connected else "отключена")
        if hasattr (self ,"arduino_status_label"):
            self .arduino_status_label .configure (text =f"Arduino: {shorten_status_text (text )}")
        if "monitoring"in self .tabs :
            self .tabs ["monitoring"].set_arduino_status (connected ,message )
        if not connected and message :
            self ._set_status (message )
    def _handle_arduino_command_event (self ,payload ,message :str ,level :str )->None :
        self ._set_status (message )
        if not payload :
            self .notifications .add ("Arduino",message ,event_type ="arduino_command",level =level )
    def _handle_weather_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        if name =="weather_update":
            self .latest_weather =payload
            if "weather"in self .tabs :
                self .tabs ["weather"].set_weather (payload )
        elif name =="weather_error":
            if "weather"in self .tabs :
                self .tabs ["weather"].set_error ()
            self ._set_status (f"Погода временно недоступна: {message }")
        elif hasattr (self ,"api_status_label"):
            if not self .settings .get ("token","").strip ():
                text ="API: аккаунт не привязан"
            else :
                text =API_ACCOUNT_LINKED_TEXT if payload else "API: нет связи/аккаунта"
            self .api_status_label .configure (text =text )
    def _handle_account_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        settings_tab =self .tabs .get ("settings")
        if name =="account_link":
            self ._handle_account_link_event (settings_tab ,payload ,message )
        elif name =="device_list":
            self ._handle_device_list_event (settings_tab ,payload ,message )
        elif name =="device_unlink":
            self ._handle_device_unlink_event (settings_tab )
        else :
            self ._handle_weather_token_event (settings_tab ,message )
        self ._set_status (message )
    def _handle_account_link_event (self ,settings_tab ,payload ,message :str )->None :
        if settings_tab and hasattr (settings_tab ,"set_account_status"):
            settings_tab .set_account_status (message )
        if settings_tab and hasattr (settings_tab ,"refresh_account_info"):
            settings_tab .refresh_account_info ()
        if not payload :
            return
        if hasattr (self ,"api_status_label"):
            self .api_status_label .configure (text =API_ACCOUNT_LINKED_TEXT )
        self .check_api_token ()
        self .refresh_thresholds ()
        self .refresh_device_list ()
    def _handle_device_list_event (self ,settings_tab ,payload ,message :str )->None :
        devices =payload .get ("devices",[])if isinstance (payload ,dict )else []
        if settings_tab and hasattr (settings_tab ,"update_device_list"):
            settings_tab .update_device_list (devices ,message )
    def _handle_device_unlink_event (self ,settings_tab )->None :
        self .clear_remote_data ()
        if settings_tab and hasattr (settings_tab ,"set_account_status"):
            settings_tab .set_account_status ("Аккаунт не привязан")
        if settings_tab and hasattr (settings_tab ,"refresh_account_info"):
            settings_tab .refresh_account_info ()
    def _handle_weather_token_event (self ,settings_tab ,message :str )->None :
        if settings_tab and hasattr (settings_tab ,"set_weather_token_status"):
            settings_tab .set_weather_token_status (message )
    def _handle_pair_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        settings_tab =self .tabs .get ("settings")
        if name =="device_pair_created":
            self ._device_pair_code =str (payload .get ("pair_code",""))
            self ._device_pair_secret =str (payload .get ("pair_secret",""))
            if not self ._device_pair_code or not self ._device_pair_secret :
                self ._set_status ("Сайт не вернул полный код QR-привязки")
                return
            if settings_tab and hasattr (settings_tab ,"show_device_pair_qr"):
                settings_tab .show_device_pair_qr (payload )
            self ._schedule_device_pair_poll (self ._device_pair_code ,1500 )
        elif name =="device_pair_status":
            self ._handle_pair_status (settings_tab ,payload ,message )
            return
        elif name =="device_pair_approved":
            self ._complete_device_pair (settings_tab )
        elif settings_tab and hasattr (settings_tab ,"set_account_status"):
            settings_tab .set_account_status (message )
        self ._set_status (message )
    def _handle_pair_status (self ,settings_tab ,payload ,message :str )->None :
        status =str (payload .get ("status","pending"))if isinstance (payload ,dict )else "pending"
        pair_code =str (payload .get ("pair_code",self ._device_pair_code ))if isinstance (payload ,dict )else self ._device_pair_code
        if pair_code and pair_code !=self ._device_pair_code :
            return
        if settings_tab and hasattr (settings_tab ,"set_pair_status"):
            settings_tab .set_pair_status (message or status )
        if status in {"pending","error"}:
            self ._schedule_device_pair_poll (pair_code ,5000 if status =="error"else 2500 )
        else :
            self .cancel_device_pair_poll ()
        self ._set_status (message )
    def _complete_device_pair (self ,settings_tab )->None :
        self .cancel_device_pair_poll ()
        if settings_tab :
            if hasattr (settings_tab ,"close_pair_window"):
                settings_tab .close_pair_window ()
            if hasattr (settings_tab ,"set_account_status"):
                settings_tab .set_account_status (ACCOUNT_LINKED_TEXT )
            if hasattr (settings_tab ,"refresh_account_info"):
                settings_tab .refresh_account_info ()
        if hasattr (self ,"api_status_label"):
            self .api_status_label .configure (text =API_ACCOUNT_LINKED_TEXT )
        self .check_api_token ()
        self .refresh_thresholds ()
        self .refresh_device_list ()
    def _handle_sync_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        if name =="thresholds_update":
            self ._apply_thresholds_event (payload ,message )
        elif name =="arduino_upload":
            self ._handle_arduino_upload_event (message )
        elif name =="history_update":
            self ._handle_history_update_event ()
        elif name .startswith ("charts_"):
            self ._handle_chart_background_event (name ,payload ,message )
        elif name =="server_send_status":
            self ._handle_server_send_event (payload ,message )
        elif name .startswith ("wifi_"):
            self ._handle_wifi_event (name ,payload ,message )
        elif message :
            self ._set_status (message )
    def _handle_arduino_upload_event (self ,message :str )->None :
        if "thresholds"in self .tabs :
            self .tabs ["thresholds"].set_upload_status (message )
        self ._set_status (message )
    def _handle_history_update_event (self )->None :
        if "charts"in self .tabs :
            self .tabs ["charts"].update_charts ()
    def _handle_chart_background_event (self ,name :str ,payload ,message :str )->None :
        charts_tab =self .tabs .get ("charts")
        if charts_tab and hasattr (charts_tab ,"handle_background_event"):
            charts_tab .handle_background_event (name ,payload ,message )
    def _handle_server_send_event (self ,payload ,message :str )->None :
        status_message =message or ("Данные отправлены на сервер"if payload else "Сервер временно недоступен")
        self ._set_status (status_message )
    def _handle_wifi_event (self ,name :str ,payload ,message :str )->None :
        settings_tab =self .tabs .get ("settings")
        if settings_tab and hasattr (settings_tab ,"handle_wifi_event"):
            settings_tab .handle_wifi_event (name ,payload ,message )
        self ._set_status (message )
    def _apply_thresholds_event (self ,payload :dict ,message :str )->None :
        thresholds_tab =self .tabs .get ("thresholds")
        if payload .get ("skipped"):
            if thresholds_tab :
                thresholds_tab .set_upload_status (message or "API-аккаунт не привязан")
            return
        if payload .get ("thresholds"):
            self .latest_thresholds =payload .get ("thresholds")
        if payload .get ("schedule"):
            self .latest_schedule =payload .get ("schedule")
        if thresholds_tab :
            thresholds_tab .set_thresholds (payload .get ("thresholds"))
            thresholds_tab .set_schedule (payload .get ("schedule"))
    def _handle_media_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        analysis_tab =self .tabs .get ("analysis")
        if name =="analysis_result":
            result :AnalysisResult =payload ["result"]
            if analysis_tab :
                analysis_tab .set_result (result ,payload .get ("is_auto",False ))
            self ._set_status ("Анализ растения завершён")
        elif name =="analysis_error":
            if analysis_tab :
                analysis_tab .set_error (message )
            self ._set_status (message )
        elif name =="next_photo":
            if analysis_tab :
                analysis_tab .set_next_photo_text (str (payload ))
        else :
            settings_tab =self .tabs .get ("settings")
            if settings_tab and hasattr (settings_tab ,"set_camera_test_result"):
                settings_tab .set_camera_test_result (payload ,message )
            self ._show_camera_preview (payload )
            self ._set_status (message )
    def _handle_extra_event (self ,name :str ,payload ,message :str ,_level :str )->None :
        if name =="extra_sensors_update":
            self ._apply_extra_sensor_values (payload )
        elif name =="extra_sensor_status":
            settings_tab =self .tabs .get ("settings")
            if settings_tab and hasattr (settings_tab ,"set_extra_sensor_status"):
                settings_tab .set_extra_sensor_status (message )
            self ._set_status (message )
        elif name =="notifications_update":
            notifications_tab =self .tabs .get ("notifications")
            if notifications_tab and hasattr (notifications_tab ,"refresh"):
                notifications_tab .refresh ()
            if message :
                self ._set_status (message )
        elif name =="theme_changed":
            self .apply_settings_changes ()
        elif name =="error":
            self ._set_status (message )
    def _apply_extra_sensor_values (self ,payload )->None :
        if not isinstance (payload ,dict ):
            return
        values =self .extra_config .values_from_payload (payload )
        self .extra_history .add_many (values )
        dynamic_values =[record for record in (self ._dynamic_value_from_extra (value )for value in values )if record ]
        if dynamic_values :
            self .dynamic_sensor_queue .add_values (dynamic_values ,str (self .settings .get ("device_id","")))
        extra_tab =self .tabs .get ("extra_devices")
        if self .active_tab =="extra_devices"and extra_tab and hasattr (extra_tab ,"update_values"):
            extra_tab .update_values (values )
        monitoring_tab =self .tabs .get ("monitoring")
        if self .active_tab =="monitoring"and monitoring_tab and hasattr (monitoring_tab ,"update_extra_values"):
            monitoring_tab .update_extra_values (values )
        charts_tab =self .tabs .get ("charts")
        if self .active_tab =="charts"and charts_tab and hasattr (charts_tab ,"update_charts"):
            charts_tab .update_charts ()
    def _update_sensor_views (self ,snapshot :SensorSnapshot )->None :
        if self .active_tab =="monitoring"and "monitoring"in self .tabs :
            self .tabs ["monitoring"].update_snapshot (snapshot )
        if self .active_tab =="control"and "control"in self .tabs :
            self .tabs ["control"].update_snapshot (snapshot )
    def _show_camera_preview (self ,path :Path )->None :
        window =tk .Toplevel (self .root )
        window .title ("Тест камеры")
        window .configure (bg =self .palette .bg )
        label =ttk .Label (window ,text ="Загрузка фото...")
        label .pack (padx =12 ,pady =12 )
        ttk .Button (window ,text ="Закрыть",command =window .destroy ).pack (pady =(0 ,12 ))
        if not hasattr (self ,"_camera_previews"):
            self ._camera_previews :dict [int ,Any ]={}
            self ._camera_preview_sequence =0
        self ._camera_preview_sequence +=1
        token =self ._camera_preview_sequence
        self ._camera_previews [token ]=label
        window .bind ("<Destroy>",lambda event :self ._camera_previews .pop (token ,None )if event .widget is window else None ,add ="+")
        def prepare ()->None :
            try :
                image =prepare_resized_image (path ,(520 ,360 ))
                self .event_bus .post ("camera_preview_ready",(token ,image ))
            except Exception :
                self .logger .exception ("Не удалось подготовить предпросмотр фото")
                self .event_bus .post ("camera_preview_error",(token ,None ))
        self .task_manager .run_once (f"camera_preview:{token}",prepare )
    def _handle_camera_preview (self ,name :str ,payload ,_message :str ,_level :str )->None :
        token ,image =payload
        label =self ._camera_previews .get (token )
        if label is None or self ._closing :
            return
        if name =="camera_preview_error":
            label .configure (text ="Не удалось загрузить фото")
            return
        from PIL import ImageTk
        photo =ImageTk .PhotoImage (image )
        label .configure (image =photo ,text ="")
        setattr (label ,"_image_ref",photo )
    def _set_status (self ,text :str )->None :
        if hasattr (self ,"status_label"):
            self .status_label .configure (text =text )
    def _append_splash_log (self ,message :str ,level :str ="info")->None :
        try :
            if not hasattr (self ,"splash_log")or not self .splash_log .winfo_exists ():
                return
            timestamp =datetime .now ().strftime ("%H:%M:%S")
            prefix ="!"if level in {"warning","error"}else "-"
            self .splash_log .configure (state ="normal")
            self .splash_log .insert ("end",f"[{timestamp }] {prefix } {message }\n")
            self .splash_log .see ("end")
            self .splash_log .configure (state ="disabled")
        except tk .TclError :
            return
