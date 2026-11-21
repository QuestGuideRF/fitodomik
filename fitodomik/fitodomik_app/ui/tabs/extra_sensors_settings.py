from __future__ import annotations
from dataclasses import dataclass, field
from datetime import datetime
import threading
import tkinter as tk
from pathlib import Path
from tkinter import filedialog, messagebox, ttk
from typing import Any
from fitodomik_app.services.extra_sensors import ExtraSensorDefinition
from fitodomik_app.ui.style_names import (
    ACCENT_BUTTON_STYLE,
    ADD_SENSOR_TEXT,
    ADVANCED_SETTINGS_TEXT,
    ALL_FILES_LABEL,
    APP_FRAME_STYLE,
    BACK_TEXT,
    COMBOBOX_SELECTED_EVENT,
    MUTED_LABEL_STYLE,
    SURFACE_FRAME_STYLE,
    SURFACE_LABEL_STYLE,
)
from fitodomik_app.ui.widgets import Card, ScrollableFrame
@dataclass(slots=True)
class AddSensorWizardState:
    connection_type: str = "analog"
    board: str = "arduino_nano"
    pin: str = ""
    address: str = "0x76"
    profile_id: str = ""
    sensor_type: str = ""
    sensor_name: str = ""
    sensor_uid: str = ""
    unit: str = "raw"
    calibration: dict[str, Any] = field(default_factory=dict)
    show_in_monitoring: bool = True
    show_in_charts: bool = True
    send_to_server: bool = False
    enabled: bool = True
    library_required: bool = False
    compile_ok: bool = False
    upload_ok: bool = False
    test_ok: bool = False
    staged_config: bool = False
    saved: bool = False
class ExtraSensorsSettingsView(ttk.Frame):
    CONNECTIONS = ("analog", "digital", "i2c", "uart", "spi")
    def __init__(self, parent, app, on_back) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.on_back = on_back
        self.board_var = tk.StringVar(value=self.app.extra_config.board_id())
        self.connection_var = tk.StringVar(value="analog")
        self.pin_var = tk.StringVar(value="")
        self.sensor_var = tk.StringVar(value="")
        self.name_var = tk.StringVar(value="")
        self.delete_var = tk.StringVar(value="")
        self.status_var = tk.StringVar(value="")
        self.backup_var = tk.StringVar(value="")
        self.remote_profile_var = tk.StringVar(value="")
        self.send_to_server_var = tk.BooleanVar(value=False)
        self.show_monitoring_var = tk.BooleanVar(value=True)
        self.show_charts_var = tk.BooleanVar(value=True)
        self.wrap_labels: list[ttk.Label] = []
        self.wizard_view: AddSensorWizardFrame | None = None
        self.relay_wizard_view: AddRelayWizardFrame | None = None
        self.screen_holder: ttk.Frame | None = None
        self.content: ttk.Frame | None = None
        self.firmware_log_text: tk.Text | None = None
        self.remote_profiles: list[dict[str, Any]] = []
        self._build()
    def _build(self) -> None:
        top = ttk.Frame(self, style=APP_FRAME_STYLE)
        top.pack(fill="x", padx=self.app.pad(4), pady=(0, self.app.pad(8)))
        ttk.Button(top, text=BACK_TEXT, command=self.on_back).pack(side="left")
        ttk.Label(top, text="Сторонние датчики", style="Title.TLabel").pack(side="left", padx=self.app.pad(12))
        ttk.Button(top, text=ADD_SENSOR_TEXT, style=ACCENT_BUTTON_STYLE, command=self.show_wizard).pack(side="right", padx=self.app.pad(3))
        ttk.Button(top, text="Добавить реле", command=self.show_relay_wizard).pack(side="right", padx=self.app.pad(3))
        ttk.Button(top, text=ADVANCED_SETTINGS_TEXT, command=self.show_advanced_settings).pack(side="right", padx=self.app.pad(3))
        ttk.Button(top, text="Сохранить", style=ACCENT_BUTTON_STYLE, command=self.save).pack(side="right")
        self.screen_holder = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.screen_holder.pack(fill="both", expand=True)
        self.show_advanced_settings()
    def show_advanced_settings(self) -> None:
        if not self.screen_holder:
            return
        if self.wizard_view and self.wizard_view.winfo_exists() and self.wizard_view.has_unsaved_stage():
            if not messagebox.askyesno(ADVANCED_SETTINGS_TEXT, "Мастер временно изменил локальную конфигурацию. Вернуть исходное состояние и открыть расширенные настройки?"):
                return
            self.wizard_view.restore_original_sensors()
        self._clear_screen()
        self.wizard_view = None
        self.relay_wizard_view = None
        self.content = ttk.Frame(self.screen_holder, style=APP_FRAME_STYLE)
        self.content.pack(fill="both", expand=True)
        self.content.bind("<Configure>", self._refresh_wraplengths)
        self._board_section()
        self._ports_section()
        self._configured_section()
        self._add_section()
        self._library_section()
        self._profile_section()
        self._firmware_section()
        self._wrap_label(self.content, textvariable=self.status_var, style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(8))
        self.refresh_all()
    def show_wizard(self) -> None:
        if not self.screen_holder:
            return
        self._clear_screen()
        self.wizard_view = AddSensorWizardFrame(self.screen_holder, self.app, self)
        self.wizard_view.pack(fill="both", expand=True)
    def show_relay_wizard(self) -> None:
        if not self.screen_holder:
            return
        self._clear_screen()
        self.wizard_view = None
        self.relay_wizard_view = AddRelayWizardFrame(self.screen_holder, self.app, self)
        self.relay_wizard_view.pack(fill="both", expand=True)
    def _clear_screen(self) -> None:
        if not self.screen_holder:
            return
        for child in self.screen_holder.winfo_children():
            child.destroy()
    def _board_section(self) -> None:
        card = Card(self.content, "Плата Arduino", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text="Выбранная плата", style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        values = list(self.app.extra_registry.BOARDS)
        labels = [self.app.extra_registry.BOARDS[item].name for item in values]
        self.board_ids = values
        self.board_combo = ttk.Combobox(row, values=labels, state="readonly", width=32)
        current_index = values.index(self.board_var.get()) if self.board_var.get() in values else 0
        self.board_combo.current(current_index)
        self.board_combo.pack(side="left", fill="x", expand=True)
        self.board_combo.bind(COMBOBOX_SELECTED_EVENT, lambda _event: self._board_changed())
        self.manual_fqbn_var = tk.StringVar(value=str(self.app.settings.get("extra_sensors_manual_fqbn", "")))
        fqbn = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        fqbn.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(fqbn, text="Ручной FQBN", style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        ttk.Entry(fqbn, textvariable=self.manual_fqbn_var).pack(side="left", fill="x", expand=True)
    def _ports_section(self) -> None:
        self.ports_card = Card(self.content, "Порты", self.app.palette)
        self.ports_card.pack(fill="x", pady=self.app.pad(6))
        self.summary_label = self._wrap_label(self.ports_card, text="", style=SURFACE_LABEL_STYLE)
        self.summary_label.pack(anchor="w", fill="x", pady=(0, self.app.pad(6)))
        self.occupied_label = self._wrap_label(self.ports_card, text="", style=MUTED_LABEL_STYLE)
        self.occupied_label.pack(anchor="w", fill="x", pady=self.app.pad(2))
        self.free_label = self._wrap_label(self.ports_card, text="", style=MUTED_LABEL_STYLE)
        self.free_label.pack(anchor="w", fill="x", pady=self.app.pad(2))
    def _configured_section(self) -> None:
        card = Card(self.content, "Добавленные датчики", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self.configured_frame = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        self.configured_frame.pack(fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=(self.app.pad(8), 0))
        ttk.Label(row, text="Удалить", style=SURFACE_LABEL_STYLE, width=12).pack(side="left")
        self.delete_combo = ttk.Combobox(row, textvariable=self.delete_var, values=[], state="readonly", width=28)
        self.delete_combo.pack(side="left", fill="x", expand=True)
        ttk.Button(row, text="Удалить датчик", command=self.delete_selected).pack(side="left", padx=self.app.pad(4))
    def _add_section(self) -> None:
        card = Card(self.content, ADD_SENSOR_TEXT, self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._combo_row(card, "Тип порта", self.connection_var, list(self.CONNECTIONS), self._connection_changed)
        self.pin_combo = self._combo_row(card, "Пин", self.pin_var, [], None)
        self.sensor_combo = self._combo_row(card, "Тип датчика", self.sensor_var, [], self._sensor_changed)
        self._entry_row(card, "Название", self.name_var)
        flags = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        flags.pack(fill="x", pady=self.app.pad(4))
        ttk.Checkbutton(flags, text="Показывать в мониторинге", variable=self.show_monitoring_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Показывать в графиках", variable=self.show_charts_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Отправлять на сайт", variable=self.send_to_server_var).pack(side="left", padx=self.app.pad(3))
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Button(row, text=ADD_SENSOR_TEXT, style=ACCENT_BUTTON_STYLE, command=self.add_sensor).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Проверить датчик", command=self.app.check_extra_sensor_now).pack(side="left", padx=self.app.pad(3))
        self.sensor_info = self._wrap_label(card, text="", style=MUTED_LABEL_STYLE)
        self.sensor_info.pack(anchor="w", fill="x", pady=(self.app.pad(4), 0))
    def _library_section(self) -> None:
        card = Card(self.content, "Arduino-библиотеки", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(card, text="Библиотеки копируются локально и не выполняются в Python.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(5))
        ttk.Button(row, text="Выбрать библиотеку", command=self.choose_library).pack(side="left")
        self.library_list = self._wrap_label(card, text="", style=MUTED_LABEL_STYLE)
        self.library_list.pack(anchor="w", fill="x", pady=(self.app.pad(4), 0))
    def _profile_section(self) -> None:
        card = Card(self.content, "Цифровые профили", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(card, text="JSON-профили описывают C++ шаблоны и проверяются как данные, без выполнения Python-кода.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(5))
        ttk.Button(row, text="Импорт профиля JSON", command=self.choose_profile).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Проверить профиль", command=self.validate_profile).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Каталог с сайта", command=self.load_remote_profiles).pack(side="left", padx=self.app.pad(3))
        remote = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        remote.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(remote, text="Профиль с сайта", style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
        self.remote_profile_combo = ttk.Combobox(remote, textvariable=self.remote_profile_var, values=[], state="readonly")
        self.remote_profile_combo.pack(side="left", fill="x", expand=True)
        ttk.Button(remote, text="Скачать", command=self.download_remote_profile).pack(side="left", padx=self.app.pad(3))
        ttk.Button(remote, text="Отправить первый пользовательский", command=self.upload_selected_user_profile).pack(side="left", padx=self.app.pad(3))
        self.profile_list = self._wrap_label(card, text="", style=MUTED_LABEL_STYLE)
        self.profile_list.pack(anchor="w", fill="x", pady=(self.app.pad(4), 0))
    def _firmware_section(self) -> None:
        card = Card(self.content, "Прошивка", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(card, text="Перед изменением fitodomik.ino создаётся backup. Загрузка в Arduino требует подтверждения.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(5))
        ttk.Button(row, text="Сгенерировать код", command=self.app.generate_extra_sensor_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Собрать прошивку", command=self.compile_firmware).pack(side="left", padx=self.app.pad(3))
        row2 = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row2.pack(fill="x", pady=(0, self.app.pad(5)))
        ttk.Button(row2, text="Загрузить в Arduino", style=ACCENT_BUTTON_STYLE, command=self.upload_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row2, text="Откатить прошивку", command=self.rollback_firmware).pack(side="left", padx=self.app.pad(3))
        backup_row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        backup_row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(backup_row, text="Backup", style=SURFACE_LABEL_STYLE, width=12).pack(side="left")
        self.backup_combo = ttk.Combobox(backup_row, textvariable=self.backup_var, values=[], state="readonly")
        self.backup_combo.pack(side="left", fill="x", expand=True)
        log_frame = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        log_frame.pack(fill="x", pady=(self.app.pad(6), 0))
        scrollbar = ttk.Scrollbar(log_frame, orient="vertical")
        self.firmware_log_text = tk.Text(
            log_frame,
            height=6,
            wrap="word",
            state="disabled",
            relief="flat",
            borderwidth=1,
            highlightthickness=1,
            yscrollcommand=scrollbar.set,
        )
        scrollbar.configure(command=self.firmware_log_text.yview)
        self._configure_firmware_log()
        self.firmware_log_text.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        self._append_firmware_log("Лог сборки появится здесь.")
    def refresh_all(self) -> None:
        self._refresh_ports()
        self._refresh_configured()
        self._refresh_libraries()
        self._refresh_profiles()
        self._refresh_backups()
        self._connection_changed()
    def save(self) -> None:
        if self.wizard_view and self.wizard_view.winfo_exists():
            self.status_var.set("В мастере датчик сохраняется на последнем шаге")
            return
        board_id = self.board_ids[self.board_combo.current()] if self.board_combo.current() >= 0 else "arduino_nano"
        self.app.extra_config.set_board(board_id)
        self.app.settings.set("extra_sensors_manual_fqbn", self.manual_fqbn_var.get().strip(), save=True)
        self.status_var.set("Настройки сторонних датчиков сохранены")
        self.refresh_all()
    def add_sensor(self) -> None:
        sensor_id = self.sensor_var.get()
        pin = self.pin_var.get()
        connection = self.connection_var.get()
        ok, message = self.app.extra_config.add_sensor(
            sensor_id,
            self.name_var.get(),
            connection,
            pin,
            send_to_server=self.send_to_server_var.get(),
            show_in_monitoring=self.show_monitoring_var.get(),
            show_in_charts=self.show_charts_var.get(),
        )
        self.status_var.set(message)
        if ok:
            self.name_var.set("")
            self.refresh_all()
            self.app.refresh_extra_devices_tab()
    def delete_selected(self) -> None:
        sensor_id = self.delete_var.get()
        if not sensor_id:
            self.status_var.set("Выберите датчик для удаления")
            return
        ok, message = self.app.extra_config.remove_sensor(sensor_id)
        self.status_var.set(message)
        if ok:
            self.refresh_all()
            self.app.refresh_extra_devices_tab()
    def choose_library(self) -> None:
        path = filedialog.askopenfilename(
            title="Выберите Arduino-библиотеку",
            filetypes=[("Arduino library", "*.zip *.h *.cpp"), (ALL_FILES_LABEL, "*.*")],
        )
        if not path:
            directory = filedialog.askdirectory(title="Или выберите папку библиотеки")
            path = directory or ""
        if not path:
            return
        result = self.app.import_extra_sensor_library(Path(path))
        text = result.message
        if result.warnings:
            text += ": " + "; ".join(result.warnings)
        self.status_var.set(text)
        self._refresh_libraries()
        if result.destination and result.status in {"ok", "warning"}:
            if messagebox.askyesno("Arduino libraries", "Скопировать библиотеку также в стандартную папку Arduino libraries?"):
                install = self.app.install_extra_sensor_library_to_arduino(result.destination)
                install_text = install.message
                if install.warnings:
                    install_text += ": " + "; ".join(install.warnings)
                self.status_var.set(install_text)
    def choose_profile(self) -> None:
        path = filedialog.askopenfilename(
            title="Выберите JSON-профиль датчика",
            filetypes=[("Sensor profile", "*.json"), (ALL_FILES_LABEL, "*.*")],
        )
        if not path:
            return
        result = self.app.import_sensor_profile(Path(path))
        text = result.message
        if result.validation and result.validation.errors:
            text += ": " + "; ".join(result.validation.errors)
        elif result.validation and result.validation.warnings:
            text += ": " + "; ".join(result.validation.warnings)
        self.status_var.set(text)
        self._refresh_profiles()
    def validate_profile(self) -> None:
        path = filedialog.askopenfilename(
            title="Проверить JSON-профиль датчика",
            filetypes=[("Sensor profile", "*.json"), (ALL_FILES_LABEL, "*.*")],
        )
        if not path:
            return
        validation = self.app.sensor_profiles.validate_file(Path(path))
        if validation.ok:
            text = "Профиль корректен"
            if validation.warnings:
                text += ": " + "; ".join(validation.warnings)
        else:
            text = "Ошибки профиля: " + "; ".join(validation.errors)
        self.status_var.set(text)
    def load_remote_profiles(self) -> None:
        self.status_var.set("Загружаю каталог профилей с сайта...")
        def worker() -> None:
            ok, profiles, message = self.app.api.list_sensor_profiles()
            self.after(0, lambda: self._remote_profiles_loaded(ok, profiles, message))
        threading.Thread(target=worker, daemon=True).start()
    def _remote_profiles_loaded(self, ok: bool, profiles: list[dict[str, Any]], message: str) -> None:
        self.remote_profiles = profiles if ok else []
        labels = [f"{item.get('title') or item.get('profile_id')} · {item.get('profile_id')}" for item in self.remote_profiles]
        if hasattr(self, "remote_profile_combo"):
            self.remote_profile_combo.configure(values=labels)
            if labels:
                self.remote_profile_combo.current(0)
        if ok:
            self.status_var.set(f"Каталог профилей загружен: {len(self.remote_profiles)} шт.")
            self._refresh_profiles()
        else:
            self.status_var.set(message)
    def download_remote_profile(self) -> None:
        if not self.remote_profiles:
            self.status_var.set("Сначала загрузите каталог профилей")
            return
        index = self.remote_profile_combo.current() if hasattr(self, "remote_profile_combo") else -1
        if index < 0:
            self.status_var.set("Выберите профиль с сайта")
            return
        profile_id = str(self.remote_profiles[index].get("profile_id", ""))
        self.status_var.set(f"Скачиваю профиль {profile_id}...")
        def worker() -> None:
            ok, profile, message = self.app.api.download_sensor_profile(profile_id)
            if ok and profile:
                result = self.app.sensor_profiles.save_profile(profile, overwrite=True)
                ok = result.status == "ok"
                message = result.message
            self.after(0, lambda: self._remote_profile_downloaded(ok, message))
        threading.Thread(target=worker, daemon=True).start()
    def _remote_profile_downloaded(self, ok: bool, message: str) -> None:
        self.status_var.set(message if ok else f"Профиль не скачан: {message}")
        if ok:
            self._refresh_profiles()
    def upload_selected_user_profile(self) -> None:
        profiles = [item for item in self.app.sensor_profiles.profiles() if item.get("_source") == "user"]
        if not profiles:
            self.status_var.set("Нет пользовательских профилей для отправки")
            return
        profile = profiles[0]
        profile_id = str(profile.get("profile_id", ""))
        if not messagebox.askyesno("Отправить профиль", f"Отправить профиль {profile_id} в личный каталог сайта?"):
            return
        self.status_var.set("Отправляю профиль на сайт...")
        def worker() -> None:
            clean_profile = {key: value for key, value in profile.items() if not str(key).startswith("_")}
            ok, message = self.app.api.save_sensor_profile(clean_profile, is_public=False)
            self.after(0, lambda: self.status_var.set(message if ok else f"Не отправлено: {message}"))
        threading.Thread(target=worker, daemon=True).start()
    def compile_firmware(self) -> None:
        if not messagebox.askyesno("Сборка прошивки", "Запустить Arduino CLI compile для текущей прошивки?"):
            return
        self.app.compile_extra_sensor_firmware()
    def upload_firmware(self) -> None:
        if not messagebox.askyesno("Загрузка прошивки", "Arduino будет перепрошита. Продолжить?"):
            return
        self.app.upload_extra_sensor_firmware()
    def rollback_firmware(self) -> None:
        if not messagebox.askyesno("Откат прошивки", "Восстановить последний backup fitodomik.ino?"):
            return
        selected = self.backup_var.get()
        backup = next((item for item in self.app.list_firmware_backups() if item.name == selected), None)
        self.app.rollback_extra_sensor_firmware(backup.path if backup else None)
    def set_status(self, message: str) -> None:
        self.status_var.set(message)
        self._append_firmware_log(message)
        if self.wizard_view and self.wizard_view.winfo_exists():
            self.wizard_view.handle_status(message)
    def _board_changed(self) -> None:
        self.save()
    def _connection_changed(self) -> None:
        connection = self.connection_var.get()
        pins = self.app.extra_config.free_pins(connection)
        self.pin_combo.configure(values=pins)
        self.pin_var.set(pins[0] if pins else "")
        definitions = self.app.extra_registry.sensors_for_connection(connection)
        labels = [self._sensor_label(item) for item in definitions]
        self.sensor_defs = definitions
        self.sensor_combo.configure(values=labels)
        if labels:
            self.sensor_combo.current(0)
            self.sensor_var.set(definitions[0].sensor_id)
        else:
            self.sensor_var.set("")
        self._sensor_changed()
    def _sensor_changed(self) -> None:
        index = self.sensor_combo.current()
        if not hasattr(self, "sensor_defs") or index < 0 or index >= len(self.sensor_defs):
            self.sensor_info.configure(text="")
            return
        definition = self.sensor_defs[index]
        self.sensor_var.set(definition.sensor_id)
        pins = self.app.extra_config.free_pins(self.connection_var.get(), definition.required_pins)
        self.pin_combo.configure(values=pins)
        if pins and self.pin_var.get() not in pins:
            self.pin_var.set(pins[0])
        parts = [
            f"Шаблон: {definition.template_status}",
            f"Единица: {definition.unit}",
            f"Опрос: {definition.poll_interval_seconds} сек",
        ]
        if definition.required_pins > 1:
            parts.append(f"Пинов: {definition.required_pins}")
        if definition.library_required:
            parts.append(f"Нужна библиотека: {definition.library_name}")
        self.sensor_info.configure(text="; ".join(parts))
    def _refresh_ports(self) -> None:
        summary = self.app.extra_config.port_summary()
        self.summary_label.configure(
            text=(
                f"{summary['board'].name}: цифровых {summary['digital_occupied']}/{summary['digital_total']} занято, "
                f"свободно {summary['digital_free']}; аналоговых {summary['analog_occupied']}/{summary['analog_total']} занято, "
                f"свободно {summary['analog_free']}."
            )
        )
        occupied = ", ".join(f"{item.pin} ({item.label})" for item in summary["occupied"]) or "нет"
        free = f"Цифровые: {', '.join(summary['free_digital']) or 'нет'}; Аналоговые: {', '.join(summary['free_analog']) or 'нет'}"
        self.occupied_label.configure(text=f"Занятые порты: {occupied}")
        self.free_label.configure(text=f"Свободные порты: {free}")
    def _refresh_configured(self) -> None:
        for child in self.configured_frame.winfo_children():
            child.destroy()
        sensors = self.app.extra_config.sensors()
        ids = [str(item.get("id", "")) for item in sensors]
        self.delete_combo.configure(values=ids)
        self.delete_var.set(ids[0] if ids else "")
        if not sensors:
            ttk.Label(self.configured_frame, text="Дополнительные датчики пока не добавлены", style=MUTED_LABEL_STYLE).pack(anchor="w")
            return
        for item in sensors:
            flags = []
            if item.get("send_to_server", False):
                flags.append("сайт")
            if item.get("show_in_charts", True):
                flags.append("графики")
            text = f"{item.get('id')} · {item.get('name')} · {item.get('connection')} · {item.get('pin')} · {item.get('template_status', '')}"
            if flags:
                text += " · " + ", ".join(flags)
            self._wrap_label(self.configured_frame, text=text, style=SURFACE_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(2))
    def _refresh_libraries(self) -> None:
        if not hasattr(self, "library_list"):
            return
        libraries = self.app.extra_libraries.list_libraries()
        if not libraries:
            self.library_list.configure(text="Пользовательские библиотеки пока не добавлены")
            return
        parts = []
        for item in libraries[:12]:
            suffix = " · " + "; ".join(item["warnings"]) if item["warnings"] else ""
            parts.append(f"{item['name']} ({item['status']}){suffix}")
        self.library_list.configure(text="Установлено: " + " | ".join(parts))
    def _refresh_profiles(self) -> None:
        if not hasattr(self, "profile_list"):
            return
        profiles = self.app.sensor_profiles.profiles()
        if not profiles:
            self.profile_list.configure(text="Профили не найдены")
            return
        parts = []
        for profile in profiles[:16]:
            source = profile.get("_source", "")
            errors = profile.get("_errors", [])
            status = "ошибка" if errors else profile.get("template_status", "")
            parts.append(f"{profile.get('name')} · {profile.get('profile_id')} · {status} · {source}")
        self.profile_list.configure(text="Профили: " + " | ".join(parts))
    def _refresh_backups(self) -> None:
        if not hasattr(self, "backup_combo"):
            return
        backups = self.app.list_firmware_backups()
        names = [item.name for item in backups]
        self.backup_combo.configure(values=names)
        self.backup_var.set(names[0] if names else "")
    def _combo_row(self, parent, label: str, variable: tk.StringVar, values: list[str], callback) -> ttk.Combobox:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        combo = ttk.Combobox(row, textvariable=variable, values=values, state="readonly", width=32)
        combo.pack(side="left", fill="x", expand=True)
        if callback:
            combo.bind(COMBOBOX_SELECTED_EVENT, lambda _event: callback())
        return combo
    def _entry_row(self, parent, label: str, variable: tk.StringVar) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        ttk.Entry(row, textvariable=variable).pack(side="left", fill="x", expand=True)
    def _sensor_label(self, definition: ExtraSensorDefinition) -> str:
        return f"{definition.name} ({definition.template_status})"
    def _wraplength(self) -> int:
        width = self.content.winfo_width() if self.content is not None else 0
        if width <= 1:
            width = self.app.content_width()
        return max(260, min(720, width - self.app.pad(52)))
    def _wrap_label(self, parent, **kwargs) -> ttk.Label:
        label = ttk.Label(parent, wraplength=self._wraplength(), **kwargs)
        self.wrap_labels.append(label)
        return label
    def _refresh_wraplengths(self, _event=None) -> None:
        wraplength = self._wraplength()
        alive: list[ttk.Label] = []
        for label in self.wrap_labels:
            try:
                if label.winfo_exists():
                    label.configure(wraplength=wraplength)
                    alive.append(label)
            except tk.TclError:
                pass
        self.wrap_labels = alive
    def refresh_scale(self) -> None:
        self._refresh_wraplengths()
        self._configure_firmware_log()
    def _append_firmware_log(self, message: str) -> None:
        if not message or not self.firmware_log_text or not self.firmware_log_text.winfo_exists():
            return
        self.firmware_log_text.configure(state="normal")
        if self.firmware_log_text.get("1.0", "end-1c").strip() == "Лог сборки появится здесь.":
            self.firmware_log_text.delete("1.0", "end")
        self.firmware_log_text.insert("end", f"{datetime.now().strftime('%H:%M:%S')} {message}\n")
        self.firmware_log_text.see("end")
        self.firmware_log_text.configure(state="disabled")
    def _configure_firmware_log(self) -> None:
        if not self.firmware_log_text:
            return
        self.firmware_log_text.configure(
            bg=self.app.palette.surface,
            fg=self.app.palette.text,
            insertbackground=self.app.palette.text,
            highlightbackground=self.app.palette.border,
            highlightcolor=self.app.palette.accent,
            font=self.app.font_tuple(9, maximum=11),
        )
        if self.wizard_view and self.wizard_view.winfo_exists():
            self.wizard_view.refresh_scale()
class AddRelayWizardFrame(ttk.Frame):
    def __init__(self, parent, app, owner: ExtraSensorsSettingsView) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.owner = owner
        pins = self.app.extra_config.free_pins("digital")
        self.pin_var = tk.StringVar(value=pins[0] if pins else "")
        self.name_var = tk.StringVar(value="Дополнительное реле")
        self.uid_var = tk.StringVar(value="")
        self.type_var = tk.StringVar(value="relay_active_low")
        self.active_low_var = tk.BooleanVar(value=True)
        self.enabled_var = tk.BooleanVar(value=True)
        self.show_control_var = tk.BooleanVar(value=True)
        self.status_var = tk.StringVar(value="")
        self._build(pins)
    def _build(self, pins: list[str]) -> None:
        scroll = ScrollableFrame(self, self.app.palette)
        scroll.pack(fill="both", expand=True)
        card = Card(scroll.inner, "Мастер добавления реле", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(
            card,
            text="Мастер добавляет реле в device_relays и GeneratedConfig.h. Старые 4 реле D7/D6/D5/D4 не меняются.",
            style=MUTED_LABEL_STYLE,
            wraplength=720,
        ).pack(anchor="w", fill="x", pady=(0, self.app.pad(6)))
        self._combo(card, "Тип модуля", self.type_var, ["relay_active_low", "relay_active_high", "mosfet_key", "servo_curtains"])
        self._combo(card, "Свободный цифровой пин", self.pin_var, pins)
        self._entry(card, "Название", self.name_var)
        self._entry(card, "relay_uid", self.uid_var)
        flags = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        flags.pack(fill="x", pady=self.app.pad(4))
        ttk.Checkbutton(flags, text="Active low", variable=self.active_low_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Включено", variable=self.enabled_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Показывать в управлении", variable=self.show_control_var).pack(side="left", padx=self.app.pad(3))
        actions = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        actions.pack(fill="x", pady=self.app.pad(8))
        ttk.Button(actions, text="Сохранить реле", style=ACCENT_BUTTON_STYLE, command=self.save_relay).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Сгенерировать прошивку", command=self.app.generate_extra_sensor_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Compile", command=self.app.compile_extra_sensor_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Upload", command=self.app.upload_extra_sensor_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text=BACK_TEXT, command=self.owner.show_advanced_settings).pack(side="right", padx=self.app.pad(3))
        ttk.Label(card, textvariable=self.status_var, style=MUTED_LABEL_STYLE, wraplength=720).pack(anchor="w", fill="x")
        if not pins:
            self.status_var.set("Свободных цифровых пинов нет. Освободите пин или смените плату.")
    def save_relay(self) -> None:
        pin = self.pin_var.get().strip().upper()
        if not pin:
            self.status_var.set("Выберите свободный цифровой пин")
            return
        uid = self.uid_var.get().strip() or f"relay_{pin}"
        uid = "".join(ch.lower() if ch.isalnum() else "_" for ch in uid).strip("_")
        relays = self.app.settings.get("device_relays", [])
        relays = [dict(item) for item in relays] if isinstance(relays, list) else []
        if any(str(item.get("relay_uid") or item.get("id")) == uid for item in relays):
            self.status_var.set(f"relay_uid уже есть: {uid}")
            return
        relays.append(
            {
                "relay_uid": uid,
                "name": self.name_var.get().strip() or uid,
                "pin": pin,
                "type": "relay",
                "relay_type": self.type_var.get(),
                "active_low": bool(self.active_low_var.get()),
                "enabled": bool(self.enabled_var.get()),
                "show_in_control": bool(self.show_control_var.get()),
                "command": uid,
                "icon": "control",
                "core": False,
            }
        )
        self.app.settings.update({"device_config_mode": "custom", "device_relays": relays}, save=True)
        self.app.device_config = self.app.device_config.__class__(self.app.settings, self.app.extra_config)
        self.app._after_device_config_changed("Реле добавлено")
        self.status_var.set("Реле сохранено. Для Arduino создана структура в GeneratedConfig.h; старые 4 реле не изменены.")
    def _entry(self, parent, label: str, variable: tk.StringVar) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        ttk.Entry(row, textvariable=variable).pack(side="left", fill="x", expand=True)
    def _combo(self, parent, label: str, variable: tk.StringVar, values: list[str]) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=24).pack(side="left")
        ttk.Combobox(row, textvariable=variable, values=values, state="readonly" if values else "normal").pack(side="left", fill="x", expand=True)
class AddSensorWizardFrame(ttk.Frame):
    STEPS = (
        "Тип подключения",
        "Пин / порт",
        "Профиль датчика",
        "Настройки датчика",
        "Калибровка",
        "Прошивка Arduino",
        "Проверка",
        "Готово",
    )
    CONNECTION_INFO = {
        "analog": ("Аналоговый датчик", "LDR, влажность почвы, уровень воды, датчик дождя, MQ-серия."),
        "digital": ("Цифровой датчик", "DHT11, DHT22, DS18B20, HC-SR04 и простые цифровые модули."),
        "i2c": ("I2C датчик", "BH1750, AHT10/AHT20, BMP280, BME280. Пины SDA/SCL общие для шины."),
        "uart": ("UART датчик", "Экспериментально. Проверьте конфликт с USB Serial и основной прошивкой."),
        "spi": ("SPI датчик", "Экспериментально. Сейчас используется как профильная заготовка."),
    }
    ANALOG_PROFILES = (
        {
            "profile_id": "analog_raw",
            "sensor_type": "analog_raw",
            "name": "Аналоговый raw",
            "connection": "analog",
            "unit": "raw",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
        {
            "profile_id": "ldr",
            "sensor_type": "ldr",
            "name": "LDR освещённость",
            "connection": "analog",
            "unit": "raw",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
        {
            "profile_id": "soil_moisture",
            "sensor_type": "soil_moisture",
            "name": "Влажность почвы",
            "connection": "analog",
            "unit": "%",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
        {
            "profile_id": "water_level",
            "sensor_type": "water_level",
            "name": "Уровень воды",
            "connection": "analog",
            "unit": "raw",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
        {
            "profile_id": "rain_analog",
            "sensor_type": "rain_analog",
            "name": "Датчик дождя",
            "connection": "analog",
            "unit": "raw",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
        {
            "profile_id": "mq_sensor",
            "sensor_type": "mq_sensor",
            "name": "MQ-серия",
            "connection": "analog",
            "unit": "raw",
            "template_status": "supported",
            "library_required": False,
            "library_name": "",
        },
    )
    def __init__(self, parent, app, owner: ExtraSensorsSettingsView) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.owner = owner
        self.wizard_state = AddSensorWizardState(board=self.app.extra_config.board_id())
        self.step_index = 0
        self.profile_options: list[dict[str, Any]] = []
        self.selected_profile: dict[str, Any] | None = None
        self.wrap_labels: list[ttk.Label] = []
        self.log_lines: list[str] = []
        self._original_sensors: list[dict[str, Any]] | None = None
        self._pending_firmware_action = ""
        self._check_after_id: str | None = None
        self.connection_var = tk.StringVar(value=self.wizard_state.connection_type)
        self.pin_var = tk.StringVar(value="")
        self.address_var = tk.StringVar(value=self.wizard_state.address)
        self.profile_var = tk.StringVar(value="")
        self.name_var = tk.StringVar(value="")
        self.uid_var = tk.StringVar(value="")
        self.unit_var = tk.StringVar(value="raw")
        self.enabled_var = tk.BooleanVar(value=True)
        self.show_monitoring_var = tk.BooleanVar(value=True)
        self.show_charts_var = tk.BooleanVar(value=True)
        self.send_to_server_var = tk.BooleanVar(value=False)
        self.calibration_type_var = tk.StringVar(value="raw")
        self.dry_raw_var = tk.StringVar(value="")
        self.wet_raw_var = tk.StringVar(value="")
        self.min_value_var = tk.StringVar(value="0")
        self.max_value_var = tk.StringVar(value="100")
        self.offset_var = tk.StringVar(value="0")
        self.firmware_log_var = tk.StringVar(value="Лог появится после действий с прошивкой.")
        self.check_status_var = tk.StringVar(value="Проверка ещё не запускалась.")
        self.calibration_preview_var = tk.StringVar(value="")
        self._build()
    def _build(self) -> None:
        header = ttk.Frame(self, style=APP_FRAME_STYLE)
        header.pack(fill="x", pady=(0, self.app.pad(8)))
        ttk.Label(header, text="Мастер добавления датчика", style="Title.TLabel").pack(side="left")
        ttk.Button(header, text="Отмена", command=self.cancel).pack(side="right")
        self.progress_frame = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.progress_frame.pack(fill="x", pady=(0, self.app.pad(6)))
        self.scroll = ScrollableFrame(self, self.app.palette)
        self.scroll.pack(fill="both", expand=True)
        self.scroll.inner.bind("<Configure>", self._refresh_wraplengths, add="+")
        footer = ttk.Frame(self, style=APP_FRAME_STYLE)
        footer.pack(fill="x", pady=(self.app.pad(8), 0))
        self.back_button = ttk.Button(footer, text=BACK_TEXT, command=self.previous_step)
        self.back_button.pack(side="left", padx=self.app.pad(3))
        self.next_button = ttk.Button(footer, text="Далее", style=ACCENT_BUTTON_STYLE, command=self.next_step)
        self.next_button.pack(side="right", padx=self.app.pad(3))
        self.secondary_button = ttk.Button(footer, text=ADVANCED_SETTINGS_TEXT, command=self.cancel)
        self.secondary_button.pack(side="right", padx=self.app.pad(3))
        self.render()
    def render(self) -> None:
        self._render_progress()
        for child in self.scroll.inner.winfo_children():
            child.destroy()
        self.wrap_labels.clear()
        builders = (
            self._step_connection,
            self._step_pin,
            self._step_profile,
            self._step_settings,
            self._step_calibration,
            self._step_firmware,
            self._step_check,
            self._step_done,
        )
        builders[self.step_index]()
        self._update_footer()
        self._refresh_wraplengths()
    def _render_progress(self) -> None:
        for child in self.progress_frame.winfo_children():
            child.destroy()
        for index, title in enumerate(self.STEPS, start=1):
            text = f"{index}. {title}"
            style = SURFACE_LABEL_STYLE if index - 1 == self.step_index else MUTED_LABEL_STYLE
            label = ttk.Label(self.progress_frame, text=text, style=style)
            label.pack(side="left", padx=(0, self.app.pad(8)), pady=self.app.pad(2))
    def _update_footer(self) -> None:
        self.back_button.configure(state="disabled" if self.step_index == 0 else "normal")
        if self.step_index == len(self.STEPS) - 1:
            self.next_button.configure(text="Закрыть", command=self.owner.show_advanced_settings, state="normal")
            return
        if self.step_index == 6:
            self.next_button.configure(text="Сохранить датчик", command=self.save_final, state="normal")
            return
        self.next_button.configure(text="Далее", command=self.next_step, state="normal")
    def next_step(self) -> None:
        if not self._save_current_step():
            return
        if (
            self.step_index == 5
            and not self.wizard_state.upload_ok
            and not messagebox.askyesno(
                "Переход без upload",
                "Прошивка ещё не загружена или upload не подтверждён. Перейти к проверке всё равно?",
            )
        ):
            return
        self.step_index = min(len(self.STEPS) - 1, self.step_index + 1)
        self.render()
    def previous_step(self) -> None:
        self._save_current_step()
        self.step_index = max(0, self.step_index - 1)
        self.render()
    def cancel(self) -> None:
        if self.wizard_state.upload_ok and not messagebox.askyesno(
            "Отмена мастера",
            "Прошивка могла быть загружена в Arduino. Закрыть мастер без сохранения датчика?",
        ):
            return
        if self.has_unsaved_stage():
            if messagebox.askyesno("Отмена мастера", "Вернуть локальную конфигурацию датчиков к состоянию до мастера?"):
                self.restore_original_sensors()
        self.owner.show_advanced_settings()
    def has_unsaved_stage(self) -> bool:
        return bool(self.wizard_state.staged_config and not self.wizard_state.saved and self._original_sensors is not None)
    def restore_original_sensors(self) -> None:
        if self._original_sensors is None:
            return
        self.app.extra_config.save_sensors(self._original_sensors)
        self.app.refresh_extra_devices_tab()
        self.wizard_state.staged_config = False
        self._original_sensors = None
    def handle_status(self, message: str) -> None:
        if not message:
            return
        self._append_log(message)
        lower = message.lower()
        if self._pending_firmware_action == "compile":
            if "успеш" in lower or "выполнен успешно" in lower:
                self.wizard_state.compile_ok = True
                self._pending_firmware_action = ""
            elif "ошиб" in lower or "не найден" in lower or "не прош" in lower:
                self.wizard_state.compile_ok = False
                self._pending_firmware_action = ""
        elif self._pending_firmware_action == "upload":
            if "upload отмен" in lower or "сборка не прош" in lower or "ошиб" in lower:
                self.wizard_state.upload_ok = False
                self._pending_firmware_action = ""
            elif "успеш" in lower or "выполнен успешно" in lower:
                self.wizard_state.upload_ok = True
                self._pending_firmware_action = ""
        if "extra_sensors получен" in lower:
            self.wizard_state.test_ok = True
            self.check_status_var.set("Данные EXTRA_SENSORS получены.")
        elif "extra_sensors пока не получен" in lower:
            self.check_status_var.set("Serial открыт, но EXTRA_SENSORS пока не получен.")
        self.firmware_log_var.set("\n".join(self.log_lines[-8:]))
    def _step_connection(self) -> None:
        card = Card(self.scroll.inner, "1. Тип подключения", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(
            card,
            text="Выберите, как датчик подключается к Arduino. UART и SPI оставлены как экспериментальные профили, чтобы не ломать текущую прошивку.",
            style=MUTED_LABEL_STYLE,
        ).pack(anchor="w", fill="x", pady=(0, self.app.pad(6)))
        for key, (title, description) in self.CONNECTION_INFO.items():
            row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
            row.pack(fill="x", pady=self.app.pad(3))
            ttk.Radiobutton(row, text=title, variable=self.connection_var, value=key).pack(side="left", padx=(0, self.app.pad(8)))
            suffix = " Частичная поддержка." if key in {"uart", "spi"} else ""
            self._wrap_label(row, text=description + suffix, style=MUTED_LABEL_STYLE).pack(side="left", fill="x", expand=True)
    def _step_pin(self) -> None:
        self.wizard_state.connection_type = self.connection_var.get()
        board = self.app.extra_config.board()
        summary = self.app.extra_config.port_summary()
        card = Card(self.scroll.inner, "2. Пин / порт", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(card, text=f"Плата: {board.name}. I2C SDA/SCL: {', '.join(board.i2c_pins) or 'не задано'}.", style=SURFACE_LABEL_STYLE).pack(anchor="w", fill="x")
        occupied = ", ".join(f"{item.pin} ({item.label})" for item in summary["occupied"]) or "нет"
        free_digital = ", ".join(summary["free_digital"]) or "нет"
        free_analog = ", ".join(summary["free_analog"]) or "нет"
        self._wrap_label(card, text=f"Занятые пины: {occupied}", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(3))
        self._wrap_label(card, text=f"Свободные цифровые: {free_digital}. Свободные аналоговые: {free_analog}.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(3))
        connection = self.wizard_state.connection_type
        if connection == "i2c":
            self.pin_var.set("I2C")
            row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
            row.pack(fill="x", pady=self.app.pad(6))
            ttk.Label(row, text="Адрес I2C", style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
            ttk.Entry(row, textvariable=self.address_var, width=12).pack(side="left")
            self._wrap_label(card, text="Для I2C отдельный пин не выбирается: используется общая шина SDA/SCL.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
            return
        required_pins = self._profile_required_pins(self.selected_profile or {}) if self.selected_profile else 1
        pins = self.app.extra_config.free_pins(connection, required_pins)
        if not pins:
            self._wrap_label(
                card,
                text="Свободных портов для выбранного типа нет. Удалите другой датчик, смените плату или выберите другой тип подключения.",
                style=MUTED_LABEL_STYLE,
            ).pack(anchor="w", fill="x", pady=self.app.pad(6))
            self.pin_var.set("")
            return
        if self.pin_var.get() not in pins:
            self.pin_var.set(pins[0])
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(row, text="Свободный пин", style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
        ttk.Combobox(row, textvariable=self.pin_var, values=pins, state="readonly", width=18).pack(side="left", fill="x", expand=True)
    def _step_profile(self) -> None:
        self.wizard_state.connection_type = self.connection_var.get()
        card = Card(self.scroll.inner, "3. Профиль датчика", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(
            card,
            text="Профиль описывает тип датчика, единицы измерения, статус шаблона и нужную Arduino-библиотеку. Python-код из профиля не выполняется.",
            style=MUTED_LABEL_STYLE,
        ).pack(anchor="w", fill="x", pady=(0, self.app.pad(6)))
        self.profile_options = self._profile_options_for_connection(self.wizard_state.connection_type)
        labels = [self._profile_label(item) for item in self.profile_options]
        if labels and self.profile_var.get() not in labels:
            self.profile_var.set(labels[0])
        selector = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        selector.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(selector, text="Профиль", style=SURFACE_LABEL_STYLE, width=16).pack(side="left")
        combo = ttk.Combobox(selector, textvariable=self.profile_var, values=labels, state="readonly", width=42)
        combo.pack(side="left", fill="x", expand=True)
        combo.bind(COMBOBOX_SELECTED_EVENT, lambda _event: self._select_profile_from_var())
        ttk.Button(selector, text="Выбрать профиль", command=self._select_profile_from_var).pack(side="left", padx=self.app.pad(4))
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(5))
        ttk.Button(row, text="Импортировать JSON-профиль", command=self._import_profile).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Импортировать Arduino-библиотеку", command=self._import_library).pack(side="left", padx=self.app.pad(3))
        self.profile_detail = self._wrap_label(card, text="", style=MUTED_LABEL_STYLE)
        self.profile_detail.pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
        self._select_profile_from_var(silent=True)
    def _step_settings(self) -> None:
        self._ensure_profile_selected()
        if not self.name_var.get().strip():
            self.name_var.set(str((self.selected_profile or {}).get("name") or "Датчик"))
        if not self.uid_var.get().strip():
            self.uid_var.set(self._default_uid())
        if not self.unit_var.get().strip():
            self.unit_var.set(str((self.selected_profile or {}).get("unit") or "raw"))
        card = Card(self.scroll.inner, "4. Настройки датчика", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._entry_row(card, "Имя датчика", self.name_var)
        self._entry_row(card, "sensor_uid", self.uid_var)
        self._entry_row(card, "Единица", self.unit_var)
        flags = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        flags.pack(fill="x", pady=self.app.pad(6))
        ttk.Checkbutton(flags, text="Включён", variable=self.enabled_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Мониторинг", variable=self.show_monitoring_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Графики", variable=self.show_charts_var).pack(side="left", padx=self.app.pad(3))
        ttk.Checkbutton(flags, text="Отправлять на сайт", variable=self.send_to_server_var).pack(side="left", padx=self.app.pad(3))
        outputs = self._profile_outputs()
        if len(outputs) > 1:
            generated = ", ".join(f"{self._safe_id(self.uid_var.get())}_{self._safe_id(item['key'])}" for item in outputs)
            self._wrap_label(card, text=f"Профиль выдаёт несколько значений. Мастер подготовит sensor_uid: {generated}.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(4))
    def _step_calibration(self) -> None:
        card = Card(self.scroll.inner, "5. Калибровка", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        if self.wizard_state.connection_type != "analog":
            self._wrap_label(card, text="Для цифровых и I2C-профилей базовая калибровка обычно не требуется.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
            self._entry_row(card, "Offset / correction", self.offset_var)
            return
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text="Тип калибровки", style=SURFACE_LABEL_STYLE, width=20).pack(side="left")
        ttk.Combobox(row, textvariable=self.calibration_type_var, values=["raw", "linear_percent", "linear_voltage", "custom"], state="readonly", width=20).pack(side="left")
        self._wrap_label(card, text="Для linear_percent можно считать сухое и влажное значение, либо ввести raw вручную.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(4))
        self._entry_row(card, "dry_raw", self.dry_raw_var)
        self._entry_row(card, "wet_raw", self.wet_raw_var)
        self._entry_row(card, "min_value", self.min_value_var)
        self._entry_row(card, "max_value", self.max_value_var)
        actions = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        actions.pack(fill="x", pady=self.app.pad(5))
        ttk.Button(actions, text="Считать сухое значение", command=lambda: self._read_raw_into(self.dry_raw_var)).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Считать влажное значение", command=lambda: self._read_raw_into(self.wet_raw_var)).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Обновить preview", command=self._update_calibration_preview).pack(side="left", padx=self.app.pad(3))
        self._wrap_label(card, textvariable=self.calibration_preview_var, style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=(self.app.pad(5), 0))
    def _step_firmware(self) -> None:
        card = Card(self.scroll.inner, "6. Прошивка Arduino", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._wrap_label(
            card,
            text="Мастер временно подготовит конфигурацию, создаст GeneratedConfig.h и ExtraSensors.h/cpp. fitodomik.ino остаётся основной точкой входа, перед изменением создаётся backup. Upload доступен только после успешного compile.",
            style=MUTED_LABEL_STYLE,
        ).pack(anchor="w", fill="x")
        actions = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        actions.pack(fill="x", pady=self.app.pad(8))
        ttk.Button(actions, text="Создать конфигурацию", command=self.generate_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Собрать прошивку", command=self.compile_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Загрузить в Arduino", style=ACCENT_BUTTON_STYLE, command=self.upload_firmware).pack(side="left", padx=self.app.pad(3))
        ttk.Button(actions, text="Откатить backup", command=self.rollback_firmware).pack(side="left", padx=self.app.pad(3))
        self._wrap_label(card, textvariable=self.firmware_log_var, style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(6))
    def _step_check(self) -> None:
        card = Card(self.scroll.inner, "7. Проверка датчика", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        expected = self._expected_sensor_uids()
        self._wrap_label(card, text=f"Ожидаемые sensor_uid: {', '.join(expected) or self.uid_var.get()}", style=SURFACE_LABEL_STYLE).pack(anchor="w", fill="x")
        latest_text = self._latest_extra_values_text(expected)
        self._wrap_label(card, text=latest_text, style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(5))
        self._wrap_label(card, textvariable=self.check_status_var, style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(5))
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Button(row, text="Проверить ещё раз", command=self.check_again).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Не работает — откатить", command=self.rollback_firmware).pack(side="left", padx=self.app.pad(3))
        if self._has_expected_values(expected):
            self.wizard_state.test_ok = True
            self.check_status_var.set("Данные получены. Можно сохранить датчик.")
    def _step_done(self) -> None:
        card = Card(self.scroll.inner, "8. Готово", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        if self.wizard_state.upload_ok:
            firmware_status = "upload выполнен"
        elif self.wizard_state.compile_ok:
            firmware_status = "compile выполнен"
        else:
            firmware_status = "требуется проверка"
        lines = [
            f"Имя: {self.wizard_state.sensor_name}",
            f"sensor_uid: {', '.join(self._expected_sensor_uids())}",
            f"Пин/порт: {self.wizard_state.pin or self.wizard_state.address}",
            f"Профиль: {self.wizard_state.profile_id}",
            f"Единица: {self.wizard_state.unit}",
            "Отправка на сайт: " + ("да" if self.wizard_state.send_to_server else "нет"),
            "Мониторинг: " + ("да" if self.wizard_state.show_in_monitoring else "нет"),
            "Графики: " + ("да" if self.wizard_state.show_in_charts else "нет"),
            "Статус прошивки: " + firmware_status,
        ]
        self._wrap_label(card, text="\n".join(lines), style=SURFACE_LABEL_STYLE).pack(anchor="w", fill="x")
        self._wrap_label(card, text="Конфигурация сохранена локально. Синхронизация с сайтом будет выполнена существующей очередью, если указан token/device_id/device_token.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(8))
    def _save_current_step(self) -> bool:
        self.wizard_state.connection_type = self.connection_var.get()
        self.wizard_state.pin = self.pin_var.get().strip().upper()
        self.wizard_state.address = self.address_var.get().strip() or "0x76"
        self._ensure_profile_selected()
        if self.step_index >= 2 and self.selected_profile is None:
            self.owner.set_status("Выберите профиль датчика")
            return False
        self.wizard_state.sensor_name = self.name_var.get().strip()
        self.wizard_state.sensor_uid = self._safe_id(self.uid_var.get().strip() or self._default_uid())
        self.wizard_state.unit = self.unit_var.get().strip() or str((self.selected_profile or {}).get("unit") or "raw")
        self.wizard_state.enabled = bool(self.enabled_var.get())
        self.wizard_state.show_in_monitoring = bool(self.show_monitoring_var.get())
        self.wizard_state.show_in_charts = bool(self.show_charts_var.get())
        self.wizard_state.send_to_server = bool(self.send_to_server_var.get())
        self.wizard_state.calibration = self._calibration_from_vars()
        if self.step_index == 1 and self.wizard_state.connection_type not in {"i2c", "uart", "spi"} and not self.wizard_state.pin:
            self.owner.set_status("Выберите свободный пин")
            return False
        if self.step_index >= 3 and not self.wizard_state.sensor_uid:
            self.owner.set_status("sensor_uid не должен быть пустым")
            return False
        return True
    def _ensure_profile_selected(self) -> None:
        if self.selected_profile is None:
            self._select_profile_from_var(silent=True)
        profile = self.selected_profile or {}
        self.wizard_state.profile_id = str(profile.get("profile_id") or "")
        self.wizard_state.sensor_type = str(profile.get("sensor_type") or self.wizard_state.profile_id or "analog_raw")
        self.wizard_state.library_required = bool(profile.get("library_required", False))
    def _select_profile_from_var(self, silent: bool = False) -> None:
        selected = self._selected_profile_option(self.profile_var.get())
        if selected is None and self.profile_options:
            selected = self.profile_options[0]
            self.profile_var.set(self._profile_label(selected))
        self.selected_profile = selected
        if not selected:
            if not silent and hasattr(self, "profile_detail"):
                self.profile_detail.configure(text="Профили не найдены для выбранного типа подключения")
            return
        self.wizard_state.profile_id = str(selected.get("profile_id") or "")
        self.wizard_state.sensor_type = str(selected.get("sensor_type") or self.wizard_state.profile_id)
        self.wizard_state.unit = str(selected.get("unit") or "raw")
        self.wizard_state.library_required = bool(selected.get("library_required", False))
        self._apply_selected_profile_pin(selected)
        self._apply_selected_profile_defaults(selected)
        if hasattr(self, "profile_detail"):
            self.profile_detail.configure(text=self._selected_profile_detail(selected))
    def _selected_profile_option(self, label: str) -> dict[str, Any] | None:
        return next((option for option in self.profile_options if self._profile_label(option) == label), None)
    def _apply_selected_profile_pin(self, selected: dict[str, Any]) -> None:
        required_pins = self._profile_required_pins(selected)
        if self.connection_var.get() in {"analog", "digital"} and required_pins > 1:
            choices = self.app.extra_config.free_pins(self.connection_var.get(), required_pins)
            if choices and self.pin_var.get() not in choices:
                self.pin_var.set(choices[0])
    def _apply_selected_profile_defaults(self, selected: dict[str, Any]) -> None:
        if not self.name_var.get().strip():
            self.name_var.set(str(selected.get("name") or self.wizard_state.sensor_type))
        if not self.unit_var.get().strip() or self.unit_var.get() == "raw":
            self.unit_var.set(self.wizard_state.unit)
        if not self.uid_var.get().strip():
            self.uid_var.set(self._default_uid())
    def _selected_profile_detail(self, selected: dict[str, Any]) -> str:
        detail = (
            f"{selected.get('name')} · {selected.get('connection')} · {selected.get('unit')} · "
            f"{selected.get('template_status')}"
        )
        if selected.get("library_required"):
            detail += f" · нужна библиотека: {selected.get('library_name') or 'не указана'}"
        outputs = self._profile_outputs(selected)
        if outputs:
            detail += " · значения: " + ", ".join(f"{item['key']} {item['unit']}".strip() for item in outputs)
        return detail
    def _profile_options_for_connection(self, connection: str) -> list[dict[str, Any]]:
        options: list[dict[str, Any]] = []
        seen: set[str] = set()
        def add(option: dict[str, Any]) -> None:
            profile_id = str(option.get("profile_id") or option.get("sensor_type") or "")
            if not profile_id or profile_id in seen:
                return
            seen.add(profile_id)
            options.append(option)
        if connection == "analog":
            for item in self.ANALOG_PROFILES:
                add({**item, "_source": "simple"})
        for definition in self.app.extra_registry.sensors_for_connection(connection):
            add(self._option_from_definition(definition))
        for profile in self.app.sensor_profiles.profiles():
            if str(profile.get("connection", "")).lower() != connection:
                continue
            add(self._option_from_json_profile(profile))
        return options
    def _option_from_definition(self, definition: ExtraSensorDefinition) -> dict[str, Any]:
        return {
            "profile_id": definition.sensor_id,
            "sensor_type": definition.sensor_id,
            "name": definition.name,
            "connection": definition.connection,
            "unit": definition.unit,
            "template_status": definition.template_status,
            "library_required": definition.library_required,
            "library_name": definition.library_name,
            "required_pins": definition.required_pins,
            "read_templates": [{"key": "value", "unit": definition.unit}],
            "_source": "registry",
        }
    def _option_from_json_profile(self, profile: dict[str, Any]) -> dict[str, Any]:
        outputs = profile.get("read_templates", [])
        units = []
        if isinstance(outputs, list):
            units = [str(item.get("unit", "")) for item in outputs if isinstance(item, dict) and str(item.get("unit", ""))]
        return {
            "profile_id": str(profile.get("profile_id") or ""),
            "sensor_type": str(profile.get("sensor_type") or profile.get("profile_id") or ""),
            "name": str(profile.get("name") or profile.get("profile_id") or "Профиль"),
            "connection": str(profile.get("connection") or ""),
            "unit": "/".join(dict.fromkeys(units)) or str(profile.get("unit") or "raw"),
            "template_status": str(profile.get("template_status") or ""),
            "library_required": bool(profile.get("library_required", False)) or bool(str(profile.get("library_name", "")).strip()),
            "library_name": str(profile.get("library_name") or ""),
            "required_pins": self._profile_required_pins(profile),
            "read_templates": outputs if isinstance(outputs, list) else [],
            "_source": str(profile.get("_source") or "profile"),
        }
    def _profile_label(self, option: dict[str, Any]) -> str:
        library = " · library" if option.get("library_required") else ""
        return f"{option.get('name')} · {option.get('profile_id')} · {option.get('template_status')}{library}"
    def _profile_required_pins(self, profile: dict[str, Any]) -> int:
        try:
            return max(1, int(profile.get("required_pins") or 1))
        except (TypeError, ValueError):
            return 1
    def _profile_outputs(self, profile: dict[str, Any] | None = None) -> list[dict[str, str]]:
        selected = profile or self.selected_profile or {}
        raw = selected.get("read_templates", [])
        if not isinstance(raw, list) or not raw:
            return [{"key": "value", "unit": str(selected.get("unit") or self.unit_var.get() or "raw")}]
        result: list[dict[str, str]] = []
        for item in raw:
            if not isinstance(item, dict):
                continue
            key = self._safe_id(str(item.get("key") or "value"))
            unit = str(item.get("unit") or selected.get("unit") or "")
            result.append({"key": key, "unit": unit})
        return result or [{"key": "value", "unit": str(selected.get("unit") or "raw")}]
    def _default_uid(self) -> str:
        profile = self.selected_profile or {}
        sensor_type = self._safe_id(str(profile.get("sensor_type") or profile.get("profile_id") or self.connection_var.get()))
        pin = self.pin_var.get().strip().upper()
        if self.connection_var.get() == "i2c":
            pin = "i2c"
        elif not pin:
            pin = "pin"
        return self._safe_id(f"{sensor_type}_{pin}")
    def _sensor_configs(self, enabled: bool | None = None) -> list[dict[str, Any]]:
        self._save_current_step()
        profile = self.selected_profile or {}
        outputs = self._profile_outputs(profile)
        base_uid = self.wizard_state.sensor_uid or self._default_uid()
        multiple = len(outputs) > 1
        return [self._sensor_config_for_output(profile, output, base_uid, multiple, enabled) for output in outputs]
    def _sensor_config_for_output(
        self,
        profile: dict[str, Any],
        output: dict[str, str],
        base_uid: str,
        multiple: bool,
        enabled: bool | None,
    ) -> dict[str, Any]:
        output_key = output["key"]
        uid = self._safe_id(f"{base_uid}_{output_key}") if multiple else self._safe_id(base_uid)
        name = self.wizard_state.sensor_name or str(profile.get("name") or uid)
        if multiple:
            name = f"{name} {output_key}"
        is_i2c = self.wizard_state.connection_type == "i2c"
        return {
            "id": uid,
            "name": name,
            "type": self.wizard_state.sensor_type or str(profile.get("sensor_type") or "analog_raw"),
            "profile": self.wizard_state.profile_id,
            "profile_output": output_key,
            "profile_group_uid": base_uid if multiple else "",
            "pin": "I2C" if is_i2c else self.wizard_state.pin,
            "address": self.wizard_state.address if is_i2c else "",
            "connection": self.wizard_state.connection_type,
            "unit": output.get("unit") or self.wizard_state.unit,
            "enabled": self.wizard_state.enabled if enabled is None else bool(enabled),
            "send_to_server": self.wizard_state.send_to_server,
            "show_in_monitoring": self.wizard_state.show_in_monitoring,
            "show_in_charts": self.wizard_state.show_in_charts,
            "calibration": dict(self.wizard_state.calibration),
            "template_status": str(profile.get("template_status") or "supported"),
            "library_required": bool(profile.get("library_required", False)),
            "library_name": str(profile.get("library_name") or ""),
            "wizard_created": True,
        }
    def _stage_sensor_config(self) -> bool:
        configs = self._sensor_configs(enabled=True)
        if not configs:
            self.owner.set_status("Нет конфигурации датчика для генерации")
            return False
        if self._original_sensors is None:
            self._original_sensors = self.app.extra_config.sensors()
        original_sensors = self._original_sensors or []
        ids = {cfg["id"] for cfg in configs}
        existing_ids = {str(item.get("id", "")) for item in original_sensors}
        duplicate = next((sensor_id for sensor_id in ids if sensor_id in existing_ids), "")
        if duplicate and not self.wizard_state.staged_config:
            self.owner.set_status(f"sensor_uid уже существует: {duplicate}")
            return False
        base = [item for item in original_sensors if str(item.get("id", "")) not in ids]
        if self.wizard_state.connection_type in {"analog", "digital"} and not self.wizard_state.staged_config:
            required_pins = self._profile_required_pins(self.selected_profile or {})
            free = self.app.extra_config.free_pins(self.wizard_state.connection_type, required_pins)
            if self.wizard_state.pin not in free:
                self.owner.set_status(f"Пин {self.wizard_state.pin} уже занят")
                return False
        self.app.extra_config.save_sensors([*base, *configs])
        self.wizard_state.staged_config = True
        return True
    def generate_firmware(self) -> None:
        if not self._stage_sensor_config():
            return
        self._append_log("Создаю GeneratedConfig.h и ExtraSensors.h/cpp...")
        self.app.generate_extra_sensor_firmware()
    def compile_firmware(self) -> None:
        if not self.wizard_state.staged_config and not self._stage_sensor_config():
            return
        self._pending_firmware_action = "compile"
        self._append_log("Запускаю arduino-cli compile...")
        self.app.compile_extra_sensor_firmware()
    def upload_firmware(self) -> None:
        if not self.wizard_state.compile_ok and not messagebox.askyesno("Upload", "Compile ещё не отмечен как успешный. Запустить upload с предварительной сборкой?"):
            return
        if not self.wizard_state.staged_config and not self._stage_sensor_config():
            return
        self._pending_firmware_action = "upload"
        self._append_log("Запускаю upload. GUI останется отзывчивым.")
        self.app.upload_extra_sensor_firmware()
    def rollback_firmware(self) -> None:
        if not messagebox.askyesno("Откат прошивки", "Восстановить последний backup прошивки?"):
            return
        self._append_log("Запускаю rollback прошивки...")
        self.app.rollback_extra_sensor_firmware(None)
    def check_again(self) -> None:
        self._save_current_step()
        self.check_status_var.set("Ожидаем данные EXTRA_SENSORS до 30 секунд...")
        self.app.check_extra_sensor_now()
        if self._check_after_id:
            try:
                self.after_cancel(self._check_after_id)
            except tk.TclError:
                pass
        self._check_after_id = self.after(30000, self._check_timeout)
        self.render()
    def _check_timeout(self) -> None:
        self._check_after_id = None
        if not self.wizard_state.test_ok:
            self.check_status_var.set("Таймаут: данные не пришли. Можно сохранить датчик отключённым или выполнить rollback.")
            self.render()
    def save_final(self) -> None:
        if not self._save_current_step():
            return
        enabled = self.wizard_state.enabled
        if not self.wizard_state.test_ok:
            if messagebox.askyesno("Данные не получены", "EXTRA_SENSORS не подтверждён. Сохранить датчик отключённым?"):
                enabled = False
            else:
                return
        configs = self._sensor_configs(enabled=enabled)
        original = self._original_sensors if self._original_sensors is not None else self.app.extra_config.sensors()
        ids = {cfg["id"] for cfg in configs}
        saved = [item for item in original if str(item.get("id", "")) not in ids]
        saved.extend(configs)
        self.app.extra_config.save_sensors(saved)
        self.wizard_state.saved = True
        self.wizard_state.enabled = enabled
        self.app.refresh_extra_devices_tab()
        self.owner.set_status("Датчик сохранён. Синхронизация с сайтом будет выполнена существующей очередью.")
        self.step_index = 7
        self.render()
    def _calibration_from_vars(self) -> dict[str, Any]:
        calibration_type = self.calibration_type_var.get()
        if self.wizard_state.connection_type != "analog":
            try:
                offset = float(self.offset_var.get())
            except ValueError:
                offset = 0.0
            return {"enabled": bool(offset), "type": "offset", "offset": offset}
        if calibration_type == "raw":
            return {}
        if calibration_type == "linear_percent":
            try:
                dry = int(float(self.dry_raw_var.get()))
                wet = int(float(self.wet_raw_var.get()))
                min_value = float(self.min_value_var.get())
                max_value = float(self.max_value_var.get())
            except ValueError:
                return {"enabled": False, "type": "linear_percent"}
            if dry > wet:
                raw_min, raw_max, invert = wet, dry, True
            else:
                raw_min, raw_max, invert = dry, wet, False
            return {
                "enabled": True,
                "type": "linear_percent",
                "calibration_type": "linear_percent",
                "dry_raw": dry,
                "wet_raw": wet,
                "raw_min": raw_min,
                "raw_max": raw_max,
                "min_value": min_value,
                "max_value": max_value,
                "unit": "%",
                "invert": invert,
            }
        return {"enabled": True, "type": calibration_type}
    def _read_raw_into(self, variable: tk.StringVar) -> None:
        uid = self.uid_var.get().strip() or self._default_uid()
        value = self.app.arduino.current_extra_sensors.get(uid)
        if value is None:
            self.owner.set_status("Live-калибровка недоступна: нет текущего EXTRA_SENSORS для этого uid. Введите raw вручную.")
            return
        variable.set(str(value))
        self._update_calibration_preview()
    def _update_calibration_preview(self) -> None:
        try:
            dry = int(float(self.dry_raw_var.get()))
            wet = int(float(self.wet_raw_var.get()))
        except ValueError:
            self.calibration_preview_var.set("Введите dry_raw и wet_raw, чтобы увидеть preview.")
            return
        current = None
        uid = self.uid_var.get().strip() or self._default_uid()
        value = self.app.arduino.current_extra_sensors.get(uid)
        try:
            current = float(value) if value is not None else None
        except (TypeError, ValueError):
            current = None
        if current is None or dry == wet:
            self.calibration_preview_var.set(f"dry_raw={dry}, wet_raw={wet}. Текущий raw пока не получен.")
            return
        if dry > wet:
            percent = 100 - ((current - wet) * 100 / (dry - wet))
        else:
            percent = (current - dry) * 100 / (wet - dry)
        percent = max(0, min(100, percent))
        self.calibration_preview_var.set(f"Текущий raw={current:g}, расчёт={percent:.1f}%")
    def _latest_extra_values_text(self, expected: list[str]) -> str:
        current = self.app.arduino.current_extra_sensors
        if not current:
            return "Последние значения: пока нет данных EXTRA_SENSORS."
        parts = []
        for uid in expected:
            if uid in current:
                parts.append(f"{uid}: {current[uid]}")
        if not parts:
            return "Последние значения EXTRA_SENSORS есть, но ожидаемый uid пока не найден."
        return "Последние значения: " + "; ".join(parts) + f". Время проверки: {datetime.now().strftime('%H:%M:%S')}"
    def _has_expected_values(self, expected: list[str]) -> bool:
        current = self.app.arduino.current_extra_sensors
        return bool(expected) and any(uid in current for uid in expected)
    def _expected_sensor_uids(self) -> list[str]:
        return [str(item.get("id", "")) for item in self._sensor_configs(enabled=self.wizard_state.enabled) if item.get("id")]
    def _import_profile(self) -> None:
        self.owner.choose_profile()
        self.profile_options = self._profile_options_for_connection(self.connection_var.get())
        self.profile_var.set("")
        self.render()
    def _import_library(self) -> None:
        self.owner.choose_library()
        self.render()
    def _append_log(self, message: str) -> None:
        self.log_lines.append(message)
        self.log_lines = self.log_lines[-12:]
        self.firmware_log_var.set("\n".join(self.log_lines[-8:]))
    def _entry_row(self, parent, label: str, variable: tk.StringVar) -> None:
        row = ttk.Frame(parent, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text=label, style=SURFACE_LABEL_STYLE, width=20).pack(side="left")
        ttk.Entry(row, textvariable=variable).pack(side="left", fill="x", expand=True)
    def _wrap_label(self, parent, **kwargs) -> ttk.Label:
        label = ttk.Label(parent, wraplength=self._wraplength(), **kwargs)
        self.wrap_labels.append(label)
        return label
    def _wraplength(self) -> int:
        width = self.scroll.winfo_width() if hasattr(self, "scroll") else 0
        if width <= 1:
            width = self.app.content_width()
        return max(260, min(760, width - self.app.pad(48)))
    def _refresh_wraplengths(self, _event=None) -> None:
        wraplength = self._wraplength()
        alive: list[ttk.Label] = []
        for label in self.wrap_labels:
            try:
                if label.winfo_exists():
                    label.configure(wraplength=wraplength)
                    alive.append(label)
            except tk.TclError:
                pass
        self.wrap_labels = alive
    def refresh_scale(self) -> None:
        self._refresh_wraplengths()
    def _safe_id(self, value: str) -> str:
        result = "".join(ch.lower() if ch.isalnum() else "_" for ch in str(value))
        while "__" in result:
            result = result.replace("__", "_")
        return result.strip("_") or "extra_sensor"
