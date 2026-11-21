from __future__ import annotations
from tkinter import ttk
from fitodomik_app.core.models import AnalysisResult
from fitodomik_app.ui.style_names import ACCENT_BUTTON_STYLE, APP_FRAME_STYLE, MUTED_LABEL_STYLE, SURFACE_LABEL_STYLE
from fitodomik_app.ui.widgets import Card, load_resized_photo
class AnalysisTab(ttk.Frame):
    PREVIEW_MAX_WIDTH = 260
    PREVIEW_MAX_HEIGHT = 160
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.original_photo = None
        self.detection_photo = None
        self._last_result: AnalysisResult | None = None
        self._last_is_auto = False
        self._build()
    def _build(self) -> None:
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        ttk.Label(self.top, text="Анализ растения", style="Title.TLabel").pack(side="left")
        self.next_photo_label = ttk.Label(self.top, text="", style=MUTED_LABEL_STYLE)
        self.next_photo_label.pack(side="right")
        self.actions = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.actions.pack(fill="x", padx=self.app.pad(18), pady=self.app.pad(6))
        self.analysis_button = ttk.Button(
            self.actions,
            text="Сделать фото и анализ",
            style=ACCENT_BUTTON_STYLE,
            command=self.app.run_plant_analysis,
        )
        self.analysis_button.pack(side="left")
        self.status_label = ttk.Label(self.actions, text="Камера не активна", style=MUTED_LABEL_STYLE)
        self.status_label.pack(side="left", padx=self.app.pad(12))
        self.images = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.images.pack(fill="both", expand=True, padx=self.app.pad(18), pady=self.app.pad(8))
        self.images.columnconfigure(0, weight=1)
        self.images.columnconfigure(1, weight=1)
        self.images.rowconfigure(0, weight=1)
        self.original_card = Card(
            self.images,
            "Оригинальное фото",
            self.app.palette,
            padding=self.app.pad(12),
            title_font=self.app.theme.fonts["card_title"],
        )
        self.original_card.grid(row=0, column=0, sticky="nsew", padx=(0, self.app.pad(6)))
        self.original_label = ttk.Label(self.original_card, text="Нет изображения", style=SURFACE_LABEL_STYLE)
        self.original_label.configure(anchor="center")
        self.original_label.pack(expand=True, fill="both")
        self.detection_card = Card(
            self.images,
            "Результат HSV-анализа",
            self.app.palette,
            padding=self.app.pad(12),
            title_font=self.app.theme.fonts["card_title"],
        )
        self.detection_card.grid(row=0, column=1, sticky="nsew", padx=(self.app.pad(6), 0))
        self.detection_label = ttk.Label(self.detection_card, text="Нет изображения", style=SURFACE_LABEL_STYLE)
        self.detection_label.configure(anchor="center")
        self.detection_label.pack(expand=True, fill="both")
        self.result_card = Card(
            self,
            "Отчёт",
            self.app.palette,
            padding=self.app.pad(12),
            title_font=self.app.theme.fonts["card_title"],
        )
        self.result_card.pack(fill="x", padx=self.app.pad(18), pady=(0, self.app.pad(18)))
        self.result_label = ttk.Label(
            self.result_card,
            text="Нет данных",
            style=SURFACE_LABEL_STYLE,
            wraplength=self._result_wraplength(),
            justify="left",
        )
        self.result_label.pack(anchor="w", fill="x")
    def set_busy(self) -> None:
        self.status_label.configure(text="Анализ выполняется...")
    def set_result(self, result: AnalysisResult, is_auto: bool = False) -> None:
        self._last_result = result
        self._last_is_auto = is_auto
        self.original_photo = load_resized_photo(result.original_path, self._image_size())
        self.detection_photo = load_resized_photo(result.detection_path, self._image_size())
        self.original_label.configure(image=self.original_photo, text="")
        self.detection_label.configure(image=self.detection_photo, text="")
        prefix = "Автоматический анализ" if is_auto else "Ручной анализ"
        analysis = result.analysis
        text = (
            f"{prefix}\n"
            f"Состояние: {analysis.get('состояние', '-')}\n"
            f"Цвета: {analysis.get('распределение цветов', '-')}\n"
            f"Детали: {analysis.get('детали', '-')}\n"
            f"Рекомендации: {analysis.get('рекомендации', '-')}\n"
            f"Сервер: {'загружено' if result.uploaded else 'локально'}"
        )
        self.result_label.configure(text=text)
        self.status_label.configure(text=f"Готово. Отчёт: {result.report_path.name}")
    def set_error(self, message: str) -> None:
        self.status_label.configure(text=message)
    def set_next_photo_text(self, text: str) -> None:
        self.next_photo_label.configure(text=text)
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(18), pady=(self.app.pad(18), self.app.pad(8)))
        self.actions.pack_configure(padx=self.app.pad(18), pady=self.app.pad(6))
        self.status_label.pack_configure(padx=self.app.pad(12))
        self.images.pack_configure(padx=self.app.pad(18), pady=self.app.pad(8))
        self.original_card.set_padding(self.app.pad(12))
        self.original_card.set_title_font(self.app.theme.fonts["card_title"])
        self.original_card.grid_configure(padx=(0, self.app.pad(6)))
        self.detection_card.set_padding(self.app.pad(12))
        self.detection_card.set_title_font(self.app.theme.fonts["card_title"])
        self.detection_card.grid_configure(padx=(self.app.pad(6), 0))
        self.result_card.set_padding(self.app.pad(12))
        self.result_card.set_title_font(self.app.theme.fonts["card_title"])
        self.result_card.pack_configure(padx=self.app.pad(18), pady=(0, self.app.pad(18)))
        self.result_label.configure(wraplength=self._result_wraplength())
    def _image_size(self) -> tuple[int, int]:
        return (self.PREVIEW_MAX_WIDTH, self.PREVIEW_MAX_HEIGHT)
    def _result_wraplength(self) -> int:
        return max(320, min(700, self.app.content_width() - 80))
