from __future__ import annotations
from datetime import datetime
import threading
import tkinter as tk
from tkinter import ttk
from fitodomik_app.ui.style_names import ACCENT_BUTTON_STYLE, APP_FRAME_STYLE, BACK_TEXT, COMBOBOX_SELECTED_EVENT, MUTED_LABEL_STYLE, SURFACE_FRAME_STYLE, SURFACE_LABEL_STYLE
from fitodomik_app.ui.widgets import Card
class FirstRunWizardFrame(ttk.Frame):
    STEPS = (
        "Приветствие",
        "Плата Arduino",
        "Подключение Arduino",
        "Камера",
        "Аккаунт",
        "Конфигурация",
        "Связь с сайтом",
        "Готово",
    )
    BOARD_LABELS = {
        "arduino_nano": "Arduino Nano",
        "arduino_nano_old": "Arduino Nano old bootloader",
        "arduino_uno": "Arduino Uno",
        "arduino_mega": "Arduino Mega",
        "manual": "Ручной FQBN",
    }
    def __init__(self, parent, app, on_back) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.on_back = on_back
        self.active = True
        self._after_ids: set[str] = set()
        self.step_index = 0
        self.status_var = tk.StringVar(value="")
        self.log_var = tk.StringVar(value="")
        self.board_var = tk.StringVar(value=str(self.app.settings.get("extra_sensors_board", "arduino_nano")))
        self.manual_fqbn_var = tk.StringVar(value=str(self.app.settings.get("extra_sensors_manual_fqbn", "")))
        self.port_var = tk.StringVar(value=str(self.app.settings.get("port", "")))
        self.camera_index_var = tk.StringVar(value=str(self.app.settings.get("camera_index", 0)))
        self.config_mode_var = tk.StringVar(value=str(self.app.settings.get("device_config_mode", "standard")))
        self.wrap_labels: list[ttk.Label] = []
        self.bind("<Destroy>", self._on_destroy, add="+")
        self._build()
    def _build(self) -> None:
        top = ttk.Frame(self, style=APP_FRAME_STYLE)
        top.pack(fill="x", padx=self.app.pad(2), pady=(0, self.app.pad(6)))
        ttk.Button(top, text="Назад к настройкам", command=self.go_back).pack(side="left")
        ttk.Label(top, text="Мастер первого запуска", style="Title.TLabel").pack(side="left", padx=self.app.pad(12))
        self.progress = ttk.Progressbar(self, maximum=len(self.STEPS) - 1, value=0)
        self.progress.pack(fill="x", pady=(0, self.app.pad(8)))
        self.steps_label = ttk.Label(self, text="", style=MUTED_LABEL_STYLE)
        self.steps_label.pack(anchor="w", fill="x", pady=(0, self.app.pad(8)))
        self.content = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.content.pack(fill="x", expand=False)
        nav = ttk.Frame(self, style=APP_FRAME_STYLE)
        nav.pack(fill="x", pady=(self.app.pad(6), self.app.pad(4)))
        self.back_button = ttk.Button(nav, text=BACK_TEXT, command=self.previous_step)
        self.back_button.pack(side="left", padx=self.app.pad(3))
        self.next_button = ttk.Button(nav, text="Далее", style=ACCENT_BUTTON_STYLE, command=self.next_step)
        self.next_button.pack(side="right", padx=self.app.pad(3))
        ttk.Button(nav, text="Отмена", command=self.go_back).pack(side="right", padx=self.app.pad(3))
        self.status_label = ttk.Label(self, textvariable=self.status_var, style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.status_label.pack(anchor="w", fill="x")
        self.log_label = ttk.Label(self, textvariable=self.log_var, style=MUTED_LABEL_STYLE, wraplength=self._wraplength())
        self.log_label.pack(anchor="w", fill="x", pady=(self.app.pad(4), 0))
        self.wrap_labels.extend([self.status_label, self.log_label])
        self.bind("<Configure>", self._refresh_wraplengths, add="+")
        self.render()
    def render(self) -> None:
        if not self._active():
            return
        if not self._widget_alive(getattr(self, "content", None)):
            return
        try:
            children = list(self.content.winfo_children())
        except tk.TclError:
            return
        for child in children:
            try:
                if child.winfo_exists():
                    child.destroy()
            except tk.TclError:
                pass
        if not self._active() or not self._widget_alive(self.content):
            return
        try:
            self.progress.configure(value=self.step_index)
            self.steps_label.configure(text=" -> ".join(self.STEPS[: self.step_index + 1]))
            self.back_button.configure(state="disabled" if self.step_index == 0 else "normal")
            self.next_button.configure(text="Завершить" if self.step_index == len(self.STEPS) - 1 else "Далее")
        except tk.TclError:
            return
        builders = (
            self._welcome_step,
            self._board_step,
            self._arduino_step,
            self._camera_step,
            self._account_step,
            self._config_step,
            self._site_step,
            self._summary_step,
        )
        builders[self.step_index]()
        self._refresh_wraplengths()
    def next_step(self) -> None:
        if not self._active():
            return
        if self.step_index == 1:
            self._save_board()
        elif self.step_index == 2:
            self._save_port()
        elif self.step_index == 3:
            self._save_camera()
        elif self.step_index == 5:
            self._save_config_mode()
        if not self._active():
            return
        if self.step_index >= len(self.STEPS) - 1:
            self.finish_setup()
            return
        self.step_index += 1
        self.render()
    def previous_step(self) -> None:
        if self._active() and self.step_index > 0:
            self.step_index -= 1
            self.render()
    def go_back(self) -> None:
        if not self.active:
            return
        self.deactivate()
        try:
            self.on_back()
        except tk.TclError:
            pass
    def finish_setup(self) -> None:
        if not self._active():
            return
        steps = dict(self.app.settings.get("setup_steps", {}) or {})
        steps["board_selected"] = True
        steps["device_config_selected"] = True
        if self.app.settings.get("token", "").strip() and self.app.settings.get("device_id", "").strip():
            steps["account_linked"] = True
        self.app.settings.update(
            {
                "first_run_completed": True,
                "setup_completed_at": datetime.now().isoformat(timespec="seconds"),
                "setup_steps": steps,
            },
            save=True,
        )
        self._set_status("Первичная настройка сохранена. Можно продолжать работу.")
        self.go_back()
    def _welcome_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Добро пожаловать", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._label(
            card,
            "Мастер поможет выбрать плату Arduino, проверить порт и камеру, привязать аккаунт, выбрать конфигурацию устройства и убедиться, что связь с сайтом готова.",
        ).pack(anchor="w", fill="x")
    def _board_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Выбор платы Arduino", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        values = list(self.BOARD_LABELS)
        labels = [self.BOARD_LABELS[item] for item in values]
        if self.board_var.get() not in values:
            self.board_var.set(values[0])
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(row, text="Плата", style=SURFACE_LABEL_STYLE, width=22).pack(side="left")
        combo = ttk.Combobox(row, values=labels, state="readonly")
        combo.current(values.index(self.board_var.get()))
        combo.pack(side="left", fill="x", expand=True)
        fqbn_var = tk.StringVar(value=self._selected_fqbn())
        fqbn_row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        fqbn_row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(fqbn_row, text="Выбранный FQBN", style=SURFACE_LABEL_STYLE, width=22).pack(side="left")
        ttk.Label(fqbn_row, textvariable=fqbn_var, style=MUTED_LABEL_STYLE, wraplength=self._wraplength()).pack(side="left", fill="x", expand=True)
        manual_row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        ttk.Label(manual_row, text="Ручной FQBN", style=SURFACE_LABEL_STYLE, width=22).pack(side="left")
        ttk.Entry(manual_row, textvariable=self.manual_fqbn_var).pack(side="left", fill="x", expand=True)
        port_row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        port_row.pack(fill="x", pady=self.app.pad(4))
        ttk.Label(port_row, text="Порт Arduino", style=SURFACE_LABEL_STYLE, width=22).pack(side="left")
        ports = self.app.arduino.list_ports()
        port_combo = ttk.Combobox(port_row, textvariable=self.port_var, values=ports, state="readonly" if ports else "normal")
        port_combo.pack(side="left", fill="x", expand=True)
        ttk.Button(port_row, text="Авто", command=self._auto_detect_port).pack(side="left", padx=self.app.pad(4))
        action_row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        action_row.pack(fill="x", pady=(self.app.pad(4), 0))
        ttk.Button(action_row, text="Проверить Arduino", style=ACCENT_BUTTON_STYLE, command=self._check_arduino).pack(side="left", padx=self.app.pad(3))
        ttk.Button(action_row, text="Обновить порты", command=self._refresh_ports).pack(side="left", padx=self.app.pad(3))
        def refresh_board_details() -> None:
            if not self._active():
                return
            fqbn_var.set(self._selected_fqbn())
            if self.board_var.get() == "manual":
                manual_row.pack(fill="x", pady=self.app.pad(4), after=fqbn_row)
            else:
                manual_row.pack_forget()
        def changed(_event=None) -> None:
            if not self._active():
                return
            index = combo.current()
            if index >= 0:
                self.board_var.set(values[index])
            refresh_board_details()
        combo.bind(COMBOBOX_SELECTED_EVENT, changed)
        self.manual_fqbn_var.trace_add("write", lambda *_args: self._active() and fqbn_var.set(self._selected_fqbn()))
        refresh_board_details()
    def _arduino_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Подключение Arduino", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        ports = self.app.arduino.list_ports()
        self._label(card, "Выберите порт Arduino и запустите проверку. Демо-режим здесь не включается.").pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(row, text="Порт", style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
        combo = ttk.Combobox(row, textvariable=self.port_var, values=ports, state="readonly" if ports else "normal")
        combo.pack(side="left", fill="x", expand=True)
        ttk.Button(row, text="Обновить", command=self._refresh_ports).pack(side="left", padx=self.app.pad(4))
        ttk.Button(card, text="Проверить Arduino", style=ACCENT_BUTTON_STYLE, command=self._check_arduino).pack(anchor="w", pady=self.app.pad(4))
        occupied = self.app.extra_config.port_summary()
        self._label(card, f"Плата конфигурации: {occupied['board'].name}. Свободно цифровых {occupied['digital_free']}, аналоговых {occupied['analog_free']}.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x")
    def _camera_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Проверка камеры", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._label(card, "Камера нужна для фото и OpenCV/HSV-анализа. Если камеры пока нет, запуск приложения не ломается.").pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Label(row, text="Индекс камеры", style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
        ttk.Entry(row, textvariable=self.camera_index_var, width=10).pack(side="left")
        ttk.Button(card, text="Проверить камеру", style=ACCENT_BUTTON_STYLE, command=self._check_camera).pack(anchor="w", pady=self.app.pad(4))
    def _account_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Привязка аккаунта через QR", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        token = self.app.settings.get("token", "").strip()
        device_id = self.app.settings.get("device_id", "").strip()
        if token and device_id:
            self._label(card, f"Аккаунт уже привязан. device_id: {device_id}").pack(anchor="w", fill="x")
        else:
            self._label(card, "Можно привязать устройство к fitodomik.ru через QR. Если сайта пока нет под рукой, этот шаг можно пропустить.").pack(anchor="w", fill="x")
        row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
        row.pack(fill="x", pady=self.app.pad(6))
        ttk.Button(row, text="Создать QR-привязку", style=ACCENT_BUTTON_STYLE, command=self.app.start_device_pair_qr).pack(side="left", padx=self.app.pad(3))
        ttk.Button(row, text="Проверить токен", command=self.app.check_api_token).pack(side="left", padx=self.app.pad(3))
    def _config_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Выбор конфигурации устройства", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        options = [
            ("standard", "Стандартная схема ФитоДомик: BME280, MH-Z19B, YL-69 и 4 реле"),
            ("empty", "Пустое устройство: сначала видны только Часы и Настройки"),
            ("custom", "Своя конфигурация: показываются только подключённые датчики и реле"),
        ]
        for value, text in options:
            ttk.Radiobutton(card, text=text, variable=self.config_mode_var, value=value).pack(anchor="w", pady=self.app.pad(3))
        ttk.Button(card, text="Загрузить конфигурацию с сайта", command=self._import_config_from_site).pack(anchor="w", pady=self.app.pad(6))
    def _site_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Проверка связи с сайтом", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        self._label(card, "Проверяю доступ к fitodomik.ru по текущему токену и device_id/device_token. Без привязки сайт можно подключить позже.").pack(anchor="w", fill="x")
        ttk.Button(card, text="Проверить связь", style=ACCENT_BUTTON_STYLE, command=self._check_site).pack(anchor="w", pady=self.app.pad(6))
    def _summary_step(self) -> None:
        if not self._active():
            return
        card = Card(self.content, "Финальный отчёт", self.app.palette)
        card.pack(fill="x", pady=self.app.pad(6))
        items = [
            ("Плата", self.BOARD_LABELS.get(self.board_var.get(), self.board_var.get())),
            ("Порт Arduino", self.port_var.get() or "не выбран"),
            ("Камера", self.camera_index_var.get() or "0"),
            ("Аккаунт", "привязан" if self.app.settings.get("token", "").strip() else "не привязан"),
            ("device_id", self.app.settings.get("device_id", "") or "нет"),
            ("Конфигурация", self.config_mode_var.get()),
        ]
        for key, value in items:
            row = ttk.Frame(card, style=SURFACE_FRAME_STYLE)
            row.pack(fill="x", pady=self.app.pad(2))
            ttk.Label(row, text=key, style=SURFACE_LABEL_STYLE, width=18).pack(side="left")
            ttk.Label(row, text=str(value), style=MUTED_LABEL_STYLE).pack(side="left", fill="x", expand=True)
        self._label(card, "После завершения можно добавить датчики, реле, сценарии и включить отправку dynamic values на сайт.", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=(self.app.pad(6), 0))
    def _save_board(self) -> None:
        if not self._active():
            return
        board = self.board_var.get().strip() or "arduino_nano"
        if board != "manual":
            try:
                self.app.extra_config.set_board(board)
            except Exception:
                pass
        self.app.settings.update(
            {
                "extra_sensors_board": board,
                "extra_sensors_manual_fqbn": self.manual_fqbn_var.get().strip(),
                "setup_steps": {**dict(self.app.settings.get("setup_steps", {}) or {}), "board_selected": True},
            },
            save=True,
        )
    def _save_port(self) -> None:
        if not self._active():
            return
        self.app.settings.update(
            {
                "port": self.port_var.get().strip(),
                "setup_steps": {**dict(self.app.settings.get("setup_steps", {}) or {}), "arduino_checked": True},
            },
            save=True,
        )
    def _save_camera(self) -> None:
        if not self._active():
            return
        try:
            index = int(self.camera_index_var.get())
        except ValueError:
            index = 0
        self.app.settings.update(
            {
                "camera_index": index,
                "setup_steps": {**dict(self.app.settings.get("setup_steps", {}) or {}), "camera_checked": True},
            },
            save=True,
        )
    def _save_config_mode(self) -> None:
        if not self._active():
            return
        mode = self.config_mode_var.get().strip() or "standard"
        if mode == "empty":
            self.app.device_config.use_empty_config(save=True)
            self.app.sensor_history.clear()
            self.app._after_device_config_changed("Включена пустая конфигурация устройства")
        elif mode == "standard":
            self.app.use_standard_device_config()
        else:
            self.app.settings.set("device_config_mode", "custom", save=True)
        self.app.settings.update(
            {"setup_steps": {**dict(self.app.settings.get("setup_steps", {}) or {}), "device_config_selected": True}},
            save=True,
        )
    def _refresh_ports(self) -> None:
        if not self._active():
            return
        ports = self.app.arduino.list_ports()
        self._set_status("Найденные порты: " + (", ".join(ports) if ports else "пока нет"))
        self.render()
    def _auto_detect_port(self) -> None:
        if not self._active():
            return
        port = self.app.arduino.auto_detect_port() or ""
        self.port_var.set(port)
        self._set_status(f"Автоопределение порта: {port or 'порт не найден'}")
    def _check_arduino(self) -> None:
        if not self._active():
            return
        self._save_port()
        self._set_status("Пробую подключиться к Arduino...")
        self.app.start_arduino()
        self._safe_after(1800, lambda: self._set_status("Команда подключения отправлена. Смотрите статус Arduino в нижней строке."))
    def _check_camera(self) -> None:
        if not self._active():
            return
        self._set_status("Проверяю камеру в фоне...")
        def worker() -> None:
            try:
                import cv2
                index = int(self.camera_index_var.get())
                cap = cv2.VideoCapture(index)
                ok, _frame = cap.read()
                cap.release()
                message = "Камера отвечает" if ok else "Камера не вернула кадр"
            except Exception as exc:
                message = f"Камера недоступна: {exc}"
            self._safe_after(0, lambda: self._set_status(message))
        threading.Thread(target=worker, daemon=True).start()
    def _check_site(self) -> None:
        if not self._active():
            return
        self._set_status("Проверяю сайт...")
        def worker() -> None:
            ok = self.app.api.check_token()
            message = "Связь с сайтом есть" if ok else "Сайт не подтверждён: проверьте токен или подключите аккаунт позже"
            self._safe_after(0, lambda: self._set_status(message))
        threading.Thread(target=worker, daemon=True).start()
    def _import_config_from_site(self) -> None:
        if not self._active():
            return
        self._set_status("Загружаю конфигурацию с сайта...")
        def worker() -> None:
            data = self.app.api.get_device_config()
            self._safe_after(0, lambda: self._remote_config_loaded(data))
        threading.Thread(target=worker, daemon=True).start()
    def _remote_config_loaded(self, data) -> None:
        if not self._active():
            return
        if not data:
            self._set_status("Конфигурация с сайта не получена")
            return
        self.app.apply_remote_device_config(data)
        if not self._active():
            return
        self.config_mode_var.set("custom")
        self._set_status("Конфигурация с сайта применена локально")
    def _label(self, parent, text: str, style: str = SURFACE_LABEL_STYLE) -> ttk.Label:
        label = ttk.Label(parent, text=text, style=style, wraplength=self._wraplength())
        self.wrap_labels.append(label)
        return label
    def _wraplength(self) -> int:
        if not self._widget_alive(self):
            return 320
        width = self.winfo_width()
        if width <= 1:
            width = self.app.content_width()
        return max(260, min(760, width - self.app.pad(48)))
    def _refresh_wraplengths(self, _event=None) -> None:
        if not self._active():
            return
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
        if not self._active() or not self._widget_alive(getattr(self, "content", None)):
            return
        try:
            children = list(self.content.winfo_children())
        except tk.TclError:
            return
        for child in children:
            if isinstance(child, Card):
                child.set_padding(self.app.pad(10, minimum=8, maximum=12))
                child.set_title_font(self.app.theme.fonts["card_title"])
        self._refresh_wraplengths()
    def _selected_fqbn(self) -> str:
        board = self.board_var.get().strip() or "arduino_nano"
        if board == "manual":
            return self.manual_fqbn_var.get().strip() or "укажите FQBN вручную"
        try:
            return self.app.extra_registry.board(board).fqbn
        except Exception:
            return self.app.extra_registry.board("arduino_nano").fqbn
    def _active(self) -> bool:
        return bool(self.active and self._widget_alive(self) and self._widget_alive(getattr(self, "content", None)))
    def _widget_alive(self, widget) -> bool:
        try:
            return widget is not None and bool(widget.winfo_exists())
        except tk.TclError:
            return False
    def _set_status(self, message: str) -> None:
        if not self._active():
            return
        try:
            self.status_var.set(message)
        except tk.TclError:
            pass
    def _safe_after(self, delay_ms: int, callback) -> None:
        if not self._active():
            return
        holder: dict[str, str] = {}
        def wrapped() -> None:
            after_id = holder.get("id", "")
            if after_id:
                self._after_ids.discard(after_id)
            if self._active():
                callback()
        try:
            after_id = self.after(delay_ms, wrapped)
            holder["id"] = after_id
            self._after_ids.add(after_id)
        except tk.TclError:
            pass
    def deactivate(self) -> None:
        if not self.active:
            return
        self.active = False
        for after_id in self._after_ids:
            try:
                self.after_cancel(after_id)
            except tk.TclError:
                pass
        self._after_ids.clear()
    def _on_destroy(self, event) -> None:
        if event.widget is self:
            self.deactivate()
