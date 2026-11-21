from __future__ import annotations
import tkinter as tk
from tkinter import ttk
from fitodomik_app.core.models import ExtraSensorValue
from fitodomik_app.ui.style_names import ACCENT_BUTTON_STYLE, APP_FRAME_STYLE, CARD_FRAME_STYLE, COMBOBOX_SELECTED_EVENT, MUTED_LABEL_STYLE, SURFACE_LABEL_STYLE
from fitodomik_app.ui.widgets import Card, ScrollableFrame
class ExtraDevicesTab(ttk.Frame):
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.selected_sensor = tk.StringVar(value="")
        self.value_labels: dict[str, ttk.Label] = {}
        self.status_labels: dict[str, ttk.Label] = {}
        self._build()
    def _build(self) -> None:
        top = ttk.Frame(self, style=APP_FRAME_STYLE)
        top.pack(fill="x", padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        ttk.Label(top, text="Доп. устройства", style="Title.TLabel").pack(side="left")
        ttk.Button(top, text="Настройки датчиков", command=self.app.open_extra_sensors_settings).pack(side="right", padx=self.app.pad(4))
        ttk.Button(top, text="Проверить", style=ACCENT_BUTTON_STYLE, command=self.app.check_extra_sensor_now).pack(side="right", padx=self.app.pad(4))
        ttk.Button(top, text="Обновить", command=self.refresh).pack(side="right", padx=self.app.pad(4))
        self.body = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.body.pack(fill="both", expand=True, padx=self.app.pad(18), pady=(self.app.pad(4), self.app.pad(18)))
        self.body.columnconfigure(0, weight=1)
        self.body.columnconfigure(1, weight=1)
        self.body.rowconfigure(0, weight=1)
        left = Card(self.body, "Подключённые датчики", self.app.palette, padding=self.app.pad(10))
        left.grid(row=0, column=0, sticky="nsew", padx=(0, self.app.pad(6)))
        self.scroll = ScrollableFrame(left, self.app.palette, canvas_bg=self.app.palette.surface, inner_style=CARD_FRAME_STYLE)
        self.scroll.pack(fill="both", expand=True)
        self.right_card = Card(self.body, "Локальный график", self.app.palette, padding=self.app.pad(10))
        self.right_card.grid(row=0, column=1, sticky="nsew", padx=(self.app.pad(6), 0))
        self.right_card.bind("<Configure>", lambda _event: self._refresh_message_wrap())
        selector = ttk.Frame(self.right_card, style=CARD_FRAME_STYLE)
        selector.pack(fill="x", pady=(0, self.app.pad(6)))
        ttk.Label(selector, text="Датчик:", style=SURFACE_LABEL_STYLE).pack(side="left")
        self.sensor_combo = ttk.Combobox(selector, textvariable=self.selected_sensor, values=[], state="readonly", width=26)
        self.sensor_combo.pack(side="left", fill="x", expand=True, padx=self.app.pad(6))
        self.sensor_combo.bind(COMBOBOX_SELECTED_EVENT, lambda _event: self.update_graph())
        self.graph = tk.Canvas(self.right_card, height=self.app.sy(210, minimum=180, maximum=320), bg=self.app.palette.surface, highlightthickness=1, highlightbackground=self.app.palette.border)
        self.graph.pack(fill="both", expand=True)
        self.message = ttk.Label(self.right_card, text="Данные появятся после строки EXTRA_SENSORS\nот Arduino", style=MUTED_LABEL_STYLE, wraplength=self._message_wraplength(), justify="left")
        self.message.pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
        self.refresh()
    def on_show(self) -> None:
        self.refresh()
    def refresh(self) -> None:
        for child in self.scroll.inner.winfo_children():
            child.destroy()
        self.value_labels.clear()
        self.status_labels.clear()
        configs = self.app.extra_config.sensors()
        latest = {item.sensor_id: item for item in self.app.extra_history.latest_values()}
        if not configs:
            ttk.Label(self.scroll.inner, text="Дополнительные датчики не добавлены. Откройте настройки датчиков.", style=MUTED_LABEL_STYLE, wraplength=520).pack(anchor="w", pady=self.app.pad(8))
        for config in configs:
            sensor_id = str(config.get("id", ""))
            latest_value = latest.get(sensor_id)
            self._sensor_row(config, latest_value)
        combo_values = [str(item.get("id", "")) for item in configs if item.get("id")]
        self.sensor_combo.configure(values=combo_values)
        if combo_values and self.selected_sensor.get() not in combo_values:
            self.selected_sensor.set(combo_values[0])
        self.update_graph()
    def update_values(self, values: list[ExtraSensorValue]) -> None:
        for value in values:
            if value.sensor_id in self.value_labels:
                self.value_labels[value.sensor_id].configure(text=self._format_value(value))
            if value.sensor_id in self.status_labels:
                self.status_labels[value.sensor_id].configure(text=value.status)
        self.update_graph()
    def _sensor_row(self, config: dict, latest: ExtraSensorValue | None) -> None:
        sensor_id = str(config.get("id", ""))
        card = Card(self.scroll.inner, "", self.app.palette, padding=self.app.pad(8))
        card.pack(fill="x", pady=self.app.pad(4))
        header = ttk.Frame(card, style=CARD_FRAME_STYLE)
        header.pack(fill="x")
        ttk.Label(header, text=str(config.get("name", sensor_id)), style=SURFACE_LABEL_STYLE, font=self.app.font_tuple(10, "bold", maximum=13)).pack(side="left", fill="x", expand=True)
        if latest:
            status_text = latest.status
        elif not config.get("enabled", True):
            status_text = "отключён"
        else:
            status_text = "нет данных"
        status = ttk.Label(header, text=status_text, style=MUTED_LABEL_STYLE)
        status.pack(side="right")
        self.status_labels[sensor_id] = status
        meta = f"{config.get('connection', '')} · {config.get('pin', '')} · {config.get('type', '')}"
        ttk.Label(card, text=meta, style=MUTED_LABEL_STYLE).pack(anchor="w", pady=(self.app.pad(2), 0))
        value_label = ttk.Label(card, text=self._format_value(latest) if latest else "--", style=SURFACE_LABEL_STYLE, font=self.app.font_tuple(18, "bold", maximum=26))
        value_label.pack(anchor="w", pady=(self.app.pad(4), 0))
        self.value_labels[sensor_id] = value_label
    def update_graph(self) -> None:
        self.graph.delete("all")
        sensor_id = self.selected_sensor.get()
        if not sensor_id:
            self._graph_message("Нет выбранного датчика")
            return
        points = [item for item in self.app.extra_history.series(sensor_id, hours=3) if item.as_float() is not None]
        if len(points) < 2:
            self._graph_message("Недостаточно данных для графика")
            return
        width = max(260, self.graph.winfo_width() or 360)
        height = max(160, self.graph.winfo_height() or 210)
        values = [float(item.as_float() or 0) for item in points]
        min_value = min(values)
        max_value = max(values)
        if max_value == min_value:
            max_value += 1
            min_value -= 1
        pad = 24
        coords: list[float] = []
        for index, value in enumerate(values):
            x = pad + (width - pad * 2) * index / max(1, len(values) - 1)
            y = height - pad - (height - pad * 2) * (value - min_value) / (max_value - min_value)
            coords.extend([x, y])
        self.graph.create_rectangle(1, 1, width - 2, height - 2, outline=self.app.palette.border)
        self.graph.create_line(*coords, fill=self.app.palette.accent, width=2, smooth=True)
        self.graph.create_text(pad, pad, anchor="w", fill=self.app.palette.muted, text=f"{max_value:g}")
        self.graph.create_text(pad, height - pad, anchor="w", fill=self.app.palette.muted, text=f"{min_value:g}")
    def _graph_message(self, text: str) -> None:
        width = max(260, self.graph.winfo_width() or 360)
        height = max(160, self.graph.winfo_height() or 210)
        self.graph.create_text(width / 2, height / 2, text=text, fill=self.app.palette.muted)
    def _format_value(self, value: ExtraSensorValue | None) -> str:
        if value is None:
            return "--"
        suffix = f" {value.unit}" if value.unit else ""
        number = value.as_float()
        if number is not None:
            return f"{number:g}{suffix}"
        return f"{value.value}{suffix}"
    def refresh_palette(self) -> None:
        if hasattr(self, "scroll"):
            self.scroll.set_palette(self.app.palette)
        if hasattr(self, "graph"):
            self.graph.configure(bg=self.app.palette.surface, highlightbackground=self.app.palette.border)
        self.update_graph()
    def refresh_scale(self) -> None:
        if hasattr(self, "graph"):
            self.graph.configure(height=self.app.sy(210, minimum=180, maximum=320))
        self._refresh_message_wrap()
    def _message_wraplength(self) -> int:
        if hasattr(self, "right_card") and self.right_card.winfo_exists():
            width = self.right_card.winfo_width()
            if width > 1:
                return max(220, min(420, width - self.app.pad(28)))
        return max(220, min(420, self.app.content_width() // 2 - self.app.pad(36)))
    def _refresh_message_wrap(self) -> None:
        if hasattr(self, "message") and self.message.winfo_exists():
            self.message.configure(wraplength=self._message_wraplength())
