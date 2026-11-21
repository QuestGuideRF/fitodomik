from __future__ import annotations
from tkinter import ttk
from fitodomik_app.ui.style_names import APP_FRAME_STYLE, MUTED_LABEL_STYLE, SURFACE_LABEL_STYLE
from fitodomik_app.ui.widgets import Card, ScrollableFrame
class NotificationsTab(ttk.Frame):
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self._build()
    def _build(self) -> None:
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        ttk.Label(self.top, text="Уведомления", style="Title.TLabel").pack(side="left")
        self.refresh_button = ttk.Button(self.top, text="Обновить", command=self.refresh)
        self.refresh_button.pack(side="right", padx=self.app.pad(4))
        self.clear_button = ttk.Button(self.top, text="Очистить", command=self.clear)
        self.clear_button.pack(side="right", padx=self.app.pad(4))
        self.scroll = ScrollableFrame(self, self.app.palette)
        self.scroll.pack(fill="both", expand=True, padx=self.app.pad(18), pady=(self.app.pad(6), self.app.pad(18)))
        self.empty_label = ttk.Label(self.scroll.inner, text="", style=MUTED_LABEL_STYLE)
        self.refresh()
    def on_show(self) -> None:
        self.refresh()
    def refresh(self) -> None:
        for child in self.scroll.inner.winfo_children():
            child.destroy()
        items = list(reversed(self.app.notifications.list()))
        if not items:
            card = Card(self.scroll.inner, "", self.app.palette, padding=self.app.pad(14))
            card.pack(fill="x", expand=True, pady=self.app.pad(6))
            ttk.Label(card, text="Уведомлений пока нет", style=MUTED_LABEL_STYLE).pack(anchor="w", fill="x", pady=self.app.pad(8))
            return
        for item in items[:300]:
            card = Card(self.scroll.inner, "", self.app.palette, padding=self.app.pad(10))
            card.pack(fill="x", expand=True, pady=self.app.pad(5))
            level = str(item.get("level", "info"))
            title = str(item.get("title", "Событие"))
            time_text = str(item.get("time", ""))
            ttk.Label(card, text=f"{time_text} · {title}", style=SURFACE_LABEL_STYLE, font=self.app.font_tuple(10, "bold")).pack(anchor="w")
            ttk.Label(card, text=str(item.get("message", "")), style=MUTED_LABEL_STYLE, wraplength=self._wraplength()).pack(anchor="w", fill="x", pady=(self.app.pad(3), 0))
            ttk.Label(card, text=f"Тип: {item.get('type', 'info')} · Уровень: {level}", style=MUTED_LABEL_STYLE).pack(anchor="w", pady=(self.app.pad(4), 0))
    def clear(self) -> None:
        self.app.notifications.clear()
        self.refresh()
    def refresh_palette(self) -> None:
        if hasattr(self, "scroll"):
            self.scroll.set_palette(self.app.palette)
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        self.refresh_button.pack_configure(padx=self.app.pad(4))
        self.clear_button.pack_configure(padx=self.app.pad(4))
        self.scroll.pack_configure(padx=self.app.pad(18), pady=(self.app.pad(6), self.app.pad(18)))
        self.refresh()
    def _wraplength(self) -> int:
        return max(560, self.app.content_width() - self.app.pad(90))
