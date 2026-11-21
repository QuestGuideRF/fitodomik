from __future__ import annotations
import logging
import os
import platform
import re
import shutil
import subprocess
import threading
import time
from pathlib import Path
from typing import Callable, Protocol
SCREEN_IDLE_TIMEOUT_SECONDS = 60
SCREEN_DIM_BRIGHTNESS = 10
SCREEN_ACTIVE_BRIGHTNESS = 100
SCREEN_IDLE_CHECK_INTERVAL_MS = 1000
class BrightnessController(Protocol):
    def set_percent(self, percent: int) -> None: ...
class SysfsBrightnessController:
    def __init__(self, brightness_path: Path, max_brightness: int) -> None:
        self.brightness_path = brightness_path
        self.max_brightness = max(1, max_brightness)
    @classmethod
    def discover(cls, base_dir: Path = Path("/sys/class/backlight")) -> SysfsBrightnessController | None:
        if not base_dir.is_dir():
            return None
        try:
            device_dirs = sorted(base_dir.iterdir())
        except OSError:
            return None
        for device_dir in device_dirs:
            brightness_path = device_dir / "brightness"
            max_path = device_dir / "max_brightness"
            try:
                max_brightness = int(max_path.read_text(encoding="ascii").strip())
            except (OSError, ValueError):
                continue
            if brightness_path.is_file() and max_brightness > 0:
                return cls(brightness_path, max_brightness)
        return None
    def set_percent(self, percent: int) -> None:
        percent = max(1, min(100, int(percent)))
        raw_value = max(1, round(self.max_brightness * percent / 100))
        self.brightness_path.write_text(str(raw_value), encoding="ascii")
class XrandrBrightnessController:
    _CONNECTED_OUTPUT = re.compile(r"^(\S+)\s+connected(?:\s|$)")
    def __init__(self, output: str) -> None:
        self.output = output
    @classmethod
    def discover(cls) -> XrandrBrightnessController | None:
        if not os.environ.get("DISPLAY") or not shutil.which("xrandr"):
            return None
        configured_output = os.environ.get("FITODOMIK_XRANDR_OUTPUT", "").strip()
        if configured_output:
            return cls(configured_output)
        try:
            result = subprocess.run(
                ["xrandr", "--query"], capture_output=True, text=True, timeout=2, check=False
            )
        except (OSError, subprocess.SubprocessError):
            return None
        for line in result.stdout.splitlines():
            match = cls._CONNECTED_OUTPUT.match(line)
            if match:
                return cls(match.group(1))
        return None
    def set_percent(self, percent: int) -> None:
        brightness = max(0.1, min(1.0, int(percent) / 100))
        subprocess.run(
            ["xrandr", "--output", self.output, "--brightness", f"{brightness:.2f}"],
            capture_output=True,
            timeout=2,
            check=True,
        )
def discover_brightness_controller() -> BrightnessController | None:
    if platform.system() != "Linux":
        return None
    for discover in (SysfsBrightnessController.discover, XrandrBrightnessController.discover):
        try:
            controller = discover()
        except Exception:
            controller = None
        if controller is not None:
            return controller
    return None
class ScreenPowerManager:
    ACTIVITY_EVENTS = ("<ButtonPress>", "<KeyPress>", "<MouseWheel>", "<Button-4>", "<Button-5>")
    def __init__(
        self,
        root,
        logger: logging.Logger,
        controller: BrightnessController | None = None,
        idle_timeout_seconds: int = SCREEN_IDLE_TIMEOUT_SECONDS,
        clock: Callable[[], float] = time.monotonic,
        run_async: bool = True,
    ) -> None:
        self.root = root
        self.logger = logger.getChild("screen_power")
        self.controller = controller if controller is not None else discover_brightness_controller()
        self.idle_timeout_seconds = max(1, idle_timeout_seconds)
        self.clock = clock
        self.run_async = run_async
        self._last_activity = self.clock()
        self._dimmed = False
        self._running = False
        self._after_id: str | None = None
        self._generation = 0
        self._brightness_lock = threading.Lock()
    def start(self) -> None:
        if self._running:
            return
        self._running = True
        if self.controller is None:
            self.logger.warning("Управление яркостью экрана недоступно; режим ожидания продолжит отслеживать активность")
        for sequence in self.ACTIVITY_EVENTS:
            self.root.bind_all(sequence, self._on_activity, add="+")
        self._schedule_check()
    def stop(self) -> None:
        self._running = False
        if self._after_id is not None:
            try:
                self.root.after_cancel(self._after_id)
            except Exception:
                pass
            self._after_id = None
        self._dimmed = False
        self._submit_brightness(SCREEN_ACTIVE_BRIGHTNESS)
    def _on_activity(self, _event=None) -> None:
        self._last_activity = self.clock()
        if self._dimmed:
            self._dimmed = False
            self._submit_brightness(SCREEN_ACTIVE_BRIGHTNESS)
    def _check_idle(self) -> None:
        self._after_id = None
        if not self._running:
            return
        if not self._dimmed and self.clock() - self._last_activity >= self.idle_timeout_seconds:
            self._dimmed = True
            self._submit_brightness(SCREEN_DIM_BRIGHTNESS)
        self._schedule_check()
    def _schedule_check(self) -> None:
        if self._running:
            self._after_id = self.root.after(SCREEN_IDLE_CHECK_INTERVAL_MS, self._check_idle)
    def _submit_brightness(self, percent: int) -> None:
        controller = self.controller
        if controller is None:
            return
        self._generation += 1
        generation = self._generation
        def apply() -> None:
            with self._brightness_lock:
                if generation != self._generation:
                    return
                try:
                    controller.set_percent(percent)
                except Exception as exc:
                    self.logger.warning("Не удалось установить яркость экрана %s%%: %s", percent, exc)
        if self.run_async:
            threading.Thread(target=apply, name="screen-brightness", daemon=True).start()
        else:
            apply()
