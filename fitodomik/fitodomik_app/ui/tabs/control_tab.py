from __future__ import annotations
import tkinter as tk
from tkinter import ttk
from fitodomik_app.core.models import SensorSnapshot
from fitodomik_app.ui.style_names import APP_FRAME_STYLE, MUTED_LABEL_STYLE
from fitodomik_app.ui.widgets import Card, StatusPill
class ControlTab(ttk.Frame):
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.mode_var = tk.StringVar(value=self.app.settings.get("control_mode", "manual"))
        self.buttons: dict[str, ttk.Button] = {}
        self.pills: dict[str, StatusPill] = {}
        self.cards: dict[str, Card] = {}
        self.icon_labels: dict[str, ttk.Label] = {}
        self.icon_names: dict[str, str] = {}
        self.card_spacers: dict[str, tuple[ttk.Frame, ttk.Frame]] = {}
        self.relays: list[dict] = []
        self.empty_label: ttk.Label | None = None
        self._build()
    def rebuild(self) -> None:
        for child in self.winfo_children():
            child.destroy()
        self.buttons.clear()
        self.pills.clear()
        self.cards.clear()
        self.icon_labels.clear()
        self.icon_names.clear()
        self.card_spacers.clear()
        self.relays = []
        self.empty_label = None
        self._build()
        self.update_snapshot(self.app.latest_snapshot)
    def _build(self) -> None:
        self.relays = self.app.device_config.control_relays()
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(10), pady=(self.app.pad(8), self.app.pad(3)))
        ttk.Label(self.top, text="Управление", style="Title.TLabel").pack(side="left")
        ttk.Button(self.top, text="Сброс аварии насоса", command=self.app.reset_pump_fault).pack(side="left", padx=self.app.pad(6))
        self.mode_frame = ttk.Frame(self.top, style=APP_FRAME_STYLE)
        self.mode_frame.pack(side="right")
        self.manual_radio = ttk.Radiobutton(
            self.mode_frame,
            text="Ручной",
            variable=self.mode_var,
            value="manual",
            command=self._mode_changed,
        )
        self.manual_radio.pack(side="left", padx=self.app.pad(6))
        self.auto_radio = ttk.Radiobutton(
            self.mode_frame,
            text="Авто",
            variable=self.mode_var,
            value="auto",
            command=self._mode_changed,
        )
        self.auto_radio.pack(side="left", padx=self.app.pad(6))
        self.cards_frame = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.cards_frame.pack(fill="both", expand=True, padx=self.app.pad(8), pady=self.app.pad(3))
        if not self.relays:
            self.empty_label = ttk.Label(
                self.cards_frame,
                text="Реле ещё не добавлены. Управление появится после настройки реле.",
                style=MUTED_LABEL_STYLE,
                wraplength=620,
            )
            self.empty_label.pack(expand=True)
            self._mode_changed()
            return
        for relay in self.relays:
            self._relay_card(relay)
        self._layout_cards()
        self._mode_changed()
    def _relay_card(self, relay: dict) -> None:
        command = str(relay.get("command", relay.get("relay_uid", ""))).upper()
        uid = str(relay.get("relay_uid", command))
        title = self.app.device_config.relay_label(relay)
        card = Card(
            self.cards_frame,
            title,
            self.app.palette,
            padding=self._card_padding(),
            title_font=self.app.font_tuple(10, "bold", maximum=12),
        )
        top_spacer = ttk.Frame(card, style="Surface.TFrame", height=self._top_gap())
        top_spacer.pack(fill="x")
        icon_name = str(relay.get("icon", "control"))
        icon = self.app.icons.get(icon_name, self._icon_size())
        if icon:
            icon_label = ttk.Label(card, image=icon, style="Surface.TLabel")
            setattr(icon_label, "_image_ref", icon)
            icon_label.pack(anchor="center")
            self.icon_labels[uid] = icon_label
            self.icon_names[uid] = icon_name
        pill = StatusPill(
            card,
            self.app.palette,
            width=self._pill_width(),
            height=self._pill_height(),
            font_size=self.app.theme.scale.font(9, maximum=12),
        )
        pill.pack(anchor="center", pady=(self.app.pad(3), self.app.pad(3)))
        button = ttk.Button(
            card,
            text="Переключить",
            style="Control.TButton",
            command=lambda cmd=command: self.app.toggle_device(cmd),
        )
        button.pack(anchor="center", fill="x", ipady=0, pady=self._button_pady())
        bottom_spacer = ttk.Frame(card, style="Surface.TFrame")
        bottom_spacer.pack(fill="both", expand=True)
        self.cards[uid] = card
        self.buttons[uid] = button
        self.pills[uid] = pill
        self.card_spacers[uid] = (top_spacer, bottom_spacer)
    def _layout_cards(self) -> None:
        count = max(1, len(self.relays))
        columns = 4 if self.app.content_width() >= 1400 and count >= 4 else min(2, count)
        rows = (count + columns - 1) // columns
        for col in range(4):
            self.cards_frame.columnconfigure(
                col,
                weight=1 if col < columns else 0,
                uniform="control_cards" if col < columns else "",
            )
        for row in range(max(2, rows)):
            self.cards_frame.rowconfigure(
                row,
                weight=1 if row < rows else 0,
                uniform="control_rows" if row < rows else "",
            )
        for index, relay in enumerate(self.relays):
            uid = str(relay.get("relay_uid", relay.get("command", index)))
            card = self.cards.get(uid)
            if not card:
                continue
            card.grid_forget()
            card.grid(
                row=index // columns,
                column=index % columns,
                sticky="nsew",
                padx=self.app.pad(5),
                pady=self.app.pad(4),
            )
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(10), pady=(self.app.pad(8), self.app.pad(3)))
        self.manual_radio.pack_configure(padx=self.app.pad(6))
        self.auto_radio.pack_configure(padx=self.app.pad(6))
        self.cards_frame.pack_configure(padx=self.app.pad(8), pady=self.app.pad(3))
        if self.empty_label:
            self.empty_label.configure(wraplength=max(260, self.app.content_width() - self.app.pad(80)))
        for uid, card in self.cards.items():
            card.set_padding(self._card_padding())
            card.set_title_font(self.app.font_tuple(10, "bold", maximum=12))
            top_spacer, bottom_spacer = self.card_spacers[uid]
            top_spacer.configure(height=self._top_gap())
            top_spacer.pack_configure(fill="x", expand=False)
            bottom_spacer.pack_configure(fill="both", expand=True)
            icon_label = self.icon_labels.get(uid)
            icon_name = self.icon_names.get(uid)
            if icon_label and icon_name:
                icon = self.app.icons.get(icon_name, self._icon_size())
                icon_label.configure(image=icon)
                setattr(icon_label, "_image_ref", icon)
        for pill in self.pills.values():
            pill.set_size(self._pill_width(), self._pill_height(), self.app.theme.scale.font(9, maximum=12))
        for button in self.buttons.values():
            button.configure(style="Control.TButton")
            button.pack_configure(ipady=0, pady=self._button_pady())
        self._layout_cards()
        self.refresh_palette()
    def update_snapshot(self, snapshot: SensorSnapshot) -> None:
        for relay in self.relays:
            uid = str(relay.get("relay_uid", relay.get("command", "")))
            state = self.app.device_config.relay_state(relay, snapshot)
            pill = self.pills.get(uid)
            if pill:
                label = str(relay.get("on_label" if state else "off_label", "Вкл" if state else "Выкл"))
                pill.set_state(state, label)
            button = self.buttons.get(uid)
            if button:
                text = str(relay.get("turn_off_text" if state else "turn_on_text", "Переключить"))
                button.configure(text=text)
    def _mode_changed(self) -> None:
        mode = self.mode_var.get()
        self.app.settings.set("control_mode", mode, save=True)
        state = tk.NORMAL if mode == "manual" else tk.DISABLED
        for button in self.buttons.values():
            button.configure(state=state)
    def refresh_palette(self) -> None:
        for pill in self.pills.values():
            pill.set_palette(self.app.palette)
        self.update_snapshot(self.app.latest_snapshot)
    def _icon_size(self) -> int:
        return self.app.icon_size(34, maximum=48)
    def _pill_width(self) -> int:
        return self.app.sx(96, minimum=92, maximum=132)
    def _pill_height(self) -> int:
        return self.app.sx(26, minimum=24, maximum=32)
    def _card_padding(self) -> int:
        return self.app.pad(7, minimum=6, maximum=10)
    def _top_gap(self) -> int:
        return self.app.pad(3, minimum=1, maximum=6)
    def _button_pady(self) -> tuple[int, int]:
        return (self.app.pad(1), self.app.pad(2))
