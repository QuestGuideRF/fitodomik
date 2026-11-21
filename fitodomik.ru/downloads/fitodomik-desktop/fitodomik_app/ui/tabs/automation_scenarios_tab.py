from __future__ import annotations
import tkinter as tk
import uuid
from tkinter import ttk
from fitodomik_app.ui.style_names import ACCENT_BUTTON_STYLE, APP_FRAME_STYLE, MUTED_LABEL_STYLE, NEW_SCENARIO_TEXT, SURFACE_FRAME_STYLE, SURFACE_LABEL_STYLE, TAKE_PHOTO_TEXT
from fitodomik_app.ui.widgets import Card, ScrollableFrame
class AutomationScenariosTab(ttk.Frame):
    OPERATORS = ("<", ">", "=", "between", "not_between", "no_data", "time_between", "value", "device_offline")
    ACTIONS = ("relay_on", "relay_off", "relay_pulse", "take_photo", "run_analysis", "send_notification", "create_command", "write_event")
    OPERATOR_LABELS = {
        "<": "Меньше",
        ">": "Больше",
        "=": "Равно",
        "between": "Между",
        "not_between": "Не между",
        "no_data": "Нет данных",
        "time_between": "Время между",
        "value": "Значение",
        "device_offline": "Устройство offline",
    }
    ACTION_LABELS = {
        "relay_on": "Включить реле",
        "relay_off": "Выключить реле",
        "relay_pulse": "Импульс реле",
        "telegram": "Отправить уведомление",
        "notification": "Записать уведомление",
        "photo": TAKE_PHOTO_TEXT,
        "take_photo": TAKE_PHOTO_TEXT,
        "run_analysis": "Запустить анализ",
        "send_notification": "Записать уведомление",
        "create_command": "Создать команду",
        "write_event": "Записать событие",
    }
    COMMAND_LABELS = {
        "sync_device_config": "Синхронизировать конфигурацию",
        "request_device_config": "Запросить конфигурацию",
        "apply_device_config": "Применить конфигурацию",
        "take_photo": TAKE_PHOTO_TEXT,
        "analyze_plant": "Анализ растения",
        "refresh_thresholds": "Обновить пороги",
        "refresh_schedule": "Обновить расписание",
    }
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.vars: dict[str, tk.Variable] = {}
        self._build()
    def rebuild(self) -> None:
        for child in self.winfo_children():
            child.destroy()
        self.vars.clear()
        self._build()
    def _build(self) -> None:
        top = ttk.Frame(self, style=APP_FRAME_STYLE)
        top.pack(fill="x", padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        ttk.Label(top, text="Сценарии", style="Title.TLabel").pack(side="left")
        self.scroll = ScrollableFrame(self, self.app.palette)
        self.scroll.pack(fill="both", expand=True, padx=self.app.pad(18), pady=(self.app.pad(6), self.app.pad(18)))
        sensors = self.app.device_config.monitoring_sensors()
        relays = self.app.device_config.control_relays()
        if not sensors or not relays:
            card = Card(self.scroll.inner, "Нет доступных модулей", self.app.palette)
            card.pack(fill="x", pady=self.app.pad(6))
            ttk.Label(
                card,
                text="Сценарии появятся, когда в конфигурации есть хотя бы один датчик и одно реле.",
                style=MUTED_LABEL_STYLE,
                wraplength=720,
            ).pack(anchor="w", fill="x")
            return
        self._existing_section()
        self._create_section(sensors, relays)
    def _existing_section(self) -> None:
        card = Card(self.scroll.inner, "Созданные сценарии", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        scenarios = self._scenarios()
        if not scenarios:
            ttk.Label(card, text="Сценарии пока не добавлены", style=MUTED_LABEL_STYLE).pack(anchor="w")
            return
        sensor_labels = {str(item.get("sensor_uid")): self.app.device_config.sensor_label(item) for item in self.app.device_config.monitoring_sensors()}
        relay_labels = {str(item.get("relay_uid")): self.app.device_config.relay_label(item) for item in self.app.device_config.control_relays()}
        for scenario in scenarios:
            sid = str(scenario.get("scenario_id") or scenario.get("id") or "")
            row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
            row.pack(fill="x", pady=self.app.pad(4))
            enabled = tk.BooleanVar(value=bool(scenario.get("enabled", True)))
            ttk.Checkbutton(row, variable=enabled, command=lambda s=sid, v=enabled: self._toggle(s, v.get())).pack(side="left")
            condition = (scenario.get("conditions") or [{}])[0] if isinstance(scenario.get("conditions"), list) else {}
            action = (scenario.get("actions") or [{}])[0] if isinstance(scenario.get("actions"), list) else {}
            text = (
                f"{scenario.get('name', sid)} · "
                f"{sensor_labels.get(str(condition.get('sensor_uid')), condition.get('sensor_uid', 'датчик'))} "
                f"{self._operator_label(str(condition.get('operator', '')))} {condition.get('value', '')} -> "
                f"{self._action_label(str(action.get('type', '')))} "
                f"{relay_labels.get(str(action.get('relay_uid')), action.get('relay_uid', 'реле'))}"
            )
            ttk.Label(row, text=text, style=SURFACE_LABEL_STYLE, wraplength=700).pack(side="left", fill="x", expand=True, padx=self.app.pad(6))
            ttk.Button(row, text="Удалить", command=lambda s=sid: self._delete(s)).pack(side="left")
    def _create_section(self, sensors: list[dict], relays: list[dict]) -> None:
        card = Card(self.scroll.inner, NEW_SCENARIO_TEXT, self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        sensor_labels = [self.app.device_config.sensor_label(item) for item in sensors]
        relay_labels = [self.app.device_config.relay_label(item) for item in relays]
        self.sensor_map = dict(zip(sensor_labels, sensors))
        self.relay_map = dict(zip(relay_labels, relays))
        self.vars["name"] = tk.StringVar(value=NEW_SCENARIO_TEXT)
        self.vars["sensor"] = tk.StringVar(value=sensor_labels[0])
        self.vars["operator"] = tk.StringVar(value=self._operator_label("<"))
        self.vars["logic"] = tk.StringVar(value="AND")
        self.vars["value"] = tk.StringVar(value="30")
        self.vars["value_to"] = tk.StringVar(value="")
        self.vars["duration_seconds"] = tk.StringVar(value="300")
        self.vars["relay"] = tk.StringVar(value=relay_labels[0])
        self.vars["action"] = tk.StringVar(value=self._action_label("relay_pulse"))
        self.vars["duration"] = tk.StringVar(value="10")
        self.vars["cooldown"] = tk.StringVar(value="1800")
        self.vars["command_type"] = tk.StringVar(value=self._command_label("sync_device_config"))
        self._entry(card, "Название", "name")
        self._combo(card, "Датчик", "sensor", sensor_labels)
        self._combo(card, "Условие", "operator", [self._operator_label(item) for item in self.OPERATORS])
        self._combo(card, "Связь условий", "logic", ["AND", "OR"])
        self._entry(card, "Значение", "value")
        self._entry(card, "Верхняя граница", "value_to")
        self._entry(card, "Длительность условия, сек", "duration_seconds")
        self._combo(card, "Реле", "relay", relay_labels)
        self._combo(card, "Действие", "action", [self._action_label(item) for item in self.ACTIONS])
        self._entry(card, "Длительность импульса, сек", "duration")
        self._entry(card, "Cooldown, сек", "cooldown")
        self._combo(card, "Команда устройству", "command_type", [self._command_label(item) for item in self.COMMAND_LABELS])
        self.preview_label = ttk.Label(card, text="", style=MUTED_LABEL_STYLE, wraplength=720)
        self.preview_label.pack(anchor="w", fill="x", pady=self.app.pad(5))
        for var in self.vars.values():
            try:
                var.trace_add("write", lambda *_args: self._refresh_preview())
            except Exception:
                pass
        self._refresh_preview()
        ttk.Button(card, text="Создать сценарий", style=ACCENT_BUTTON_STYLE, command=self._create).pack(anchor="w", pady=self.app.pad(6))
    def _entry(self, parent, label: str, key: str) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(3))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=26).pack(side="left")
        ttk.Entry(row, textvariable=self.vars[key]).pack(side="left", fill="x", expand=True)
    def _combo(self, parent, label: str, key: str, values: list[str]) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(3))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=26).pack(side="left")
        ttk.Combobox(row, textvariable=self.vars[key], values=values, state="readonly").pack(side="left", fill="x", expand=True)
    def _create(self) -> None:
        sensor = self.sensor_map[self.vars["sensor"].get()]
        relay = self.relay_map[self.vars["relay"].get()]
        scenario = {
            "scenario_id": "scenario_" + uuid.uuid4().hex[:10],
            "name": self.vars["name"].get().strip() or NEW_SCENARIO_TEXT,
            "enabled": True,
            "conditions": [
                {
                    "sensor_uid": str(sensor.get("sensor_uid")),
                    "operator": self._operator_value(self.vars["operator"].get()),
                    "logic": self.vars["logic"].get(),
                    "value": self._number(self.vars["value"].get(), 0),
                    "value_to": self._number(self.vars["value_to"].get(), None),
                    "duration_seconds": max(0, int(self._number(self.vars["duration_seconds"].get(), 0) or 0)),
                }
            ],
            "actions": [
                {
                    "type": self._action_value(self.vars["action"].get()),
                    "relay_uid": str(relay.get("relay_uid")),
                    "duration_seconds": max(1, int(self._number(self.vars["duration"].get(), 10) or 10)),
                    "command_type": self._command_value(self.vars["command_type"].get()),
                }
            ],
            "cooldown_seconds": max(60, int(self._number(self.vars["cooldown"].get(), 1800) or 0)),
        }
        scenarios = self._scenarios()
        scenarios.append(scenario)
        self.app.settings.set("automation_scenarios", scenarios, save=True)
        self.app._after_device_config_changed("Сценарий добавлен")
        self.rebuild()
    def _toggle(self, scenario_id: str, enabled: bool) -> None:
        scenarios = self._scenarios()
        for scenario in scenarios:
            if str(scenario.get("scenario_id") or scenario.get("id")) == scenario_id:
                scenario["enabled"] = bool(enabled)
        self.app.settings.set("automation_scenarios", scenarios, save=True)
        self.app._after_device_config_changed("Сценарий обновлён")
    def _delete(self, scenario_id: str) -> None:
        scenarios = [item for item in self._scenarios() if str(item.get("scenario_id") or item.get("id")) != scenario_id]
        self.app.settings.set("automation_scenarios", scenarios, save=True)
        self.app._after_device_config_changed("Сценарий удалён")
        self.rebuild()
    def _scenarios(self) -> list[dict]:
        value = self.app.settings.get("automation_scenarios", [])
        return [dict(item) for item in value] if isinstance(value, list) else []
    def _number(self, value: str, default):
        try:
            return float(value)
        except (TypeError, ValueError):
            return default
    def _refresh_preview(self) -> None:
        if not hasattr(self, "preview_label"):
            return
        sensor = self.vars.get("sensor", tk.StringVar(value="датчик")).get()
        operator = self.vars.get("operator", tk.StringVar(value="<")).get()
        value = self.vars.get("value", tk.StringVar(value="")).get()
        duration = self.vars.get("duration_seconds", tk.StringVar(value="0")).get()
        action_label = self.vars.get("action", tk.StringVar(value="")).get()
        action = self._action_value(action_label)
        relay = self.vars.get("relay", tk.StringVar(value="реле")).get()
        pulse = self.vars.get("duration", tk.StringVar(value="")).get()
        text = f"Предпросмотр: если {sensor} {operator} {value}"
        if duration and duration != "0":
            text += f" в течение {duration} сек"
        text += f", выполнить {action_label}"
        if action.startswith("relay"):
            text += f" для {relay}"
            if action == "relay_pulse":
                text += f" на {pulse} сек"
        self.preview_label.configure(text=text)
    def _operator_label(self, value: str) -> str:
        return self.OPERATOR_LABELS.get(value, value)
    def _operator_value(self, label: str) -> str:
        reverse = {value: key for key, value in self.OPERATOR_LABELS.items()}
        return reverse.get(label, label)
    def _action_label(self, value: str) -> str:
        return self.ACTION_LABELS.get(value, value)
    def _action_value(self, label: str) -> str:
        reverse = {value: key for key, value in self.ACTION_LABELS.items()}
        return reverse.get(label, label)
    def _command_label(self, value: str) -> str:
        return self.COMMAND_LABELS.get(value, value)
    def _command_value(self, label: str) -> str:
        reverse = {value: key for key, value in self.COMMAND_LABELS.items()}
        return reverse.get(label, label)
    def refresh_palette(self) -> None:
        if hasattr(self, "scroll"):
            self.scroll.set_palette(self.app.palette)
    def refresh_scale(self) -> None:
        if hasattr(self, "scroll"):
            self.scroll.pack_configure(padx=self.app.pad(18), pady=(self.app.pad(6), self.app.pad(18)))
