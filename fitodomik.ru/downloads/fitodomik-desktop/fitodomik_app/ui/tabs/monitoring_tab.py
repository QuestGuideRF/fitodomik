from __future__ import annotations
from tkinter import ttk
from fitodomik_app.core.models import ExtraSensorValue, SensorSnapshot
from fitodomik_app.ui.style_names import ACCENT_BUTTON_STYLE, APP_FRAME_STYLE, MUTED_LABEL_STYLE, SURFACE_FRAME_STYLE, SURFACE_LABEL_STYLE
from fitodomik_app.ui.widgets import Card, StatusPill
class MonitoringTab(ttk.Frame):
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.sensor_values: dict[str, ttk.Label] = {}
        self.device_pills: dict[str, StatusPill] = {}
        self.sensor_cards: dict[str, Card] = {}
        self.sensor_icon_labels: dict[str, ttk.Label] = {}
        self.sensor_icon_names: dict[str, str] = {}
        self.relay_rows: dict[str, ttk.Frame] = {}
        self.device_card: Card | None = None
        self.main_grid: ttk.Frame | None = None
        self.sensors_grid: ttk.Frame | None = None
        self.empty_label: ttk.Label | None = None
        self._build()
    def rebuild(self) -> None:
        for child in self.winfo_children():
            child.destroy()
        self.sensor_values.clear()
        self.device_pills.clear()
        self.sensor_cards.clear()
        self.sensor_icon_labels.clear()
        self.sensor_icon_names.clear()
        self.relay_rows.clear()
        self.device_card = None
        self.main_grid = None
        self.sensors_grid = None
        self.empty_label = None
        self._build()
        self.update_snapshot(self.app.latest_snapshot)
    def _build(self) -> None:
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(14), pady=(self.app.pad(14), self.app.pad(6)))
        ttk.Label(self.top, text="Мониторинг", style="Title.TLabel").pack(side="left")
        self.start_button = ttk.Button(self.top, text="Запустить", style=ACCENT_BUTTON_STYLE, command=self.app.start_arduino)
        self.start_button.pack(side="right", padx=self.app.pad(3))
        self.stop_button = ttk.Button(self.top, text="Остановить", style="Small.TButton", command=self.app.stop_arduino)
        self.stop_button.pack(side="right", padx=self.app.pad(3))
        sensors = self.app.device_config.monitoring_sensors()
        relays = self.app.device_config.control_relays()
        if not sensors and not relays:
            self.empty_label = ttk.Label(
                self,
                text="Датчики и реле ещё не добавлены. Откройте настройки устройства.",
                style=MUTED_LABEL_STYLE,
                wraplength=620,
            )
            self.empty_label.pack(expand=True, padx=self.app.pad(18), pady=self.app.pad(18))
            self._build_status()
            return
        self.main_grid = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.main_grid.pack(fill="both", expand=True, padx=self.app.pad(14), pady=self.app.pad(6))
        self.main_grid.columnconfigure(0, weight=3)
        if relays:
            self.main_grid.columnconfigure(1, weight=2, minsize=self.app.sx(180, minimum=180, maximum=260))
        self.main_grid.rowconfigure(0, weight=1)
        self.sensors_grid = ttk.Frame(self.main_grid, style=APP_FRAME_STYLE)
        self.sensors_grid.grid(row=0, column=0, sticky="nsew", padx=(0, self.app.pad(6) if relays else 0))
        for sensor in sensors:
            self._sensor_card(sensor)
        if relays:
            self.device_card = Card(
                self.main_grid,
                "Устройства",
                self.app.palette,
                padding=self.app.pad(10),
                title_font=self.app.theme.fonts["card_title"],
            )
            self.device_card.grid(row=0, column=1, sticky="nsew", padx=(self.app.pad(6), 0), pady=self.app.pad(5))
            for relay in relays:
                self._relay_row(relay)
        self._build_status()
        self._layout_sensor_cards()
    def _build_status(self) -> None:
        self.status_label = ttk.Label(self, text="Arduino: ожидание подключения", style=MUTED_LABEL_STYLE)
        self.status_label.pack(anchor="w", padx=self.app.pad(18), pady=(0, self.app.pad(8)))
    def _sensor_card(self, sensor: dict) -> None:
        key = str(sensor.get("sensor_uid", ""))
        title = self.app.device_config.sensor_label(sensor)
        card = Card(
            self.sensors_grid,
            title,
            self.app.palette,
            padding=self.app.pad(10),
            title_font=self.app.theme.fonts["card_title"],
        )
        icon_name = str(sensor.get("icon", "monitor"))
        icon = self.app.icons.get(icon_name, self.app.icon_size(28, maximum=38))
        if icon:
            icon_label = ttk.Label(card, image=icon, style=SURFACE_LABEL_STYLE)
            setattr(icon_label, "_image_ref", icon)
            icon_label.pack(anchor="e")
            self.sensor_icon_labels[key] = icon_label
            self.sensor_icon_names[key] = icon_name
        value = ttk.Label(
            card,
            text=self._initial_sensor_text(sensor),
            style="Big.TLabel",
            font=self.app.theme.fonts["value"],
        )
        value.pack(anchor="w", pady=(self.app.pad(4), 0))
        self.sensor_values[key] = value
        self.sensor_cards[key] = card
    def _relay_row(self, relay: dict) -> None:
        if not self.device_card:
            return
        key = str(relay.get("relay_uid", relay.get("state_field", "")))
        row = ttk.Frame(self.device_card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(row, text=self.app.device_config.relay_label(relay), style=SURFACE_LABEL_STYLE).pack(side="left")
        pill = StatusPill(
            row,
            self.app.palette,
            width=self.app.sx(92, minimum=92, maximum=136),
            height=self.app.sx(28, minimum=28, maximum=36),
            font_size=self.app.theme.scale.font(10),
        )
        pill.pack(side="right")
        self.device_pills[key] = pill
        self.relay_rows[key] = row
    def _layout_sensor_cards(self) -> None:
        if not self.sensors_grid:
            return
        columns = 3 if self.app.content_width() >= 860 else 2
        for col in range(3):
            self.sensors_grid.columnconfigure(col, weight=1 if col < columns else 0)
        for row in range(max(1, (len(self.sensor_cards) + columns - 1) // columns)):
            self.sensors_grid.rowconfigure(row, weight=1)
        for index, key in enumerate(self.sensor_cards):
            card = self.sensor_cards[key]
            card.grid_forget()
            card.grid(
                row=index // columns,
                column=index % columns,
                sticky="nsew",
                padx=self.app.pad(5),
                pady=self.app.pad(5),
            )
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(14), pady=(self.app.pad(14), self.app.pad(6)))
        self.start_button.pack_configure(padx=self.app.pad(3))
        self.stop_button.pack_configure(padx=self.app.pad(3))
        if self.empty_label:
            self.empty_label.configure(wraplength=max(260, self.app.content_width() - self.app.pad(80)))
        if self.main_grid:
            self.main_grid.pack_configure(padx=self.app.pad(14), pady=self.app.pad(6))
            if self.device_card:
                self.main_grid.columnconfigure(1, minsize=self.app.sx(180, minimum=180, maximum=260))
        if self.sensors_grid:
            self.sensors_grid.grid_configure(padx=(0, self.app.pad(6) if self.device_card else 0))
        for key, card in self.sensor_cards.items():
            card.set_padding(self.app.pad(10))
            card.set_title_font(self.app.theme.fonts["card_title"])
            if key in self.sensor_values:
                self.sensor_values[key].configure(font=self.app.theme.fonts["value"])
            icon_label = self.sensor_icon_labels.get(key)
            icon_name = self.sensor_icon_names.get(key)
            if icon_label and icon_name:
                icon = self.app.icons.get(icon_name, self.app.icon_size(28, maximum=38))
                icon_label.configure(image=icon)
                setattr(icon_label, "_image_ref", icon)
        if self.device_card:
            self.device_card.set_padding(self.app.pad(10))
            self.device_card.set_title_font(self.app.theme.fonts["card_title"])
            self.device_card.grid_configure(padx=(self.app.pad(6), 0), pady=self.app.pad(5))
        for pill in self.device_pills.values():
            pill.set_size(
                self.app.sx(92, minimum=92, maximum=136),
                self.app.sx(28, minimum=28, maximum=36),
                self.app.theme.scale.font(10),
            )
        self.status_label.pack_configure(padx=self.app.pad(18), pady=(0, self.app.pad(8)))
        self._layout_sensor_cards()
        self.refresh_palette()
    def update_snapshot(self, snapshot: SensorSnapshot) -> None:
        for sensor in self.app.device_config.monitoring_sensors():
            if self.app.device_config.is_extra_sensor(sensor):
                continue
            key = str(sensor.get("sensor_uid", ""))
            label = self.sensor_values.get(key)
            if label:
                value = self.app.device_config.sensor_value(sensor, snapshot)
                text = self.app.device_config.format_sensor_value(sensor, value)
                if str(label.cget("text")) != text:
                    label.configure(text=text)
        for relay in self.app.device_config.control_relays():
            key = str(relay.get("relay_uid", relay.get("state_field", "")))
            pill = self.device_pills.get(key)
            if pill:
                state = self.app.device_config.relay_state(relay, snapshot)
                text = str(relay.get("on_label" if state else "off_label", "Вкл" if state else "Выкл"))
                pill.set_state(state, text)
    def update_extra_values(self, values: list[ExtraSensorValue]) -> None:
        for value in values:
            label = self.sensor_values.get(value.sensor_id)
            if label:
                text = self._format_extra_value(value)
                if str(label.cget("text")) != text:
                    label.configure(text=text)
    def refresh_palette(self) -> None:
        for pill in self.device_pills.values():
            pill.set_palette(self.app.palette)
        self.update_snapshot(self.app.latest_snapshot)
    def set_arduino_status(self, connected: bool, message: str = "") -> None:
        prefix = "Arduino: подключена" if connected else "Arduino: нет подключения"
        self.status_label.configure(text=f"{prefix}. {message}" if message else prefix)
    def _initial_sensor_text(self, sensor: dict) -> str:
        if self.app.device_config.is_extra_sensor(sensor):
            latest = {item.sensor_id: item for item in self.app.extra_history.latest_values()}
            value = latest.get(str(sensor.get("sensor_uid", "")))
            return self._format_extra_value(value) if value else self.app.device_config.format_sensor_value(sensor, None)
        return self.app.device_config.format_sensor_value(sensor, None)
    def _format_extra_value(self, value: ExtraSensorValue | None) -> str:
        if value is None:
            return "--"
        suffix = f" {value.unit}" if value.unit else ""
        number = value.as_float()
        if number is not None:
            return f"{number:g}{suffix}"
        return f"{value.value}{suffix}"
