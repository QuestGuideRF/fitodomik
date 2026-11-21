from __future__ import annotations
from datetime import datetime, timedelta
import tkinter as tk
from tkinter import ttk
from typing import Any, cast
from fitodomik_app.ui.style_names import APP_FRAME_STYLE, COMBOBOX_SELECTED_EVENT, MUTED_LABEL_STYLE
from fitodomik_app.ui.widgets import ScrollableFrame
class ChartsTab(ttk.Frame):
    UPDATE_DELAY_MS = 200
    MAX_RENDER_POINTS = 300
    CHART_GRID_COLUMNS_COMPACT = 2
    CHART_GRID_COLUMNS_WIDE = 3
    CHART_CARD_MIN_WIDTH = 250
    CHART_CARD_MIN_HEIGHT = 135
    CHART_CARD_MAX_HEIGHT = 170
    CHART_FIGURE_DPI = 100
    TITLE_FONT_SIZE = 8
    AXIS_LABEL_FONT_SIZE = 7
    TICK_FONT_SIZE = 6
    LINE_WIDTH = 1.4
    PERIODS = {
        "Последний час": 1,
        "Последние 3 часа": 3,
        "Последние 6 часов": 6,
        "Сутки": 24,
    }
    def __init__(self, parent, app) -> None:
        super().__init__(parent, style=APP_FRAME_STYLE)
        self.app = app
        self.period_var = tk.StringVar(value="Последний час")
        self._initialized = False
        self._figures = []
        self._axes = {}
        self._canvases = {}
        self._cards = {}
        self._chart_widgets = {}
        self._last_signatures = {}
        self.chart_sensors: list[dict] = []
        self._backend_loading = False
        self._loading_data = False
        self._reload_pending = False
        self._generation = 0
        self._request_id = 0
        self._figure_class: Any | None = None
        self._canvas_class: Any | None = None
        self.mdates: Any | None = None
        self._layout_signature: tuple[int, int, int] | None = None
        self._resize_after_id: str | None = None
        self._update_after_id: str | None = None
        self._build_after_id: str | None = None
        self._render_after_id: str | None = None
        self._initializing = False
        self._lines: dict[str, Any] = {}
        self._messages: dict[str, Any] = {}
        self._pending_series: dict[str, tuple[float, list]] = {}
        self._plot_sizes: dict[str, tuple[int, int]] = {}
        self._build_shell()
        self.bind("<Destroy>", self._on_destroy, add="+")
    def _cancel_work(self) -> None:
        for name in ("_resize_after_id", "_update_after_id", "_build_after_id", "_render_after_id"):
            token = getattr(self, name)
            if token is not None:
                self.after_cancel(token)
                setattr(self, name, None)
    def _on_destroy(self, event) -> None:
        if event.widget is self:
            self._cancel_work()
    def rebuild(self) -> None:
        self._generation += 1
        self._request_id += 1
        self._cancel_work()
        self._initializing = False
        self._lines.clear()
        self._messages.clear()
        self._pending_series.clear()
        self._plot_sizes.clear()
        for child in self.winfo_children():
            child.destroy()
        self._initialized = False
        self._figures = []
        self._axes = {}
        self._canvases = {}
        self._cards = {}
        self._chart_widgets = {}
        self._last_signatures = {}
        self.chart_sensors = []
        self._backend_loading = False
        self._loading_data = False
        self._reload_pending = False
        self._figure_class = None
        self._canvas_class = None
        self.mdates = None
        self._layout_signature = None
        self._build_shell()
    def _build_shell(self) -> None:
        self.top = ttk.Frame(self, style=APP_FRAME_STYLE)
        self.top.pack(fill="x", padx=self.app.pad(12), pady=(self.app.pad(12), self.app.pad(6)))
        self.top.columnconfigure(0, weight=1)
        ttk.Label(self.top, text="Графики", style="Title.TLabel").grid(row=0, column=0, sticky="w")
        controls = ttk.Frame(self.top, style=APP_FRAME_STYLE)
        controls.grid(row=0, column=1, sticky="e")
        self.period_label = ttk.Label(controls, text="Период:")
        self.period_label.pack(side="left", padx=(0, self.app.pad(6)))
        self.period_combo = ttk.Combobox(
            controls,
            textvariable=self.period_var,
            values=list(self.PERIODS),
            state="readonly",
            width=16,
        )
        self.period_combo.pack(side="left")
        self.period_combo.bind(COMBOBOX_SELECTED_EVENT, lambda _event: self.update_charts(show_loading=True))
        self.scroll = ScrollableFrame(self, self.app.palette)
        self.scroll.pack(fill="both", expand=True, padx=self.app.pad(8), pady=(0, self.app.pad(6)))
        self.scroll.canvas.bind("<Configure>", self._on_canvas_resize, add="+")
        self.scroll.canvas.configure(yscrollcommand=self._on_scroll)
        self.container = self.scroll.inner
        self.message = ttk.Label(
            self.container,
            text="Графики загрузятся при открытии вкладки",
            style=MUTED_LABEL_STYLE,
        )
        self.message.pack(expand=True, pady=24)
    def on_show(self) -> None:
        if self._initialized:
            self._layout_chart_cards()
            self._schedule_render()
            self.update_charts()
            return
        if self._initializing:
            if self._build_after_id is None:
                self._build_after_id = self.after(1, self._build_next_chart)
            return
        self.chart_sensors = self.app.device_config.chart_sensors()
        if not self.chart_sensors:
            self._show_message("Нет подключённых датчиков")
            return
        self._show_message("Подготовка осей графиков...")
        self._start_backend_load()
    def update_charts(self, show_loading: bool = False) -> None:
        # Coalesce notifications only; measurement history remains untouched.
        if self.app.active_tab != "charts":
            return
        if not self._initialized:
            self.on_show()
            return
        if self._loading_data:
            self._reload_pending = True
            return
        if self._update_after_id is None:
            self._update_after_id = self.after(self.UPDATE_DELAY_MS, self._dispatch_update)
    def _dispatch_update(self) -> None:
        self._update_after_id = None
        if self.app.active_tab != "charts" or not self._initialized:
            return
        self._request_id += 1
        hours = self._selected_hours()
        request_id = self._request_id
        generation = self._generation
        sensors = [dict(sensor) for sensor in self.chart_sensors]
        self._loading_data = True
        self.app.task_manager.run_once(
            f"charts_data:{id(self)}:{generation}:{request_id}",
            lambda: self._load_points_worker(generation, request_id, hours, sensors),
        )
    def _start_backend_load(self) -> None:
        if self._backend_loading:
            return
        self._backend_loading = True
        generation = self._generation
        self.app.task_manager.run_once(
            f"charts_backend:{id(self)}:{generation}",
            lambda: self._load_backend_worker(generation),
        )
    def _load_backend_worker(self, generation: int) -> None:
        try:
            from matplotlib.backends.backend_tkagg import FigureCanvasTkAgg
            from matplotlib.figure import Figure
            import matplotlib.dates as mdates
        except Exception as exc:
            self.app.event_bus.post(
                "charts_backend_error",
                {"tab_token": id(self), "generation": generation},
                level="error",
                message=f"Matplotlib недоступен: {exc}",
            )
            return
        self.app.event_bus.post(
            "charts_backend_ready",
            {
                "tab_token": id(self),
                "generation": generation,
                "canvas_class": FigureCanvasTkAgg,
                "figure_class": Figure,
                "mdates": mdates,
            },
        )
    def _load_points_worker(self, generation: int, request_id: int, hours: float, sensors: list[dict]) -> None:
        try:
            items = self.app.sensor_history.since(hours)
            series = {
                self._chart_key(index, sensor): self._downsample(self._sensor_points(sensor, items, hours))
                for index, sensor in enumerate(sensors)
            }
        except Exception as exc:
            self.app.event_bus.post(
                "charts_data_error",
                {"tab_token": id(self), "generation": generation, "request_id": request_id},
                level="error",
                message=f"Не удалось загрузить данные графика: {exc}",
            )
            return
        self.app.event_bus.post(
            "charts_data_loaded",
            {
                "tab_token": id(self),
                "generation": generation,
                "request_id": request_id,
                "hours": hours,
                "series": series,
            },
        )
    def handle_background_event(self, name: str, payload: object, message: str = "") -> None:
        data = payload if isinstance(payload, dict) else {}
        if data.get("tab_token") != id(self) or data.get("generation") != self._generation:
            return
        if name == "charts_backend_ready":
            self._backend_loading = False
            self._canvas_class = data.get("canvas_class")
            self._figure_class = data.get("figure_class")
            self.mdates = data.get("mdates")
            self._init_matplotlib()
            return
        if name == "charts_backend_error":
            self._backend_loading = False
            self._show_message(message or "Matplotlib недоступен")
            return
        self._loading_data = False
        if data.get("request_id") != self._request_id:
            self._schedule_pending_reload()
            return
        if name == "charts_data_error":
            self._last_signatures = {}
            self._render_skeleton(message or "Ошибка загрузки данных")
        elif name == "charts_data_loaded":
            if data.get("hours") == self._selected_hours():
                self._render_loaded_data(data)
            else:
                self._reload_pending = True
        self._schedule_pending_reload()
    def _render_loaded_data(self, payload: dict) -> None:
        hours = float(payload.get("hours", self._selected_hours()))
        raw_series = payload.get("series")
        series = cast(dict[str, list[tuple[object, float]]], raw_series) if isinstance(raw_series, dict) else {}
        for index, sensor in enumerate(self.chart_sensors):
            key = self._chart_key(index, sensor)
            points = series.get(key, [])
            self._pending_series[key] = (hours, points)
        self._schedule_render()
    def _on_scroll(self, first, last) -> None:
        self.scroll.scrollbar.set(first, last)
        self._schedule_render()
    def _visible_keys(self) -> list[str]:
        if self.app.active_tab != "charts" or not self._initialized:
            return []
        top = self.scroll.canvas.canvasy(0)
        bottom = top + self.scroll.canvas.winfo_height()
        return [key for key, card in self._cards.items()
                if card.winfo_y() < bottom and card.winfo_y() + card.winfo_height() > top]
    def _schedule_render(self) -> None:
        if self._pending_series and self._render_after_id is None and self.app.active_tab == "charts":
            self._render_after_id = self.after(1, self._render_next)
    def _render_next(self) -> None:
        self._render_after_id = None
        visible = self._visible_keys()
        key = next((key for key in visible if key in self._pending_series), None)
        if key is None:
            return
        hours, points = self._pending_series.pop(key)
        index = list(self._cards).index(key)
        sensor = self.chart_sensors[index]
        signature = self._points_signature(key, hours, points)
        if self._last_signatures.get(key) != signature:
            self._last_signatures[key] = signature
            axis = self._axes[key]
            line = self._lines[key]
            x_values, y_values = zip(*points) if points else ([], [])
            line.set_data(x_values, y_values)
            line.set_marker("o" if 0 < len(points) <= 80 else "None")
            self._messages[key].set_text("Нет данных для графика")
            self._messages[key].set_visible(not points)
            self._apply_axis_limits(axis, sensor, hours, points)
            # One synchronous canvas per turn, rather than a burst of idle draws.
            self._canvases[key].draw()
        if any(key in self._pending_series for key in visible):
            self._schedule_render()
    def _schedule_pending_reload(self) -> None:
        if not self._reload_pending:
            return
        self._reload_pending = False
        self.update_charts()
    def _render_skeleton(self, message: str) -> None:
        hours = self._selected_hours()
        for index, sensor in enumerate(self.chart_sensors):
            key = self._chart_key(index, sensor)
            axis = self._axes.get(key)
            canvas = self._canvases.get(key)
            if axis is None or canvas is None:
                continue
            if self._last_signatures.get(key):
                continue
            self._apply_axis_limits(axis, sensor, hours)
            self._messages[key].set_text(message)
            self._messages[key].set_visible(True)
            canvas.draw_idle()
    def _set_axis_labels(self, axis, sensor: dict) -> None:
        axis.set_xlabel("Время", color=self.app.palette.text, fontsize=self._font_size(self.AXIS_LABEL_FONT_SIZE, maximum=9))
        axis.set_title(
            self.app.device_config.sensor_label(sensor),
            color=self.app.palette.text,
            fontsize=self._font_size(self.TITLE_FONT_SIZE, maximum=10),
            pad=3,
        )
        axis.set_ylabel(
            str(sensor.get("unit", "")),
            color=self.app.palette.text,
            fontsize=self._font_size(self.AXIS_LABEL_FONT_SIZE, maximum=9),
            labelpad=2,
        )
    def _show_message(self, message: str) -> None:
        if self.message.winfo_exists():
            self.message.configure(text=message)
            self.message.pack(expand=True, pady=24)
    def _sensor_points(self, sensor: dict, items: list, hours: float) -> list[tuple[object, float]]:
        if self.app.device_config.is_extra_sensor(sensor):
            sensor_id = str(sensor.get("sensor_uid", ""))
            points = []
            for item in self.app.extra_history.series(sensor_id, hours=hours):
                value = item.as_float()
                if value is not None:
                    points.append((item.timestamp, float(value)))
            return points
        return self.app.device_config.sensor_points(sensor, items)
    def _downsample(self, points: list[tuple[object, float]]) -> list[tuple[object, float]]:
        if len(points) <= self.MAX_RENDER_POINTS:
            return points
        step = (len(points) - 1) / (self.MAX_RENDER_POINTS - 1)
        return [points[int(round(index * step))] for index in range(self.MAX_RENDER_POINTS)]
    def _points_signature(self, key: str, hours: float, points: list[tuple[object, float]]) -> tuple:
        return (key, hours, tuple(points))
    def _init_matplotlib(self) -> None:
        if self._canvas_class is None or self._figure_class is None or self.mdates is None:
            self._show_message("Matplotlib недоступен")
            return
        for child in self.container.winfo_children():
            child.destroy()
        self._figures = []
        self._axes = {}
        self._canvases = {}
        self._cards = {}
        self._chart_widgets = {}
        self._last_signatures = {}
        self._layout_signature = None
        self._initializing = True
        self._build_after_id = self.after(1, self._build_next_chart)
    def _build_next_chart(self) -> None:
        self._build_after_id = None
        if self.app.active_tab != "charts":
            return
        if self._figure_class is None or self._canvas_class is None or self.mdates is None:
            return
        index = len(self._figures)
        if index >= len(self.chart_sensors):
            self._initializing = False
            self._initialized = True
            self._layout_chart_cards(force=True)
            self.refresh_palette()
            self.update_charts()
            return
        sensor = self.chart_sensors[index]
        key = self._chart_key(index, sensor)
        figure = self._figure_class(
            figsize=(self.CHART_CARD_MIN_WIDTH / self.CHART_FIGURE_DPI, self.CHART_CARD_MIN_HEIGHT / self.CHART_FIGURE_DPI),
            dpi=self.CHART_FIGURE_DPI, facecolor=self.app.palette.surface,
        )
        self._adjust_figure(figure)
        axis = figure.add_subplot(111)
        self._style_axis(axis)
        self._set_axis_labels(axis, sensor)
        axis.xaxis.set_major_locator(self.mdates.AutoDateLocator(minticks=3, maxticks=5))
        axis.xaxis.set_major_formatter(self.mdates.DateFormatter("%H:%M"))
        self._apply_axis_limits(axis, sensor, self._selected_hours())
        self._lines[key], = axis.plot([], [], color=self._chart_color(index), linewidth=self.LINE_WIDTH,
                                      markersize=2.2, markeredgewidth=0)
        self._messages[key] = axis.text(0.5, 0.5, "Загрузка данных...", transform=axis.transAxes,
                                        ha="center", va="center", color=self.app.palette.muted,
                                        fontsize=self._font_size(8, maximum=10))
        card = ttk.Frame(self.container, style="Card.TFrame", padding=self.app.pad(4))
        card.grid_propagate(False)
        canvas = self._canvas_class(figure, master=card)
        widget = canvas.get_tk_widget()
        widget.configure(bg=self.app.palette.surface, highlightthickness=0)
        widget.pack(fill="both", expand=True)
        self._figures.append(figure)
        self._axes[key] = axis
        self._canvases[key] = canvas
        self._cards[key] = card
        self._chart_widgets[key] = widget
        self._layout_chart_cards(force=True)
        self._build_after_id = self.after(1, self._build_next_chart)
    def _on_canvas_resize(self, event) -> None:
        if not self._initialized:
            return
        if event.width < 120:
            return
        if self._resize_after_id is not None:
            try:
                self.after_cancel(self._resize_after_id)
            except tk.TclError:
                pass
        self._resize_after_id = self.after(80, lambda: self._layout_chart_cards(force=False))
    def _layout_chart_cards(self, force: bool = False) -> None:
        self._resize_after_id = None
        if self.app.active_tab != "charts":
            return
        if not self._cards:
            return
        available_width = self._available_chart_width()
        columns = self._chart_columns_for_width(available_width, len(self.chart_sensors))
        gap = self.app.pad(5, minimum=4, maximum=8)
        card_width = max(
            self.CHART_CARD_MIN_WIDTH,
            int((available_width - (columns * gap * 2) - self.app.pad(4)) / columns),
        )
        card_height = max(
            self.CHART_CARD_MIN_HEIGHT,
            min(self.CHART_CARD_MAX_HEIGHT, int(card_width * 0.54)),
        )
        signature = (columns, card_width, card_height)
        if not force and signature == self._layout_signature:
            return
        self._layout_signature = signature
        for column in range(self.CHART_GRID_COLUMNS_WIDE):
            weight = 1 if column < columns else 0
            self.container.columnconfigure(column, weight=weight, uniform="charts" if weight else "")
        for index, sensor in enumerate(self.chart_sensors):
            key = self._chart_key(index, sensor)
            card = self._cards.get(key)
            figure = self._figures[index] if index < len(self._figures) else None
            widget = self._chart_widgets.get(key)
            canvas = self._canvases.get(key)
            if card is None or figure is None or widget is None or canvas is None:
                continue
            row = index // columns
            column = index % columns
            self.container.rowconfigure(row, weight=0)
            card.grid(row=row, column=column, sticky="nsew", padx=gap, pady=gap)
            card.configure(width=card_width, height=card_height)
            plot_width = max(220, card_width - self.app.pad(12, minimum=10, maximum=18))
            plot_height = max(112, card_height - self.app.pad(10, minimum=8, maximum=14))
            widget.configure(width=plot_width, height=plot_height)
            if self._plot_sizes.get(key) != (plot_width, plot_height):
                self._plot_sizes[key] = (plot_width, plot_height)
                figure.set_size_inches(plot_width / self.CHART_FIGURE_DPI, plot_height / self.CHART_FIGURE_DPI, forward=True)
                self._adjust_figure(figure)
                canvas.draw_idle()
        self._schedule_render()
    def _adjust_figure(self, figure) -> None:
        figure.subplots_adjust(left=0.19, right=0.965, bottom=0.28, top=0.82)
    def _available_chart_width(self) -> int:
        width = 0
        try:
            width = int(self.scroll.canvas.winfo_width())
        except tk.TclError:
            width = 0
        if width <= 1:
            try:
                width = int(self.app.content_width())
            except Exception:
                width = 600
        return max(320, width)
    def _chart_columns_for_width(self, width: int, count: int) -> int:
        if count <= 1:
            return 1
        if width >= 930 and count >= 6:
            return min(self.CHART_GRID_COLUMNS_WIDE, count)
        if width < 560:
            return 1
        return min(self.CHART_GRID_COLUMNS_COMPACT, count)
    def _selected_hours(self) -> float:
        return float(self.PERIODS.get(self.period_var.get(), 1))
    def _period_bounds(self, hours: float, now: datetime | None = None) -> tuple[datetime, datetime]:
        end = now or datetime.now()
        return end - timedelta(hours=float(hours)), end
    def _apply_axis_limits(
        self,
        axis,
        sensor: dict,
        hours: float,
        points: list[tuple[object, float]] | None = None,
    ) -> None:
        start, end = self._period_bounds(hours)
        axis.set_xlim(start, end)
        axis.set_ylim(*self._y_limits(sensor, points or []))
        axis.margins(x=0.01)
    def _y_limits(self, sensor: dict, points: list[tuple[object, float]]) -> tuple[float, float]:
        default_min, default_max = self._default_y_limits(sensor)
        values = [float(value) for _timestamp, value in points]
        if not values:
            return default_min, default_max
        if self._is_percent_sensor(sensor) and min(values) >= 0 and max(values) <= 100:
            return 0.0, 100.0
        low = min(values)
        high = max(values)
        if low == high:
            padding = max(abs(low) * 0.05, 1.0)
        else:
            padding = max((high - low) * 0.10, 1.0)
        low -= padding
        high += padding
        if high <= low:
            high = low + 1.0
        return low, high
    def _default_y_limits(self, sensor: dict) -> tuple[float, float]:
        identity = self._sensor_identity(sensor)
        unit = str(sensor.get("unit", "")).strip().lower()
        if "co2" in identity or "ppm" in unit:
            return 300.0, 1500.0
        if "pressure" in identity or "давлен" in identity:
            return 700.0, 800.0
        if self._is_percent_sensor(sensor):
            return 0.0, 100.0
        if "temp" in identity or "темпера" in identity or "°" in unit:
            return 0.0, 40.0
        return 0.0, 100.0
    def _is_percent_sensor(self, sensor: dict) -> bool:
        identity = self._sensor_identity(sensor)
        unit = str(sensor.get("unit", "")).strip()
        return unit == "%" or "humidity" in identity or "soil" in identity or "влаж" in identity
    def _sensor_identity(self, sensor: dict) -> str:
        parts = (
            sensor.get("sensor_uid", ""),
            sensor.get("sensor_type", ""),
            sensor.get("source_field", ""),
            sensor.get("name", ""),
            sensor.get("unit", ""),
        )
        return " ".join(str(part).lower() for part in parts)
    def _chart_key(self, index: int, sensor: dict) -> str:
        return f"{index}:{sensor.get('sensor_uid', index)}"
    def _style_axis(self, axis) -> None:
        palette = self.app.palette
        axis.set_facecolor(palette.surface)
        axis.figure.set_facecolor(palette.surface)
        axis.grid(True, color=palette.chart_grid, linestyle="--", alpha=0.45, linewidth=0.6)
        axis.tick_params(colors=palette.text, labelsize=self._font_size(self.TICK_FONT_SIZE, maximum=8), pad=1)
        for label in axis.get_xticklabels():
            label.set_rotation(0)
            label.set_ha("center")
        for spine in axis.spines.values():
            spine.set_color(palette.border)
    def _font_size(self, size: int, maximum: int) -> int:
        try:
            return self.app.theme.scale.font(size, maximum=maximum)
        except Exception:
            return min(size, maximum)
    def _chart_color(self, index: int) -> str:
        colors = [
            self.app.palette.accent,
            self.app.palette.good,
            self.app.palette.accent_2,
            self.app.palette.danger,
            self.app.palette.muted,
        ]
        return colors[index % len(colors)]
    def refresh_palette(self) -> None:
        self.scroll.set_palette(self.app.palette)
        self.period_combo.configure(style="TCombobox")
        if self._initialized:
            for index, sensor in enumerate(self.chart_sensors):
                key = self._chart_key(index, sensor)
                self._style_axis(self._axes[key])
                self._set_axis_labels(self._axes[key], sensor)
                self._lines[key].set_color(self._chart_color(index))
                self._messages[key].set_color(self.app.palette.muted)
                self._messages[key].set_fontsize(self._font_size(8, maximum=10))
            for figure in self._figures:
                figure.set_facecolor(self.app.palette.surface)
            for key, card in self._cards.items():
                try:
                    card.configure(style="Card.TFrame")
                    widget = self._chart_widgets.get(key)
                    if widget is not None:
                        widget.configure(bg=self.app.palette.surface)
                except tk.TclError:
                    pass
            self._last_signatures = {}
            self.update_charts(show_loading=True)
    def refresh_scale(self) -> None:
        self.top.pack_configure(padx=self.app.pad(12), pady=(self.app.pad(12), self.app.pad(6)))
        self.scroll.pack_configure(padx=self.app.pad(8), pady=(0, self.app.pad(6)))
        self._layout_signature = None
        if self._initialized:
            for index, sensor in enumerate(self.chart_sensors):
                key = self._chart_key(index, sensor)
                self._style_axis(self._axes[key])
                self._set_axis_labels(self._axes[key], sensor)
                self._messages[key].set_fontsize(self._font_size(8, maximum=10))
            self._layout_chart_cards(force=True)
            self._last_signatures = {}
            self.update_charts()
