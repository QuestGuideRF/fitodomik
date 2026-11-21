from __future__ import annotations
from datetime import datetime, timedelta
from pathlib import Path
from tkinter import ttk
import os
import tkinter as tk
import webbrowser
from typing import Any, Callable, Literal, cast
from fitodomik_app.core.version import APP_VERSION
from fitodomik_app.ui.style_names import (
    ACCENT_BUTTON_STYLE,
    APP_FRAME_STYLE,
    BACK_TEXT,
    MUTED_LABEL_STYLE,
    SURFACE_FRAME_STYLE,
    SURFACE_LABEL_STYLE,
)
from fitodomik_app.ui.tabs.extra_sensors_settings import ExtraSensorsSettingsView
from fitodomik_app.ui.tabs.first_run_wizard import FirstRunWizardFrame
from fitodomik_app.ui.widgets import ScrollableFrame, load_resized_photo
from fitodomik_app.services.wifi_manager import WifiNetwork, WifiOperationResult, WifiScanResult, WifiStatus
NOT_SET_TEXT = "не задан"
WEATHER_CITIES = [
    "Moscow",
    "Saint Petersburg",
    "Novosibirsk",
    "Yekaterinburg",
    "Kazan",
    "Nizhny Novgorod",
    "Chelyabinsk",
    "Samara",
    "Rostov-on-Don",
    "Krasnodar",
]
INTEGER_SETTING_KEYS = {
    "serial_baudrate",
    "camera_index",
    "photo_count",
    "photo_time",
    "photo_time2",
    "photo_check_interval",
    "photo_interval_minutes",
    "device_test_pulse_seconds",
    "pump_test_pulse_seconds",
    "smart_photo_min_interval_minutes",
    "smart_photo_if_no_analysis_hours",
}
FLOAT_SETTING_KEYS = {"smart_photo_temperature_jump_delta", "smart_photo_soil_drop_delta", "serial_timeout"}
TILE_SPECS = [
    ("account", "Привязка аккаунта", "Аккаунт, QR, устройства", "settings"),
    ("weather", "Погода", "Город, токены, fallback", "weather"),
    ("config", "Конфигурация", "Схема, датчики, реле", "plant"),
    ("device", "Устройство", "Тест Arduino и реле", "control"),
    ("arduino", "Arduino / порт", "Serial-порт и baudrate", "settings"),
    ("photo", "Фото", "Камера и автофото", "camera"),
    ("calibration", "Калибровка", "Смещения датчиков", "temp"),
    ("info", "Информация", "Версия, сайты, выход", "monitor"),
]
def _mask_secret(value: object, visible: int = 4) -> str:
    text = str(value or "").strip()
    if not text:
        return NOT_SET_TEXT
    if len(text) <= visible * 2:
        return "*" * len(text)
    return f"{text[:visible]}...{text[-visible:]}"
class SettingsTab(ttk.Frame):
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.vars: dict[str, tk.Variable] = {}
        self.calibration_vars: dict[str, tk.Variable] = {}
        self.section_cards: list[tk.Widget] = []
        self.wrappable_labels: list[ttk.Label] = []
        self.scaled_inputs: list[tuple[tk.Widget, int, int]] = []
        self.current_section = "home"
        self.account_card: tk.Widget | None = None
        self.account_status_label: ttk.Label | None = None
        self.device_list_frame: ttk.Frame | None = None
        self.pair_frame: ttk.Frame | None = None
        self.pair_photo: object | None = None
        self.pair_after_id: str | None = None
        self.pair_expires_at: datetime | None = None
        self.pair_timer_label: ttk.Label | None = None
        self.pair_status_label: ttk.Label | None = None
        self.weather_token_status_label: ttk.Label | None = None
        self.extra_status_label: ttk.Label | None = None
        self.update_status_label: ttk.Label | None = None
        self.status_label: ttk.Label | None = None
        self.port_combo: ttk.Combobox | None = None
        self.test_status_label: ttk.Label | None = None
        self.photo_status_label: ttk.Label | None = None
        self.photo_preview_label: ttk.Label | None = None
        self.photo_preview: object | None = None
        self.wifi_status_label: ttk.Label | None = None
        self.wifi_networks_frame: ttk.Frame | None = None
        self.wifi_selected_ssid = ""
        self.wifi_password_var: tk.StringVar | None = None
        self.wifi_show_password_var: tk.BooleanVar | None = None
        self.wifi_password_entry: ttk.Entry | None = None
        self.calibration_value_labels: dict[str, ttk.Label] = {}
        self.first_run_view: FirstRunWizardFrame | None = None
        self.extra_settings_view: ExtraSensorsSettingsView | None = None
        self._build()
    def _build(self) -> None:
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(14), pady=(self.app.pad(14), self.app.pad(6)))
        self.back_button = ttk.Button(self.top, text=BACK_TEXT, command=self.rebuild_sections)
        self.title_label = ttk.Label(self.top, text="Настройки", style="Title.TLabel")
        self.title_label.pack(side="left")
        self.save_button = ttk.Button(self.top, text="Сохранить", style=ACCENT_BUTTON_STYLE, command=self.save)
        self.save_button.pack(side="right")
        self.scroll = ScrollableFrame(self, self.app.palette)
        self.scroll.pack(fill="both", expand=True, padx=self.app.pad(14), pady=(self.app.pad(6), self.app.pad(14)))
        self._show_home()
    def _show_home(self) -> None:
        self._prepare_content("home", "Настройки", show_back=False, show_save=False)
        grid = ttk.Frame(self.scroll.inner, style=APP_FRAME_STYLE)
        grid.pack(fill="both", expand=True)
        for column in range(2):
            grid.columnconfigure(column, weight=1, uniform="settings_tiles")
        for index, spec in enumerate(TILE_SPECS):
            self._settings_tile(grid, index, *spec)
    def _settings_tile(self, parent, index: int, section: str, title: str, description: str, icon_name: str) -> None:
        row, column = divmod(index, 2)
        tile = ttk.Frame(parent, style="Tile.TFrame", padding=self.app.pad(12))
        tile.grid(
            row=row,
            column=column,
            sticky="nsew",
            padx=self.app.pad(5),
            pady=self.app.pad(5),
            ipady=self.app.pad(6),
        )
        parent.rowconfigure(row, weight=1)
        tile.columnconfigure(1, weight=1)
        icon = self.app.icons.get(icon_name, self.app.icon_size(42, maximum=54))
        icon_label = ttk.Label(tile, image=icon, style="Tile.TLabel")
        icon_label.grid(row=0, column=0, rowspan=2, sticky="n", padx=(0, self.app.pad(10)))
        title_label = ttk.Label(tile, text=title, style="TileTitle.TLabel", wraplength=self.app.sx(220, minimum=160))
        title_label.grid(row=0, column=1, sticky="w")
        desc_label = ttk.Label(tile, text=description, style="TileMuted.TLabel", wraplength=self.app.sx(230, minimum=160))
        desc_label.grid(row=1, column=1, sticky="nw", pady=(self.app.pad(3), 0))
        for widget in (tile, icon_label, title_label, desc_label):
            widget.bind("<Button-1>", lambda _event, target=section: self._open_section(target))
            widget.bind("<Enter>", lambda _event, frame=tile: frame.configure(style="TileHover.TFrame"))
            widget.bind("<Leave>", lambda _event, frame=tile: frame.configure(style="Tile.TFrame"))
    def _open_section(self, section: str) -> None:
        builders: dict[str, Callable[[], None]] = {
            "account": self._build_account_section,
            "weather": self._build_weather_section,
            "config": self._build_config_section,
            "device": self._build_device_section,
            "arduino": self._build_arduino_section,
            "photo": self._build_photo_section,
            "calibration": self._build_calibration_section,
            "info": self._build_info_section,
        }
        builder = builders.get(section)
        if builder:
            builder()
    def _prepare_content(self, section: str, title: str, show_back: bool = True, show_save: bool = True) -> None:
        self._deactivate_first_run_view()
        self.close_pair_window(cancel_poll=False)
        self.current_section = section
        self._drop_content_refs()
        for child in self.scroll.inner.winfo_children():
            child.destroy()
        self.vars.clear()
        self.calibration_vars.clear()
        self.section_cards = []
        self.wrappable_labels = []
        self.scaled_inputs = []
        self.title_label.configure(text=title)
        self._set_top_button(self.back_button, show_back, side="left")
        self._set_top_button(self.save_button, show_save, side="right")
        self.scroll.canvas.yview_moveto(0)
    def _set_top_button(self, button: ttk.Button, visible: bool, side: Literal["left", "right"]) -> None:
        try:
            if visible and not button.winfo_ismapped():
                button.pack(side=side, padx=(0, self.app.pad(8)) if side == "left" else 0)
            elif not visible and button.winfo_ismapped():
                button.pack_forget()
        except tk.TclError:
            pass
    def _drop_content_refs(self) -> None:
        self.account_card = None
        self.account_status_label = None
        self.device_list_frame = None
        self.weather_token_status_label = None
        self.extra_status_label = None
        self.update_status_label = None
        self.status_label = None
        self.port_combo = None
        self.test_status_label = None
        self.photo_status_label = None
        self.photo_preview_label = None
        self.photo_preview = None
        self.wifi_status_label = None
        self.wifi_networks_frame = None
        self.wifi_selected_ssid = ""
        self.wifi_password_var = None
        self.wifi_show_password_var = None
        self.wifi_password_entry = None
        self.calibration_value_labels = {}
        self.extra_settings_view = None
        self.first_run_view = None
    def rebuild_sections(self) -> None:
        self._show_home()
    def open_first_run_wizard(self) -> None:
        self._prepare_content("wizard", "Первый запуск", show_back=True, show_save=False)
        self.first_run_view = FirstRunWizardFrame(self.scroll.inner, self.app, self.rebuild_sections)
        self.first_run_view.pack(fill="both", expand=True)
    def open_extra_sensors_settings(self) -> None:
        self._prepare_content("extra", "Сторонние датчики", show_back=True, show_save=False)
        self.extra_settings_view = ExtraSensorsSettingsView(self.scroll.inner, self.app, self._build_config_section)
        self.extra_settings_view.pack(fill="both", expand=True)
    def _build_account_section(self) -> None:
        self._prepare_content("account", "Привязка аккаунта")
        card = self._section_card("Аккаунт и устройство")
        self.account_card = card
        self._entry(card, "Логин", "account_username", width=24)
        self._password_entry(card, "Пароль", "account_password", width=24)
        self._entry(card, "Имя устройства", "device_name", width=28)
        self._button_grid(
            card,
            [
                ("Привязать", self.link_account, True),
                ("Проверить", self.app.check_api_token, False),
                ("QR-код", self.start_qr_pair, False),
                ("Отвязать", self.app.unlink_device, False),
                ("Список устройств", self.app.refresh_device_list, False),
            ],
            columns=2,
        )
        token_card = self._section_card("Текущие данные")
        self.token_value = tk.StringVar(value=_mask_secret(self.app.settings.get("token", "")))
        self.device_id_value = tk.StringVar(value=str(self.app.settings.get("device_id", "")) or NOT_SET_TEXT)
        self.device_token_value = tk.StringVar(value=_mask_secret(self.app.settings.get("device_token", "")))
        self._readonly_row(token_card, "Токен", self.token_value)
        self._readonly_row(token_card, "ID устройства", self.device_id_value)
        self._readonly_row(token_card, "Device token", self.device_token_value)
        self.account_status_label = ttk.Label(token_card, text="", style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.account_status_label.pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
        self.wrappable_labels.append(self.account_status_label)
        self.device_list_frame = ttk.Frame(self.scroll.inner, style=SURFACE_FRAME_STYLE, padding=self.app.pad(12))
        self.device_list_frame.pack(fill="x", pady=self.app.pad(6))
        self.update_device_list([])
        self.refresh_account_info()
    def _build_weather_section(self) -> None:
        self._prepare_content("weather", "Погода")
        card = self._section_card("Город и проверка")
        current_city = str(self.app.settings.get("weather_city", "Moscow"))
        city_values = WEATHER_CITIES if current_city in WEATHER_CITIES else [current_city, *WEATHER_CITIES]
        self.vars["weather_city"] = tk.StringVar(value=current_city)
        row = self._form_row(card, "Город")
        combo = ttk.Combobox(
            row,
            textvariable=self.vars["weather_city"],
            values=city_values,
            height=10,
            font=self.app.font_tuple(14, maximum=18),
        )
        combo.pack(side="left", fill="x", expand=True, ipady=self.app.pad(8))
        self._register_scaled_input(combo, 28, 52)
        self._button_grid(
            card,
            [
                ("Сохранить город", self.save, True),
                ("Обновить погоду", self._save_city_and_refresh_weather, False),
                ("Проверить WeatherAPI", self.check_weather_tokens, False),
                ("Проверить fallback", self._check_weather_fallback, False),
            ],
            columns=2,
        )
        self.weather_token_status_label = ttk.Label(
            card,
            text="Проверка не запускалась",
            style=MUTED_LABEL_STYLE,
            wraplength=self._wraplength(),
        )
        self.weather_token_status_label.pack(anchor="w", fill="x", pady=(self.app.pad(8), 0))
        self.wrappable_labels.append(self.weather_token_status_label)
    def _build_config_section(self) -> None:
        self._prepare_content("config", "Конфигурация устройства")
        card = self._section_card("Схема устройства")
        mode = self.app.device_config.mode()
        sensors_count = len(self.app.device_config.sensors())
        relays_count = len(self.app.device_config.relays())
        ttk.Label(
            card,
            text=f"Текущий режим: {self._device_mode_text(mode)}. Датчиков: {sensors_count}, реле: {relays_count}.",
            style=MUTED_LABEL_STYLE,
            wraplength=self._wraplength(),
        ).pack(anchor="w", fill="x", pady=(0, self.app.pad(8)))
        self._button_grid(
            card,
            [
                ("Стандартная схема", self.app.use_standard_device_config, True),
                ("Пустое устройство", self.app.use_empty_device_config, False),
                ("Синхронизировать с сайтом", self.app.sync_device_settings_now, False),
            ],
            columns=2,
        )
        extra = self._section_card("Сторонние датчики")
        self.vars["show_extra_devices_tab"] = tk.BooleanVar(
            value=bool(self.app.settings.get("show_extra_devices_tab", True))
        )
        ttk.Checkbutton(
            extra,
            text="Показывать вкладку дополнительных устройств",
            variable=self.vars["show_extra_devices_tab"],
        ).pack(anchor="w", pady=self.app.pad(4))
        summary = self.app.extra_config.port_summary()
        ttk.Label(
            extra,
            text=(
                f"{summary['board'].name}: свободно цифровых {summary['digital_free']}, "
                f"аналоговых {summary['analog_free']}. Добавлено датчиков: {len(self.app.extra_config.sensors())}."
            ),
            style=MUTED_LABEL_STYLE,
            wraplength=self._wraplength(),
        ).pack(anchor="w", fill="x", pady=(0, self.app.pad(8)))
        self._button_grid(
            extra,
            [
                ("Мастер датчиков", self.open_extra_sensors_settings, True),
                ("Запросить статус", self.app.check_extra_sensor_now, False),
                ("Сгенерировать код", self.app.generate_extra_sensor_firmware, False),
                ("Скомпилировать", self.app.compile_extra_sensor_firmware, False),
            ],
            columns=2,
        )
        self.extra_status_label = ttk.Label(extra, text="GeneratedConfig обновляется из расширенного мастера.", style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.extra_status_label.pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
        self.wrappable_labels.append(self.extra_status_label)
    def _build_device_section(self) -> None:
        self._prepare_content("device", "Устройство")
        card = self._section_card("Проверка Arduino")
        status = "подключена" if self.app.arduino.is_connected else "не подключена"
        self.test_status_label = ttk.Label(card, text=f"Arduino: {status}", style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.test_status_label.pack(anchor="w", fill="x", pady=(0, self.app.pad(8)))
        self._button_grid(
            card,
            [
                ("Проверить Arduino", self.app.start_arduino, True),
                ("Команда S", self._request_arduino_status, False),
            ],
            columns=2,
        )
        relays = self._section_card("Исполнительные устройства")
        self._button_grid(
            relays,
            [
                ("Лампа", lambda: self._send_device_test("lamp"), True),
                ("Вентилятор", lambda: self._send_device_test("fan"), False),
                ("Насос", lambda: self._send_device_test("pump"), False),
                ("Шторы", lambda: self._send_device_test("curtains"), False),
                ("Все включить", lambda: self._send_device_test("all"), False),
                ("Все выключить", lambda: self._send_direct_command("ALL_OFF"), False),
            ],
            columns=2,
        )
    def _build_arduino_section(self) -> None:
        self._prepare_content("arduino", "Arduino / порт")
        card = self._section_card("Serial-подключение")
        self.vars["auto_detect_port"] = tk.BooleanVar(value=bool(self.app.settings.get("auto_detect_port", True)))
        ttk.Checkbutton(card, text="Автоопределение порта", variable=self.vars["auto_detect_port"]).pack(anchor="w", pady=self.app.pad(4))
        self.vars["port"] = tk.StringVar(value=str(self.app.settings.get("port", "")))
        row = self._form_row(card, "Порт")
        self.port_combo = ttk.Combobox(row, textvariable=self.vars["port"], values=self.app.arduino.list_ports())
        self.port_combo.pack(side="left", fill="x", expand=True)
        self._register_scaled_input(self.port_combo, 18, 42)
        self._button_grid(
            card,
            [
                ("Обновить список", self.refresh_ports, False),
                ("Автоопределить", self._auto_detect_port, True),
                ("Подключить", self._save_and_start_arduino, True),
                ("Отключить", self.app.stop_arduino, False),
            ],
            columns=2,
        )
        self._spin(card, "Baudrate", "serial_baudrate", 1200, 115200)
        self._entry(card, "Timeout, сек", "serial_timeout", width=8)
        self.vars["arduino_auto_reconnect"] = tk.BooleanVar(value=bool(self.app.settings.get("arduino_auto_reconnect", True)))
        self.vars["auto_upload_thresholds"] = tk.BooleanVar(value=bool(self.app.settings.get("auto_upload_thresholds", True)))
        ttk.Checkbutton(card, text="Автопереподключение", variable=self.vars["arduino_auto_reconnect"]).pack(anchor="w", pady=self.app.pad(3))
        ttk.Checkbutton(card, text="Автоматически загружать пороги", variable=self.vars["auto_upload_thresholds"]).pack(anchor="w", pady=self.app.pad(3))
        ttk.Label(card, text="Протокол команд: main. Старое значение legacy в settings.json игнорируется.", style=MUTED_LABEL_STYLE, wraplength=self._wraplength()).pack(anchor="w", fill="x", pady=(self.app.pad(8), 0))
    def _build_wifi_controls(self) -> None:
        status_card = self._section_card("Текущий статус")
        self.wifi_status_label = ttk.Label(
            status_card,
            text="Статус Wi-Fi ещё не проверялся",
            style=MUTED_LABEL_STYLE,
            wraplength=self._wraplength(),
        )
        self.wifi_status_label.pack(anchor="w", fill="x", pady=(0, self.app.pad(8)))
        self.wrappable_labels.append(self.wifi_status_label)
        self._button_grid(status_card, [("Обновить список сетей", self.app.refresh_wifi_networks, True)], columns=1)
        networks_card = self._section_card("Сети")
        self.wifi_networks_frame = ttk.Frame(networks_card, style=SURFACE_FRAME_STYLE)
        self.wifi_networks_frame.pack(fill="x")
        self._update_wifi_networks([])
        password_card = self._section_card("Пароль сети")
        self.wifi_password_var = tk.StringVar(value="")
        self.wifi_show_password_var = tk.BooleanVar(value=False)
        row = self._form_row(password_card, "Пароль")
        self.wifi_password_entry = ttk.Entry(
            row,
            textvariable=self.wifi_password_var,
            width=self._scaled_chars(24),
            show="*",
        )
        self.wifi_password_entry.pack(side="left", fill="x", expand=True, ipady=self.app.pad(4))
        self._register_scaled_input(self.wifi_password_entry, 24)
        ttk.Checkbutton(
            password_card,
            text="Показать пароль",
            variable=self.wifi_show_password_var,
            command=self._toggle_wifi_password_visibility,
        ).pack(anchor="w", pady=self.app.pad(4))
        self._button_grid(
            password_card,
            [
                ("Подключиться", self._connect_selected_wifi, True),
                ("Отключиться", self.app.disconnect_wifi, False),
                ("Забыть сеть", self._forget_selected_wifi, False),
                ("Проверить интернет", self.app.check_wifi_internet, False),
            ],
            columns=2,
        )
        self.app.refresh_wifi_networks()
    def _build_photo_section(self) -> None:
        self._prepare_content("photo", "Фото")
        camera = self._section_card("Камера")
        self._spin(camera, "Индекс USB-камеры", "camera_index", 0, 10)
        self._entry(camera, "Папка фото/отчётов", "analysis_output_dir", width=32)
        self.vars["analysis_upload_enabled"] = tk.BooleanVar(value=bool(self.app.settings.get("analysis_upload_enabled", True)))
        ttk.Checkbutton(camera, text="Отправлять фото и отчёт на сервер", variable=self.vars["analysis_upload_enabled"]).pack(anchor="w", pady=self.app.pad(4))
        preview = ttk.Frame(camera, style=SURFACE_FRAME_STYLE, padding=self.app.pad(8))
        preview.pack(fill="x", pady=self.app.pad(6))
        self.photo_preview_label = ttk.Label(preview, text="Preview появится после тестового фото", style=MUTED_LABEL_STYLE)
        self.photo_preview_label.pack(anchor="center")
        self._button_grid(
            camera,
            [
                ("Проверить камеру", self._save_and_test_camera, True),
                ("Сделать тестовое фото", self._save_and_test_camera, False),
            ],
            columns=2,
        )
        self.photo_status_label = ttk.Label(camera, text="Камера не проверялась", style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.photo_status_label.pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
        self.wrappable_labels.append(self.photo_status_label)
        schedule = self._section_card("Автофото")
        self.vars["photo_schedule_enabled"] = tk.BooleanVar(value=bool(self.app.settings.get("photo_schedule_enabled", True)))
        self.vars["photo_send_to_server"] = tk.BooleanVar(value=bool(self.app.settings.get("photo_send_to_server", True)))
        self.vars["photo_save_local"] = tk.BooleanVar(value=bool(self.app.settings.get("photo_save_local", True)))
        ttk.Checkbutton(schedule, text="Делать фото по расписанию", variable=self.vars["photo_schedule_enabled"]).pack(anchor="w", pady=self.app.pad(3))
        ttk.Checkbutton(schedule, text="Отправлять фото на сервер", variable=self.vars["photo_send_to_server"]).pack(anchor="w", pady=self.app.pad(3))
        ttk.Checkbutton(schedule, text="Сохранять фото локально", variable=self.vars["photo_save_local"]).pack(anchor="w", pady=self.app.pad(3))
        for key, label, minimum, maximum in (
            ("photo_count", "Фото в день", 1, 2),
            ("photo_time", "Время фото 1, час", 0, 23),
            ("photo_time2", "Время фото 2, час", 0, 23),
            ("photo_interval_minutes", "Минимальный интервал, мин", 0, 10080),
            ("photo_check_interval", "Проверка расписания, сек", 30, 3600),
        ):
            self._spin(schedule, label, key, minimum, maximum)
        smart = self._section_card("Умное автофото")
        self.vars["smart_photo_enabled"] = tk.BooleanVar(value=bool(self.app.settings.get("smart_photo_enabled", True)))
        ttk.Checkbutton(smart, text="Включить умное автофото", variable=self.vars["smart_photo_enabled"]).pack(anchor="w", pady=self.app.pad(3))
        self._spin(smart, "Cooldown между фото, мин", "smart_photo_min_interval_minutes", 5, 10080)
        self._smart_photo_check(smart, "Фото при скачке температуры", "smart_photo_on_temperature_jump")
        self._entry(smart, "Скачок температуры, C", "smart_photo_temperature_jump_delta", width=8)
        self._smart_photo_check(smart, "Фото при падении влажности почвы", "smart_photo_on_soil_drop")
        self._entry(smart, "Падение влажности почвы, %", "smart_photo_soil_drop_delta", width=8)
        self._smart_photo_check(smart, "Фото при включении света", "smart_photo_on_light_on")
        self._spin(smart, "Если давно не было анализа, часов", "smart_photo_if_no_analysis_hours", 0, 720)
        self._smart_photo_check(smart, "Фото при тревожных показателях", "smart_photo_if_alert")
    def _build_calibration_section(self) -> None:
        self._prepare_content("calibration", "Калибровка датчиков")
        raw = self.app.arduino.current_snapshot
        current = self.app.latest_snapshot
        card = self._section_card("Текущие показания")
        self._reading_row(card, "Температура", raw.temperature, current.temperature, "C")
        self._reading_row(card, "Влажность воздуха", raw.humidity, current.humidity, "%")
        self._reading_row(card, "Давление", raw.pressure, current.pressure, "мм.рт.ст")
        self._reading_row(card, "CO2", raw.co2, current.co2, "ppm")
        self._reading_row(card, "Влажность почвы", raw.soil_moisture, current.soil_moisture, "%")
        controls = self._section_card("Смещения")
        for key, label, step, unit in (
            ("temperature_offset", "Температура", 0.1, "C"),
            ("humidity_offset", "Влажность воздуха", 1.0, "%"),
            ("pressure_offset", "Давление", 1.0, "мм.рт.ст"),
            ("co2_offset", "CO2", 10.0, "ppm"),
            ("soil_min", "Минимум почвы raw", 1.0, ""),
            ("soil_max", "Максимум почвы raw", 1.0, ""),
            ("soil_offset", "Смещение почвы", 1.0, "%"),
        ):
            self._calibration_stepper(controls, label, key, step, unit)
        cfg = self.app.settings.get("calibration", {})
        self.calibration_vars["soil_invert"] = tk.BooleanVar(value=bool(cfg.get("soil_invert", False)))
        ttk.Checkbutton(controls, text="Инвертировать влажность почвы", variable=self.calibration_vars["soil_invert"]).pack(anchor="w", pady=self.app.pad(6))
        self._button_grid(controls, [("Сохранить калибровку", self.save, True)], columns=1)
    def _build_info_section(self) -> None:
        self._prepare_content("info", "Информация", show_save=False)
        card = self._section_card("Состояние")
        token_state = "привязан" if self.app.settings.get("token", "").strip() else "не привязан"
        device_state = self.app.settings.get("device_name", "") or "не выбрано"
        arduino_state = "подключена" if self.app.arduino.is_connected else "не подключена"
        ttk.Label(card, text=f"Версия приложения: {APP_VERSION}", style=SURFACE_LABEL_STYLE).pack(anchor="w", pady=self.app.pad(2))
        ttk.Label(card, text=f"Аккаунт: {token_state}", style=SURFACE_LABEL_STYLE).pack(anchor="w", pady=self.app.pad(2))
        ttk.Label(card, text=f"Устройство: {device_state}", style=SURFACE_LABEL_STYLE).pack(anchor="w", pady=self.app.pad(2))
        ttk.Label(card, text=f"Arduino: {arduino_state}", style=SURFACE_LABEL_STYLE).pack(anchor="w", pady=self.app.pad(2))
        self._button_grid(
            card,
            [
                ("Проверить обновления", self.app.check_desktop_update, True),
                ("Скачать обновление", self.app.download_desktop_update, False),
                ("Открыть fitodomik.ru", lambda: self._open_url("https://fitodomik.ru"), False),
                ("Открыть кабинет", lambda: self._open_url("https://fitodomik.ru/farm.php"), False),
                ("Папка приложения", self._open_app_folder, False),
                ("Выйти из fullscreen", self.app.toggle_fullscreen, False),
                ("Вернуть fullscreen", self.app.toggle_fullscreen, False),
                ("Закрыть приложение", self.app.close, True),
            ],
            columns=2,
        )
        self.update_status_label = ttk.Label(card, text="Обновления ещё не проверялись", style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.update_status_label.pack(anchor="w", fill="x", pady=(self.app.pad(8), 0))
        self.wrappable_labels.append(self.update_status_label)
        self._build_wifi_controls()
    def _section_card(self, title: str) -> ttk.Frame:
        card = ttk.Frame(self.scroll.inner, style="Card.TFrame", padding=self.app.pad(12))
        card.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(card, text=title, style=SURFACE_LABEL_STYLE, font=self.app.theme.font_tuple(12, "bold", maximum=16)).pack(anchor="w", pady=(0, self.app.pad(8)))
        self.section_cards.append(card)
        return card
    def _button_grid(self, parent, buttons: list[tuple[str, Callable[[], object], bool]], columns: int = 2) -> None:
        frame = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        frame.pack(fill="x", pady=self.app.pad(5))
        for column in range(columns):
            frame.columnconfigure(column, weight=1, uniform="settings_buttons")
        for index, (label, command, accent) in enumerate(buttons):
            button = ttk.Button(frame, text=label, command=command, style=ACCENT_BUTTON_STYLE if accent else "TButton")
            row, column = divmod(index, columns)
            button.grid(row=row, column=column, sticky="ew", padx=self.app.pad(3), pady=self.app.pad(3), ipady=self.app.pad(4))
    def _form_row(self, parent, label: str) -> ttk.Frame:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=18).pack(side="left", padx=(0, self.app.pad(6)))
        return row
    def _entry(self, parent, label: str, key: str, width: int = 24) -> None:
        self.vars[key] = tk.StringVar(value=str(self.app.settings.get(key, "")))
        row = self._form_row(parent, label)
        entry = ttk.Entry(row, textvariable=self.vars[key], width=self._scaled_chars(width))
        entry.pack(side="left", fill="x", expand=True)
        self._register_scaled_input(entry, width)
    def _password_entry(self, parent, label: str, key: str, width: int = 24) -> None:
        self.vars[key] = tk.StringVar(value="")
        row = self._form_row(parent, label)
        entry = ttk.Entry(row, textvariable=self.vars[key], width=self._scaled_chars(width), show="*")
        entry.pack(side="left", fill="x", expand=True)
        self._register_scaled_input(entry, width)
    def _spin(self, parent, label: str, key: str, from_: int, to: int) -> None:
        self.vars[key] = tk.StringVar(value=str(self.app.settings.get(key, from_)))
        row = self._form_row(parent, label)
        spin = ttk.Spinbox(row, from_=from_, to=to, textvariable=self.vars[key], width=self._scaled_chars(8, 16))
        spin.pack(side="left", fill="x", expand=True)
        self._register_scaled_input(spin, 8, 16)
    def _readonly_row(self, parent, label: str, variable: tk.StringVar) -> None:
        row = self._form_row(parent, label)
        entry = ttk.Entry(row, textvariable=variable, state="readonly")
        entry.pack(side="left", fill="x", expand=True)
    def _smart_photo_check(self, parent, label: str, key: str) -> None:
        self.vars[key] = tk.BooleanVar(value=bool(self.app.settings.get(key, True)))
        ttk.Checkbutton(parent, text=label, variable=self.vars[key]).pack(anchor="w", pady=self.app.pad(3))
    def _reading_row(self, parent, label: str, raw_value: float | None, current_value: float | None, unit: str) -> None:
        text = f"{label}: raw {self._format_value(raw_value, unit)} · итог {self._format_value(current_value, unit)}"
        ttk.Label(parent, text=text, style=SURFACE_LABEL_STYLE, wraplength=self._wraplength()).pack(anchor="w", fill="x", pady=self.app.pad(2))
    def _calibration_stepper(self, parent, label: str, key: str, step: float, unit: str) -> None:
        cfg = self.app.settings.get("calibration", {})
        self.calibration_vars[key] = tk.StringVar(value=str(cfg.get(key, 0)))
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=20).pack(side="left")
        ttk.Button(row, text="-", command=lambda: self._adjust_calibration(key, -step)).pack(side="left", padx=self.app.pad(3), ipadx=self.app.pad(8))
        value = ttk.Label(row, textvariable=self.calibration_vars[key], style=SURFACE_LABEL_STYLE, width=10)
        value.pack(side="left", padx=self.app.pad(4))
        ttk.Button(row, text="+", command=lambda: self._adjust_calibration(key, step)).pack(side="left", padx=self.app.pad(3), ipadx=self.app.pad(8))
        ttk.Label(row, text=unit, style=MUTED_LABEL_STYLE, width=10).pack(side="left")
        ttk.Button(row, text="Сбросить", command=lambda: self._reset_calibration(key)).pack(side="right", padx=self.app.pad(3))
    def _adjust_calibration(self, key: str, delta: float) -> None:
        var = self.calibration_vars.get(key)
        if not var:
            return
        try:
            current = float(var.get() or 0)
        except ValueError:
            current = 0.0
        value = current + delta
        var.set(f"{value:.2f}".rstrip("0").rstrip("."))
    def _reset_calibration(self, key: str) -> None:
        defaults = {"soil_max": 100.0}
        var = self.calibration_vars.get(key)
        if var:
            var.set(str(defaults.get(key, 0.0)))
    def save(self) -> None:
        old_token = self.app.settings.get("token", "")
        values = self._collect_settings_values()
        self.app.settings.update(values, save=True)
        self._save_calibration_values()
        self.app.apply_settings_changes()
        self._refresh_remote_after_save(old_token)
        self._set_local_status("Настройки сохранены")
    def _collect_settings_values(self) -> dict[str, Any]:
        values: dict[str, Any] = {}
        for key, var in self.vars.items():
            if key == "account_password":
                continue
            value = var.get()
            try:
                if key in INTEGER_SETTING_KEYS:
                    value = int(value)
                elif key in FLOAT_SETTING_KEYS:
                    value = float(value)
            except (TypeError, ValueError):
                continue
            values[key] = value
        return values
    def _save_calibration_values(self) -> None:
        if not self.calibration_vars:
            return
        calibration = dict(self.app.settings.get("calibration", {}))
        for key, var in self.calibration_vars.items():
            if key == "soil_invert":
                calibration[key] = bool(var.get())
                continue
            try:
                calibration[key] = float(var.get())
            except (TypeError, ValueError):
                continue
        self.app.settings.set("calibration", calibration, save=True)
    def _refresh_remote_after_save(self, old_token: str) -> None:
        new_token = self.app.settings.get("token", "")
        if new_token:
            self.app.check_api_token()
            self.app.refresh_thresholds()
        elif old_token:
            self.app.clear_remote_data()
    def link_account(self) -> None:
        username = str(self.vars.get("account_username", tk.StringVar()).get()).strip()
        password = str(self.vars.get("account_password", tk.StringVar()).get())
        if not username or not password:
            self.set_account_status("Введите имя и пароль")
            return
        device_name = str(self.vars.get("device_name", tk.StringVar()).get()).strip()
        if device_name:
            self.app.settings.set("device_name", device_name, save=True)
        self.set_account_status("Проверяю аккаунт...")
        self.app.link_account(username, password)
    def start_qr_pair(self) -> None:
        device_name = str(self.vars.get("device_name", tk.StringVar()).get()).strip()
        if device_name:
            self.app.settings.set("device_name", device_name, save=True)
        self.app.start_device_pair_qr()
    def check_weather_tokens(self) -> None:
        self._save_city()
        self.set_weather_token_status("Проверяю WeatherAPI...")
        self.app.check_weather_tokens()
    def _check_weather_fallback(self) -> None:
        self._save_city()
        self.set_weather_token_status("Проверяю fallback Open-Meteo...")
        self.app.refresh_weather()
    def _save_city_and_refresh_weather(self) -> None:
        self._save_city()
        self.app.refresh_weather()
    def _save_city(self) -> None:
        city = str(self.vars.get("weather_city", tk.StringVar(value="Moscow")).get()).strip() or "Moscow"
        self.app.settings.set("weather_city", city, save=True)
    def _request_arduino_status(self) -> None:
        self._set_test_status("Запрашиваю статус Arduino...")
        self.app.task_manager.run_once("arduino_status_from_settings", self.app.arduino.request_status)
    def _send_device_test(self, device: str) -> None:
        self._set_test_status(f"Отправляю тест: {device}")
        self.app.run_device_test(device)
    def _send_direct_command(self, device: str) -> None:
        self._set_test_status(f"Отправляю команду: {device}")
        self.app.task_manager.run_once(f"settings_command_{device}", lambda: self.app.arduino.send_command(device))
    def _save_and_start_arduino(self) -> None:
        self.save()
        self.app.start_arduino()
    def _save_and_test_camera(self) -> None:
        self.save()
        self._set_photo_status("Проверяю камеру...")
        self.app.test_camera()
    def _auto_detect_port(self) -> None:
        port = self.app.arduino.auto_detect_port() or ""
        if "port" in self.vars:
            self.vars["port"].set(port)
        self._set_local_status(f"Найден порт: {port or 'не найден'}")
    def refresh_ports(self) -> None:
        try:
            if self.port_combo and self.port_combo.winfo_exists():
                self.port_combo.configure(values=self.app.arduino.list_ports())
        except tk.TclError:
            pass
    def set_update_status(self, message: str) -> None:
        if self.update_status_label and self.update_status_label.winfo_exists():
            self.update_status_label.configure(text=message)
        self._set_local_status(message)
    def set_extra_sensor_status(self, message: str) -> None:
        if self.extra_settings_view and self.extra_settings_view.winfo_exists():
            self.extra_settings_view.set_status(message)
        if self.extra_status_label and self.extra_status_label.winfo_exists():
            self.extra_status_label.configure(text=message)
        self._set_local_status(message)
    def set_account_status(self, message: str) -> None:
        if self.account_status_label and self.account_status_label.winfo_exists():
            self.account_status_label.configure(text=message)
        self._set_local_status(message)
    def refresh_account_info(self) -> None:
        if hasattr(self, "token_value"):
            self.token_value.set(_mask_secret(self.app.settings.get("token", "")))
        if hasattr(self, "device_id_value"):
            self.device_id_value.set(str(self.app.settings.get("device_id", "")) or NOT_SET_TEXT)
        if hasattr(self, "device_token_value"):
            self.device_token_value.set(_mask_secret(self.app.settings.get("device_token", "")))
        if "device_name" in self.vars:
            self.vars["device_name"].set(self.app.settings.get("device_name", ""))
        text = "Аккаунт привязан" if self.app.settings.get("token", "").strip() else "Аккаунт не привязан"
        if self.app.settings.get("device_name", ""):
            text += f" · {self.app.settings.get('device_name', '')}"
        self.set_account_status(text)
    def update_device_list(self, devices: list[dict] | None, message: str = "") -> None:
        if not self.device_list_frame or not self.device_list_frame.winfo_exists():
            return
        for child in self.device_list_frame.winfo_children():
            child.destroy()
        ttk.Label(self.device_list_frame, text="Устройства аккаунта", style=SURFACE_LABEL_STYLE, font=self.app.theme.font_tuple(11, "bold", maximum=15)).pack(anchor="w")
        if message:
            ttk.Label(self.device_list_frame, text=message, style=MUTED_LABEL_STYLE, wraplength=self._wraplength()).pack(anchor="w", fill="x", pady=self.app.pad(3))
        current_id = str(self.app.settings.get("device_id", ""))
        if not devices:
            local_name = self.app.settings.get("device_name", "") or "FitoDomik"
            ttk.Label(
                self.device_list_frame,
                text=f"Текущее устройство: {local_name}. ID: {current_id or 'пока не получен'}",
                style=MUTED_LABEL_STYLE,
                wraplength=self._wraplength(),
            ).pack(anchor="w", fill="x", pady=self.app.pad(4))
            return
        for device in devices:
            self._device_row(device, current_id)
        self.refresh_account_info()
    def _device_row(self, device: dict, current_id: str) -> None:
        device_id = str(device.get("device_id", ""))
        device_name = str(device.get("device_name", "FitoDomik"))
        device_token = str(device.get("device_token", ""))
        status = str(device.get("status", "active"))
        row = ttk.Frame(self.device_list_frame, style=SURFACE_FRAME_STYLE, padding=(0, self.app.pad(4)))
        row.pack(fill="x")
        marker = "выбрано" if device_id == current_id else status
        ttk.Label(row, text=f"{device_name} · {marker}", style=SURFACE_LABEL_STYLE).pack(side="left", fill="x", expand=True)
        if status == "active" and device_id != current_id:
            ttk.Button(
                row,
                text="Использовать",
                command=lambda did=device_id, name=device_name, token=device_token: self.app.select_local_device(did, name, token),
            ).pack(side="right", padx=self.app.pad(3))
    def set_weather_token_status(self, message: str) -> None:
        if self.weather_token_status_label and self.weather_token_status_label.winfo_exists():
            self.weather_token_status_label.configure(text=message)
        self._set_local_status(message)
    def handle_wifi_event(self, name: str, payload: object, message: str = "") -> None:
        if isinstance(payload, WifiScanResult):
            self._update_wifi_status(payload.status, message)
            self._update_wifi_networks(payload.networks)
        elif isinstance(payload, WifiOperationResult):
            self._update_wifi_status(payload.status, payload.message or message)
            self._update_wifi_networks(payload.networks)
        elif isinstance(payload, WifiStatus):
            self._update_wifi_status(payload, message)
        else:
            self._set_wifi_status(message or "Wi-Fi: ответ не распознан")
        self._set_local_status(message)
    def _toggle_wifi_password_visibility(self) -> None:
        if not self.wifi_password_entry or not self.wifi_password_entry.winfo_exists():
            return
        show_password = bool(self.wifi_show_password_var and self.wifi_show_password_var.get())
        self.wifi_password_entry.configure(show="" if show_password else "*")
    def _connect_selected_wifi(self) -> None:
        if not self.wifi_selected_ssid:
            self._set_wifi_status("Выберите Wi-Fi сеть")
            return
        password = self.wifi_password_var.get() if self.wifi_password_var else ""
        self.app.connect_wifi(self.wifi_selected_ssid, password)
    def _forget_selected_wifi(self) -> None:
        if not self.wifi_selected_ssid:
            self._set_wifi_status("Выберите Wi-Fi сеть")
            return
        self.app.forget_wifi(self.wifi_selected_ssid)
    def _update_wifi_status(self, status: WifiStatus, message: str = "") -> None:
        state = "включён" if status.enabled else "выключен"
        connection = "подключено" if status.connected else "не подключено"
        parts = [f"Wi-Fi: {state}", f"Состояние: {connection}", f"Управление: {status.manager}"]
        if status.ssid:
            parts.append(f"Сеть: {status.ssid}")
        if status.ip_address:
            parts.append(f"IP: {status.ip_address}")
        if status.signal is not None:
            parts.append(f"Сигнал: {status.signal}%")
        if message or status.message:
            parts.append(message or status.message)
        self._set_wifi_status("\n".join(parts))
    def _set_wifi_status(self, message: str) -> None:
        if self.wifi_status_label and self.wifi_status_label.winfo_exists():
            self.wifi_status_label.configure(text=message)
        self._set_local_status(message.splitlines()[-1] if message else "")
    def _update_wifi_networks(self, networks: list[WifiNetwork]) -> None:
        if not self.wifi_networks_frame or not self.wifi_networks_frame.winfo_exists():
            return
        for child in self.wifi_networks_frame.winfo_children():
            child.destroy()
        if not networks:
            ttk.Label(
                self.wifi_networks_frame,
                text="Сети ещё не найдены. Нажмите «Обновить список сетей».",
                style=MUTED_LABEL_STYLE,
                wraplength=self._wraplength(),
            ).pack(anchor="w", fill="x", pady=self.app.pad(4))
            return
        for network in networks:
            self._wifi_network_row(network, networks)
    def _wifi_network_row(self, network: WifiNetwork, networks: list[WifiNetwork]) -> None:
        selected = network.ssid == self.wifi_selected_ssid
        style = "TileHover.TFrame" if selected or network.connected else SURFACE_FRAME_STYLE
        row = ttk.Frame(self.wifi_networks_frame, style=style, padding=self.app.pad(8))
        row.pack(fill="x", pady=self.app.pad(3))
        row.columnconfigure(0, weight=1)
        label = ttk.Label(
            row,
            text=self._wifi_network_label(network),
            style=SURFACE_LABEL_STYLE,
            wraplength=self._wraplength(),
        )
        label.grid(row=0, column=0, sticky="ew")
        button = ttk.Button(
            row,
            text="Выбрана" if selected else "Выбрать",
            command=lambda ssid=network.ssid, items=networks: self._select_wifi_network(ssid, items),
            style=ACCENT_BUTTON_STYLE if selected else "TButton",
        )
        button.grid(row=0, column=1, sticky="e", padx=(self.app.pad(8), 0), ipadx=self.app.pad(4), ipady=self.app.pad(3))
        for widget in (row, label):
            widget.bind("<Button-1>", lambda _event, ssid=network.ssid, items=networks: self._select_wifi_network(ssid, items))
    def _select_wifi_network(self, ssid: str, networks: list[WifiNetwork]) -> None:
        self.wifi_selected_ssid = ssid
        self._update_wifi_networks(networks)
        self._set_local_status(f"Выбрана сеть: {ssid}")
    def _wifi_network_label(self, network: WifiNetwork) -> str:
        signal = f"{network.signal}%" if network.signal is not None else "сигнал неизвестен"
        security = "защищена" if network.is_secured else "открытая"
        current = " · подключена" if network.connected else ""
        return f"{network.ssid} · {signal} · {security}{current}"
    def show_device_pair_qr(self, payload: dict) -> None:
        if self.current_section != "account":
            self._build_account_section()
        self.close_pair_window(cancel_poll=False)
        link = str(payload.get("link", ""))
        pair_code = str(payload.get("pair_code", ""))
        expires_in = int(payload.get("expires_in", 900) or 900)
        self.pair_expires_at = datetime.now() + timedelta(seconds=expires_in)
        parent = self.account_card or self.scroll.inner
        self.pair_frame = ttk.Frame(parent, style=SURFACE_FRAME_STYLE, padding=self.app.pad(8))
        self.pair_frame.pack(fill="x", pady=(self.app.pad(8), 0))
        qr_holder = ttk.Frame(self.pair_frame, style=SURFACE_FRAME_STYLE)
        qr_holder.pack(side="left", padx=(0, self.app.pad(10)), anchor="n")
        try:
            import qrcode
            from PIL import ImageTk
            qr_image: Any = qrcode.make(link)
            image = qr_image.convert("RGB").resize((180, 180))
            self.pair_photo = ImageTk.PhotoImage(image)
            ttk.Label(qr_holder, image=self.pair_photo, style=SURFACE_LABEL_STYLE).pack()
        except Exception:
            ttk.Label(qr_holder, text="QR недоступен. Проверьте пакет qrcode.", style=SURFACE_LABEL_STYLE, wraplength=220).pack()
        body = ttk.Frame(self.pair_frame, style=SURFACE_FRAME_STYLE)
        body.pack(side="left", fill="both", expand=True)
        ttk.Label(body, text="Отсканируйте QR телефоном", style=SURFACE_LABEL_STYLE, font=self.app.theme.font_tuple(11, "bold", maximum=15)).pack(anchor="w")
        self.pair_timer_label = ttk.Label(body, text="Код действует 15:00", style=MUTED_LABEL_STYLE)
        self.pair_timer_label.pack(anchor="w", pady=self.app.pad(2))
        self.pair_status_label = ttk.Label(body, text="Ожидаю подтверждение на сайте", style=MUTED_LABEL_STYLE, wraplength=max(220, self._wraplength() // 2))
        self.pair_status_label.pack(anchor="w", fill="x", pady=self.app.pad(2))
        self._button_grid(body, [("Сгенерировать заново", self.app.start_device_pair_qr, True), ("Скрыть QR", self.close_pair_window, False)], columns=1)
        self.set_account_status(f"QR-код создан: {pair_code}")
        self._update_pair_timer()
    def set_pair_status(self, message: str) -> None:
        if self.pair_status_label and self.pair_status_label.winfo_exists():
            self.pair_status_label.configure(text=message)
        self.set_account_status(message)
    def close_pair_window(self, cancel_poll: bool = True) -> None:
        if cancel_poll:
            self.app.cancel_device_pair_poll()
        if self.pair_after_id and self.pair_frame:
            try:
                self.pair_frame.after_cancel(self.pair_after_id)
            except tk.TclError:
                pass
        self.pair_after_id = None
        if self.pair_frame and self.pair_frame.winfo_exists():
            self.pair_frame.destroy()
        self.pair_frame = None
        self.pair_photo = None
        self.pair_expires_at = None
        self.pair_timer_label = None
        self.pair_status_label = None
    def _update_pair_timer(self) -> None:
        if not self.pair_frame or not self.pair_frame.winfo_exists() or not self.pair_timer_label or not self.pair_expires_at:
            return
        remaining =max(0, int((self.pair_expires_at - datetime.now()).total_seconds()))
        minutes, seconds = divmod(remaining, 60)
        self.pair_timer_label.configure(text=f"Код действует {minutes:02d}:{seconds:02d}")
        if remaining <= 0:
            self.set_pair_status("Код истёк. Сгенерируйте новый QR.")
            return
        self.pair_after_id = self.pair_frame.after(1000, self._update_pair_timer)
    def set_camera_test_result(self, path: Path | str, message: str = "") -> None:
        self._set_photo_status(message or "Камера работает")
        if not self.photo_preview_label or not self.photo_preview_label.winfo_exists():
            return
        try:
            self.photo_preview = load_resized_photo(Path(path), (260, 150))
            self.photo_preview_label.configure(image=cast(Any, self.photo_preview), text="")
        except Exception:
            self.photo_preview_label.configure(text=str(path))
    def refresh_settings_values(self) -> None:
        self.refresh_account_info()
    def refresh_palette(self) -> None:
        if hasattr(self, "scroll"):
            self.scroll.set_palette(self.app.palette)
        for child in self.winfo_children():
            try:
                child.update_idletasks()
            except tk.TclError:
                pass
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(14), pady=(self.app.pad(14), self.app.pad(6)))
        self.scroll.pack_configure(padx=self.app.pad(14), pady=(self.app.pad(6), self.app.pad(14)))
        self._refresh_wraplengths()
        self._refresh_scaled_inputs()
        if self.extra_settings_view and self.extra_settings_view.winfo_exists() and hasattr(self.extra_settings_view, "refresh_scale"):
            self.extra_settings_view.refresh_scale()
        if self.first_run_view and self.first_run_view.winfo_exists() and hasattr(self.first_run_view, "refresh_scale"):
            self.first_run_view.refresh_scale()
        self.refresh_palette()
    def _refresh_wraplengths(self) -> None:
        wraplength = self._wraplength()
        alive: list[ttk.Label] = []
        for label in self.wrappable_labels:
            try:
                if label.winfo_exists():
                    label.configure(wraplength=wraplength)
                    alive.append(label)
            except tk.TclError:
                pass
        self.wrappable_labels = alive
    def _refresh_scaled_inputs(self) -> None:
        for widget, width, maximum in self.scaled_inputs:
            try:
                widget.configure({"width": self._scaled_chars(width, maximum)})
            except tk.TclError:
                pass
    def _register_scaled_input(self, widget: tk.Widget, width: int, maximum: int = 64) -> None:
        self.scaled_inputs.append((widget, width, maximum))
    def _scaled_chars(self, width: int, maximum: int = 64) -> int:
        return max(width, min(maximum, int(round(width * self.app.theme.scale.font_scale))))
    def _wraplength(self) -> int:
        width = self.scroll.canvas.winfo_width() if hasattr(self, "scroll") else 0
        if width <= 1:
            width = self.app.content_width()
        return max(260, min(760, width - self.app.pad(48)))
    def _deactivate_first_run_view(self) -> None:
        try:
            if self.first_run_view and self.first_run_view.winfo_exists() and hasattr(self.first_run_view, "deactivate"):
                self.first_run_view.deactivate()
        except tk.TclError:
            pass
    def _set_local_status(self, message: str) -> None:
        if self.status_label and self.status_label.winfo_exists():
            self.status_label.configure(text=message)
        if message:
            try:
                self.app._set_status(message)
            except Exception:
                pass
    def _set_test_status(self, message: str) -> None:
        if self.test_status_label and self.test_status_label.winfo_exists():
            self.test_status_label.configure(text=message)
        self._set_local_status(message)
    def _set_photo_status(self, message: str) -> None:
        if self.photo_status_label and self.photo_status_label.winfo_exists():
            self.photo_status_label.configure(text=message)
        self._set_local_status(message)
    def _open_app_folder(self) -> None:
        path = Path.cwd()
        try:
            if os.name == "nt":
                os.startfile(path)  
            else:
                webbrowser.open(path.as_uri())
        except Exception as exc:
            self._set_local_status(f"Не удалось открыть папку: {exc}")
    def _open_url(self, url: str) -> None:
        webbrowser.open(url)
    def _device_mode_text(self, mode: str) -> str:
        return {
            "standard": "стандартная схема",
            "empty": "пустое устройство",
            "custom": "пользовательская конфигурация",
        }.get(mode, mode)
    def _format_value(self, value: float | None, unit: str) -> str:
        if value is None:
            return "недоступно"
        suffix = f" {unit}" if unit else ""
        return f"{float(value):.1f}{suffix}"
