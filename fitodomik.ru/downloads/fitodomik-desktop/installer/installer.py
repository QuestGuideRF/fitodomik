from __future__ import annotations
import json
import os
import platform
import queue
import shutil
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Callable
PROJECT_REPO_URL = "ВСТАВИТЬ_ССЫЛКУ_НА_GITHUB_РЕПОЗИТОРИЙ"
APP_NAME = "FitoDomik"
DEFAULT_INSTALL_DIR = Path.home() / "FitoDomik"
DEFAULT_SETTINGS = {
    "theme_mode": "auto",
    "dark_theme_time": "20:00",
    "light_theme_time": "08:00",
    "weather_city": "Moscow",
    "token": "",
    "device_id": "",
    "device_token": "",
    "device_name": "FitoDomik-RPi",
    "port": "",
    "auto_detect_port": True,
    "serial_baudrate": 9600,
    "serial_timeout": 2,
    "camera_index": 0,
    "fullscreen": False,
    "kiosk_mode": False,
    "interface_scale": "auto",
    "command_poll_interval_seconds": 10,
    "failed_upload_retry_interval_seconds": 60,
    "arduino_auto_reconnect": True,
    "analysis_output_dir": "analysis_results",
    "analysis_upload_enabled": True,
}
PIP_FALLBACK_PACKAGES = [
    "pyserial>=3.5",
    "Pillow>=9.0.0",
    "matplotlib>=3.5.0",
    "numpy>=1.21.0",
    "requests>=2.28.0",
    "opencv-python>=4.5.0",
    "urllib3>=1.26.0",
    "qrcode[pil]>=7.4.0",
]
APT_PACKAGES = [
    "git",
    "curl",
    "wget",
    "unzip",
    "python3",
    "python3-pip",
    "python3-venv",
    "python3-tk",
    "python3-opencv",
    "python3-numpy",
    "libatlas-base-dev",
    "libopenblas-dev",
    "libjpeg-dev",
    "zlib1g-dev",
    "v4l-utils",
    "avrdude",
    "build-essential",
    "ca-certificates",
]
BOARD_OPTIONS = {
    "Arduino Nano": "arduino:avr:nano",
    "Arduino Nano old bootloader": "arduino:avr:nano:cpu=atmega328old",
    "Arduino Uno": "arduino:avr:uno",
}
def installer_dir() -> Path:
    return Path(__file__).resolve().parent
def source_project_dir() -> Path:
    current = installer_dir()
    parent = current.parent
    if (parent / "main.py").exists() and (parent / "fitodomik_app").is_dir():
        return parent
    if (current / "main.py").exists() and (current / "fitodomik_app").is_dir():
        return current
    return parent
def is_windows() -> bool:
    return os.name == "nt"
def is_linux() -> bool:
    return sys.platform.startswith("linux")
def is_raspberry_pi() -> bool:
    model_path = Path("/proc/device-tree/model")
    if not model_path.exists():
        return False
    try:
        return "raspberry pi" in model_path.read_text(errors="ignore").lower()
    except Exception:
        return False
def is_arm_32bit() -> bool:
    machine = platform.machine().lower()
    return machine in {"armv6l", "armv7l"}
def is_raspberry_pi_32bit() -> bool:
    return is_linux() and is_raspberry_pi() and is_arm_32bit()
def platform_name() -> str:
    if is_windows():
        return "Windows"
    if is_raspberry_pi_32bit():
        return "Raspberry Pi OS 32-bit"
    if is_raspberry_pi():
        return "Raspberry Pi OS"
    if is_linux():
        return "Linux"
    return platform.platform()
def venv_python(install_dir: Path) -> Path:
    if is_windows():
        return install_dir / ".venv" / "Scripts" / "python.exe"
    return install_dir / ".venv" / "bin" / "python"
def run_command(
    command: list[str],
    cwd: Path | None = None,
    logger: Callable[[str], None] | None = None,
    timeout: int | None = None,
) -> tuple[bool, str]:
    command = [str(part) for part in command]
    text = " ".join(command)
    if logger:
        logger(f"$ {text}")
    try:
        process = subprocess.Popen(
            command,
            cwd=str(cwd) if cwd else None,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            stdin=None,
            universal_newlines=True,
            encoding="utf-8",
            errors="replace",
        )
        output_lines: list[str] = []
        assert process.stdout is not None
        started = time.time()
        for line in process.stdout:
            output_lines.append(line.rstrip())
            if logger:
                logger(line.rstrip())
            if timeout and time.time() - started > timeout:
                process.kill()
                return False, "\n".join(output_lines + ["timeout"])
        code = process.wait()
        return code == 0, "\n".join(output_lines)
    except FileNotFoundError:
        message = f"Команда не найдена: {command[0]}"
        if logger:
            logger(message)
        return False, message
    except Exception as exc:
        message = str(exc)
        if logger:
            logger(message)
        return False, message
def timestamp() -> str:
    return time.strftime("%Y%m%d_%H%M%S")
def backup_path(path: Path) -> Path | None:
    if not path.exists():
        return None
    backup = path.with_name(f"{path.name}.backup.{timestamp()}")
    if path.is_dir():
        shutil.copytree(path, backup)
    else:
        shutil.copy2(path, backup)
    return backup
def copy_project_to_install_dir(install_dir: Path, logger: Callable[[str], None]) -> None:
    source_dir = source_project_dir()
    install_dir = install_dir.expanduser().resolve()
    source_dir = source_dir.resolve()
    if install_dir == source_dir:
        logger(f"Проект уже находится в выбранной папке: {install_dir}")
        return
    install_dir.mkdir(parents=True, exist_ok=True)
    has_project = (install_dir / "main.py").exists() and (install_dir / "fitodomik_app").is_dir()
    non_log_entries = [p for p in install_dir.iterdir() if p.name != "install.log"]
    if non_log_entries and not has_project:
        backup = install_dir.with_name(f"{install_dir.name}.backup.{timestamp()}")
        shutil.move(str(install_dir), str(backup))
        logger(f"Существующая непроектная папка сохранена: {backup}")
        install_dir.mkdir(parents=True, exist_ok=True)
    elif has_project:
        logger("В папке уже есть проект. Пользовательские данные не перезаписываю.")
        return
    ignore = shutil.ignore_patterns(
        ".git",
        ".venv",
        "__pycache__",
        "*.pyc",
        "logs",
        "analysis_results",
    )
    shutil.copytree(source_dir, install_dir, dirs_exist_ok=True, ignore=ignore)
    logger(f"Проект скопирован: {source_dir} -> {install_dir}")
def create_venv(install_dir: Path, logger: Callable[[str], None]) -> bool:
    install_dir.mkdir(parents=True, exist_ok=True)
    args = [sys.executable, "-m", "venv", str(install_dir / ".venv")]
    if is_linux():
        args.append("--system-site-packages")
    ok, _ = run_command(args, cwd=install_dir, logger=logger)
    return ok
def write_filtered_requirements(install_dir: Path, logger: Callable[[str], None]) -> Path:
    source = install_dir / "requirements.txt"
    lines = source.read_text(encoding="utf-8").splitlines() if source.exists() else PIP_FALLBACK_PACKAGES
    filtered: list[str] = []
    for line in lines:
        normalized = line.strip().lower()
        if is_raspberry_pi_32bit() and normalized.startswith("opencv-python"):
            logger("Raspberry Pi OS 32-bit: opencv-python исключен из pip requirements.")
            continue
        if normalized.startswith("arduino-cli"):
            logger("arduino-cli исключен из pip requirements: это внешний CLI, он проверяется отдельно.")
            continue
        filtered.append(line)
    target = install_dir / ".fitodomik_requirements_install.txt"
    target.write_text("\n".join(filtered) + "\n", encoding="utf-8")
    return target
def install_python_dependencies(install_dir: Path, logger: Callable[[str], None]) -> bool:
    python = venv_python(install_dir)
    if not python.exists():
        logger("venv не найден, создаю.")
        if not create_venv(install_dir, logger):
            return False
    ok, _ = run_command([str(python), "-m", "pip", "install", "--upgrade", "pip", "setuptools", "wheel"], logger=logger)
    if not ok:
        return False
    requirements = write_filtered_requirements(install_dir, logger)
    ok, _ = run_command([str(python), "-m", "pip", "install", "-r", str(requirements)], logger=logger)
    return ok
def install_linux_system_dependencies(logger: Callable[[str], None]) -> bool:
    if not is_linux():
        logger("Системные apt-зависимости нужны только Linux/Raspberry.")
        return True
    if shutil.which("apt") is None:
        logger("apt не найден. Пропускаю системные зависимости.")
        return False
    ok_update, _ = run_command(["sudo", "apt", "update"], logger=logger)
    ok_install, _ = run_command(["sudo", "apt", "install", "-y", *APT_PACKAGES], logger=logger)
    ok_arduino, _ = run_command(["sudo", "apt", "install", "-y", "arduino-cli"], logger=logger)
    if not ok_arduino:
        logger("arduino-cli недоступен через apt. Это не блокирует установку desktop-приложения.")
    return ok_update and ok_install
def configure_serial_groups(logger: Callable[[str], None]) -> bool:
    if not is_linux():
        logger("Группы dialout/tty актуальны только для Linux/Raspberry.")
        return True
    user = os.environ.get("USER") or os.environ.get("LOGNAME") or ""
    if not user:
        logger("Не удалось определить пользователя для usermod.")
        return False
    ok1, _ = run_command(["sudo", "usermod", "-aG", "dialout", user], logger=logger)
    ok2, _ = run_command(["sudo", "usermod", "-aG", "tty", user], logger=logger)
    if ok1 and ok2:
        logger("Пользователь добавлен в dialout и tty. Нужен перелогин или перезагрузка.")
    return ok1 and ok2
def list_serial_ports() -> list[str]:
    try:
        from serial.tools import list_ports
        return sorted(port.device for port in list_ports.comports())
    except Exception:
        pass
    if is_windows():
        return [f"COM{i}" for i in range(1, 17)]
    ports: list[str] = []
    for pattern in ("ttyUSB*", "ttyACM*"):
        ports.extend(str(path) for path in Path("/dev").glob(pattern))
    return sorted(ports)
def test_serial_port(install_dir: Path, port: str, logger: Callable[[str], None]) -> bool:
    if not port:
        logger("Порт Arduino не выбран.")
        return False
    python = venv_python(install_dir)
    code = (
        "import sys, serial\n"
        "port=sys.argv[1]\n"
        "ser=serial.Serial(port, 9600, timeout=2)\n"
        "ser.close()\n"
        "print('Serial OK:', port)\n"
    )
    ok, _ = run_command([str(python), "-B", "-c", code, port], logger=logger)
    return ok
def prepare_arduino_cli(install_dir: Path, logger: Callable[[str], None]) -> bool:
    if shutil.which("arduino-cli") is None:
        logger("arduino-cli не найден. Прошивку можно выполнить вручную.")
        return False
    run_command(["arduino-cli", "config", "init"], logger=logger)
    run_command(["arduino-cli", "core", "update-index"], logger=logger)
    run_command(["arduino-cli", "core", "install", "arduino:avr"], logger=logger)
    local_libraries = install_dir / "libraries"
    if local_libraries.is_dir():
        target_root = Path.home() / "Arduino" / "libraries"
        target_root.mkdir(parents=True, exist_ok=True)
        for library in local_libraries.iterdir():
            target = target_root / library.name
            if target.exists():
                backup = backup_path(target)
                logger(f"Backup Arduino-библиотеки: {backup}")
            if library.is_dir():
                shutil.copytree(library, target, dirs_exist_ok=True)
            else:
                shutil.copy2(library, target)
        logger(f"Arduino-библиотеки подготовлены: {target_root}")
    return True
def flash_arduino(
    install_dir: Path,
    fqbn: str,
    port: str,
    logger: Callable[[str], None],
) -> bool:
    sketch = install_dir / "fitodomik.ino"
    if not sketch.exists():
        logger("fitodomik.ino не найден.")
        return False
    if not fqbn or not port:
        logger("Для прошивки нужны FQBN и порт.")
        return False
    if shutil.which("arduino-cli") is None:
        logger("arduino-cli не найден.")
        return False
    ok_compile, _ = run_command(["arduino-cli", "compile", "--fqbn", fqbn, str(sketch)], logger=logger)
    if not ok_compile:
        return False
    ok_upload, _ = run_command(["arduino-cli", "upload", "-p", port, "--fqbn", fqbn, str(sketch)], logger=logger)
    return ok_upload
def check_camera(install_dir: Path, logger: Callable[[str], None]) -> bool:
    python = venv_python(install_dir)
    if not python.exists():
        python = Path(sys.executable)
    code = (
        "import cv2\n"
        "cap=cv2.VideoCapture(0)\n"
        "ok, frame=cap.read()\n"
        "cap.release()\n"
        "print(bool(ok))\n"
        "raise SystemExit(0 if ok else 1)\n"
    )
    ok, _ = run_command([str(python), "-B", "-c", code], logger=logger)
    return ok
def check_imports(install_dir: Path, logger: Callable[[str], None]) -> dict[str, bool]:
    python = venv_python(install_dir)
    if not python.exists():
        python = Path(sys.executable)
    modules = ["tkinter", "serial", "PIL", "matplotlib", "requests", "cv2", "qrcode"]
    result: dict[str, bool] = {}
    for module in modules:
        ok, _ = run_command([str(python), "-B", "-c", f"import {module}; print('OK')"], logger=logger)
        result[module] = ok
    return result
def merge_settings(
    install_dir: Path,
    weather_city: str,
    fullscreen: bool,
    kiosk_mode: bool,
    port: str,
    logger: Callable[[str], None],
) -> None:
    path = install_dir / "settings.json"
    if path.exists():
        backup = backup_path(path)
        logger(f"Backup settings.json: {backup}")
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            data = {}
    else:
        data = {}
    for key, value in DEFAULT_SETTINGS.items():
        data.setdefault(key, value)
    data["weather_city"] = weather_city or data.get("weather_city") or "Moscow"
    data["fullscreen"] = bool(fullscreen)
    data["kiosk_mode"] = bool(kiosk_mode)
    if port:
        data["port"] = port
        data["auto_detect_port"] = False
    path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    logger(f"settings.json сохранен: {path}")
def configure_autostart(install_dir: Path, logger: Callable[[str], None]) -> bool:
    if is_windows():
        startup = Path(os.environ.get("APPDATA", Path.home())) / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"
        startup.mkdir(parents=True, exist_ok=True)
        script = startup / "FitoDomik.cmd"
        script.write_text(
            f'@echo off\ncd /d "{install_dir}"\n".venv\\Scripts\\python.exe" main.py\n',
            encoding="utf-8",
        )
        desktop = Path.home() / "Desktop"
        if desktop.exists():
            (desktop / "FitoDomik.cmd").write_text(script.read_text(encoding="utf-8"), encoding="utf-8")
        logger(f"Windows автозапуск настроен: {script}")
        return True
    if is_linux():
        autostart_dir = Path.home() / ".config" / "autostart"
        autostart_dir.mkdir(parents=True, exist_ok=True)
        desktop_text = f
        autostart_file = autostart_dir / "fitodomik.desktop"
        autostart_file.write_text(desktop_text, encoding="utf-8")
        autostart_file.chmod(0o755)
        desktop = Path.home() / "Desktop"
        if desktop.exists():
            desktop_file = desktop / "fitodomik.desktop"
            desktop_file.write_text(desktop_text, encoding="utf-8")
            desktop_file.chmod(0o755)
        logger(f"Linux автозапуск настроен: {autostart_file}")
        return True
    logger("Автозапуск для этой ОС не реализован.")
    return False
def compile_project(install_dir: Path, logger: Callable[[str], None]) -> bool:
    python = venv_python(install_dir)
    if not python.exists():
        python = Path(sys.executable)
    ok_compile, _ = run_command([str(python), "-m", "compileall", "-q", "main.py", "fitodomik_app"], cwd=install_dir, logger=logger)
    ok_import, _ = run_command([str(python), "-B", "-c", "import main; import fitodomik_app.ui.app"], cwd=install_dir, logger=logger)
    return ok_compile and ok_import
def run_app(install_dir: Path, logger: Callable[[str], None]) -> bool:
    python = venv_python(install_dir)
    if not python.exists():
        logger("venv python не найден.")
        return False
    kwargs: dict[str, object] = {
        "cwd": str(install_dir),
        "stdin": subprocess.DEVNULL,
        "stdout": subprocess.DEVNULL,
        "stderr": subprocess.DEVNULL,
    }
    if is_windows():
        kwargs["creationflags"] = getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
    subprocess.Popen([str(python), str(install_dir / "main.py")], **kwargs)
    logger("ФитоДомик запущен.")
    return True
class ConsoleWizard:
    def __init__(self) -> None:
        self.install_dir = DEFAULT_INSTALL_DIR
    def log(self, message: str) -> None:
        print(message)
    def ask(self, prompt: str, default: str = "") -> str:
        value = input(f"{prompt} [{default}]: ").strip()
        return value or default
    def yes(self, prompt: str, default: bool = False) -> bool:
        suffix = "Y/n" if default else "y/N"
        answer = input(f"{prompt} [{suffix}] ").strip().lower()
        if not answer:
            return default
        return answer in {"y", "yes", "д", "да"}
    def run(self) -> None:
        self.log(f"{APP_NAME}: мастер установки ({platform_name()})")
        self.install_dir = Path(self.ask("Папка установки", str(DEFAULT_INSTALL_DIR))).expanduser()
        if is_linux() and self.yes("Установить системные apt-зависимости", is_raspberry_pi()):
            install_linux_system_dependencies(self.log)
            configure_serial_groups(self.log)
        copy_project_to_install_dir(self.install_dir, self.log)
        create_venv(self.install_dir, self.log)
        install_python_dependencies(self.install_dir, self.log)
        imports = check_imports(self.install_dir, self.log)
        self.log("Импорты: " + ", ".join(f"{k}={'OK' if v else 'FAIL'}" for k, v in imports.items()))
        ports = list_serial_ports()
        self.log("Порты Arduino: " + (", ".join(ports) if ports else "не найдены"))
        port = self.ask("Порт Arduino", ports[0] if ports else "")
        if port:
            test_serial_port(self.install_dir, port, self.log)
        if self.yes("Подготовить Arduino CLI и библиотеки", False):
            prepare_arduino_cli(self.install_dir, self.log)
        camera_ok = check_camera(self.install_dir, self.log)
        self.log(f"Камера: {'OK' if camera_ok else 'не найдена'}")
        city = self.ask("Город погоды", "Moscow")
        fullscreen = self.yes("Fullscreen", False)
        kiosk = self.yes("Kiosk mode", False)
        merge_settings(self.install_dir, city, fullscreen, kiosk, port, self.log)
        if self.yes("Настроить автозапуск", True):
            configure_autostart(self.install_dir, self.log)
        compile_project(self.install_dir, self.log)
        run_command_text = "installer\\run.bat" if is_windows() else "./installer/run.sh"
        self.log(f"Готово. Запуск: cd {self.install_dir} && {run_command_text}")
        run_app(self.install_dir, self.log)
        self.log("Установщик завершает работу.")
class TkWizard:
    def __init__(self) -> None:
        import tkinter as tk
        from tkinter import ttk
        self.tk = tk
        self.ttk = ttk
        self.root = tk.Tk()
        self.root.title("Установка ФитоДомик")
        self.root.geometry("980x680")
        self.root.minsize(900, 620)
        self.root.configure(bg="#eef2ec")
        self.install_dir_var = tk.StringVar(value=str(DEFAULT_INSTALL_DIR))
        self.weather_var = tk.StringVar(value="Moscow")
        self.port_var = tk.StringVar(value="")
        self.board_var = tk.StringVar(value="Arduino Nano old bootloader")
        self.fqbn_var = tk.StringVar(value=BOARD_OPTIONS["Arduino Nano old bootloader"])
        self.fullscreen_var = tk.BooleanVar(value=False)
        self.kiosk_var = tk.BooleanVar(value=False)
        self.autostart_var = tk.BooleanVar(value=True)
        self.status_var = tk.StringVar(value=f"Платформа: {platform_name()}")
        self.busy = False
        self.main_thread_id = threading.get_ident()
        self.log_queue: queue.Queue[str] = queue.Queue()
        self.ui_queue: queue.Queue[Callable[[], None]] = queue.Queue()
        self.advanced_visible = False
        self.advanced_frame = None
        self.closing = False
        self._build_style()
        self._build_layout()
        try:
            self.root.attributes("-fullscreen", True)
        except Exception:
            self.root.state("zoomed")
        self.root.bind("<Escape>", lambda _event: self.root.attributes("-fullscreen", False))
        self.root.bind("<F11>", lambda _event: self.root.attributes("-fullscreen", not bool(self.root.attributes("-fullscreen"))))
        self.root.protocol("WM_DELETE_WINDOW", self.close)
        self.root.after(80, self._flush_log_queue)
    def _build_style(self) -> None:
        style = self.ttk.Style()
        try:
            style.theme_use("clam")
        except Exception:
            pass
        style.configure("Root.TFrame", background="#eef2ec")
        style.configure("Card.TFrame", background="#ffffff", relief="flat")
        style.configure("Title.TLabel", background="#ffffff", foreground="#18352b", font=("Segoe UI", 18, "bold"))
        style.configure("Lead.TLabel", background="#ffffff", foreground="#385247", font=("Segoe UI", 11))
        style.configure("Body.TLabel", background="#ffffff", foreground="#385247", font=("Segoe UI", 10))
        style.configure("Status.TLabel", background="#eef2ec", foreground="#365246", font=("Segoe UI", 9))
        style.configure("Accent.TButton", font=("Segoe UI", 10, "bold"), padding=(14, 8))
        style.configure("Primary.TButton", font=("Segoe UI", 18, "bold"), padding=(26, 22))
        style.configure("Toggle.TButton", font=("Segoe UI", 11, "bold"), padding=(16, 10))
        style.configure("Soft.TButton", font=("Segoe UI", 10), padding=(12, 7))
    def _build_layout(self) -> None:
        tk = self.tk
        ttk = self.ttk
        root_frame = ttk.Frame(self.root, style="Root.TFrame", padding=22)
        root_frame.pack(fill="both", expand=True)
        main = ttk.Frame(root_frame, style="Card.TFrame", padding=24)
        main.pack(fill="both", expand=True)
        header = ttk.Frame(main, style="Card.TFrame")
        header.grid(row=0, column=0, sticky="ew")
        ttk.Label(header, text="Установка ФитоДомик", style="Title.TLabel").pack(anchor="w")
        ttk.Label(
            header,
            text=f"Платформа: {platform_name()}. Нажмите полную установку, чтобы автоматически проверить систему и подготовить приложение.",
            style="Lead.TLabel",
            wraplength=980,
        ).pack(anchor="w", pady=(6, 0))
        primary = ttk.Frame(main, style="Card.TFrame")
        primary.grid(row=1, column=0, sticky="ew", pady=(24, 12))
        ttk.Button(
            primary,
            text="Полная установка",
            style="Primary.TButton",
            command=self.full_install,
        ).pack(fill="x")
        primary_actions = ttk.Frame(primary, style="Card.TFrame")
        primary_actions.pack(fill="x", pady=(12, 0))
        ttk.Button(
            primary_actions,
            text="Выйти",
            style="Soft.TButton",
            command=self.exit_installer,
        ).pack(side="right")
        ttk.Button(
            primary_actions,
            text="Расширенные настройки",
            style="Toggle.TButton",
            command=self.toggle_advanced,
        ).pack(side="right", padx=(0, 10))
        self.advanced_frame = ttk.Frame(main, style="Card.TFrame")
        advanced = self.advanced_frame
        ttk.Label(advanced, text="Папка установки", style="Body.TLabel").grid(row=0, column=0, sticky="w")
        ttk.Entry(advanced, textvariable=self.install_dir_var).grid(row=1, column=0, columnspan=2, sticky="ew", pady=(4, 8))
        ttk.Button(advanced, text="Подготовить папку", style="Soft.TButton", command=self.prepare_project).grid(row=1, column=2, padx=(8, 0), sticky="ew")
        ttk.Label(advanced, text="Город погоды", style="Body.TLabel").grid(row=2, column=0, sticky="w")
        ttk.Entry(advanced, textvariable=self.weather_var).grid(row=3, column=0, sticky="ew", pady=(4, 8))
        ttk.Label(advanced, text="Порт Arduino", style="Body.TLabel").grid(row=2, column=1, sticky="w", padx=(12, 0))
        ttk.Entry(advanced, textvariable=self.port_var).grid(row=3, column=1, sticky="ew", padx=(12, 0), pady=(4, 8))
        ttk.Button(advanced, text="Найти порты", style="Soft.TButton", command=self.detect_ports).grid(row=3, column=2, padx=(8, 0), sticky="ew")
        ttk.Label(advanced, text="Плата Arduino", style="Body.TLabel").grid(row=4, column=0, sticky="w")
        board = ttk.Combobox(advanced, textvariable=self.board_var, values=list(BOARD_OPTIONS), state="readonly")
        board.grid(row=5, column=0, sticky="ew", pady=(4, 8))
        board.bind("<<ComboboxSelected>>", self._sync_fqbn)
        ttk.Label(advanced, text="FQBN", style="Body.TLabel").grid(row=4, column=1, sticky="w", padx=(12, 0))
        ttk.Entry(advanced, textvariable=self.fqbn_var).grid(row=5, column=1, columnspan=2, sticky="ew", padx=(12, 0), pady=(4, 8))
        flags = ttk.Frame(advanced, style="Card.TFrame")
        flags.grid(row=6, column=0, columnspan=3, sticky="ew", pady=(2, 12))
        ttk.Checkbutton(flags, text="Fullscreen", variable=self.fullscreen_var).pack(side="left")
        ttk.Checkbutton(flags, text="Kiosk mode", variable=self.kiosk_var).pack(side="left", padx=(18, 0))
        ttk.Checkbutton(flags, text="Автозапуск", variable=self.autostart_var).pack(side="left", padx=(18, 0))
        actions = ttk.Frame(advanced, style="Card.TFrame")
        actions.grid(row=7, column=0, columnspan=3, sticky="ew")
        button_specs = [
            ("Проверить систему", self.check_system),
            ("Системные зависимости", self.install_system_deps),
            ("Python-зависимости", self.install_python_deps),
            ("Arduino CLI", self.prepare_arduino),
            ("Проверить камеру", self.test_camera),
            ("Сохранить настройки", self.save_settings),
            ("Автозапуск", self.setup_autostart),
            ("Финальная проверка", self.final_check),
            ("Прошить Arduino", self.flash_arduino),
            ("Запустить", self.launch_app),
        ]
        for index, (text, command) in enumerate(button_specs):
            ttk.Button(actions, text=text, style="Soft.TButton", command=command).grid(
                row=index // 3,
                column=index % 3,
                sticky="ew",
                padx=4,
                pady=4,
            )
        for column in range(3):
            actions.columnconfigure(column, weight=1)
            advanced.columnconfigure(column, weight=1)
        ttk.Label(main, textvariable=self.status_var, style="Status.TLabel").grid(row=3, column=0, sticky="ew", pady=(12, 4))
        self.log_box = tk.Text(main, height=18, wrap="word", bg="#0f1f1a", fg="#d9f6e2", insertbackground="#d9f6e2", relief="flat", padx=12, pady=10)
        self.log_box.grid(row=4, column=0, sticky="nsew")
        main.columnconfigure(0, weight=1)
        main.rowconfigure(4, weight=1)
        self.log(f"Готов к установке. Платформа: {platform_name()}")
    def toggle_advanced(self) -> None:
        if self.advanced_frame is None:
            return
        if self.advanced_visible:
            self.advanced_frame.grid_remove()
            self.advanced_visible = False
            self.log("Расширенные настройки скрыты.")
        else:
            self.advanced_frame.grid(row=2, column=0, sticky="ew", pady=(8, 6))
            self.advanced_visible = True
            self.log("Расширенные настройки открыты.")
    def _sync_fqbn(self, _event: object | None = None) -> None:
        self.fqbn_var.set(BOARD_OPTIONS.get(self.board_var.get(), self.fqbn_var.get()))
    def install_dir(self) -> Path:
        return Path(self.install_dir_var.get()).expanduser()
    def _append_log(self, message: str) -> None:
        self.log_box.insert("end", message.rstrip() + "\n")
        self.log_box.see("end")
        self.status_var.set(message[:160])
        self.root.update_idletasks()
    def _flush_log_queue(self) -> None:
        if self.closing:
            return
        while True:
            try:
                message = self.log_queue.get_nowait()
            except queue.Empty:
                break
            self._append_log(message)
        while True:
            try:
                callback = self.ui_queue.get_nowait()
            except queue.Empty:
                break
            try:
                callback()
            except Exception as exc:
                self._append_log(f"Ошибка UI: {exc}")
        self.root.after(80, self._flush_log_queue)
    def log(self, message: str) -> None:
        if threading.get_ident() != self.main_thread_id:
            self.log_queue.put(message)
            return
        self._append_log(message)
    def run_on_ui(self, callback: Callable[[], None]) -> None:
        if threading.get_ident() == self.main_thread_id:
            callback()
            return
        self.ui_queue.put(callback)
    def close(self) -> None:
        self.closing = True
        try:
            self.root.quit()
        except Exception:
            pass
        try:
            self.root.destroy()
        except Exception:
            pass
    def exit_installer(self) -> None:
        if self.busy:
            import tkinter.messagebox as messagebox
            if not messagebox.askyesno("Выход", "Установка ещё выполняется. Закрыть установщик?"):
                return
        self.close()
    def close_after_launch(self) -> None:
        self.run_on_ui(lambda: self.root.after(900, self.close))
    def run_threaded(self, title: str, func: Callable[[], None]) -> None:
        if self.busy:
            self.log("Дождитесь завершения текущего шага.")
            return
        self.busy = True
        self.log(f"== {title} ==")
        def worker() -> None:
            try:
                func()
            except Exception as exc:
                self.log(f"Ошибка: {exc}")
            finally:
                self.busy = False
                self.log(f"== {title}: завершено ==")
        threading.Thread(target=worker, daemon=True).start()
    def check_system(self) -> None:
        def action() -> None:
            self.log(f"ОС: {platform_name()}")
            self.log(f"Python: {sys.version.split()[0]}")
            self.log(f"Архитектура: {platform.machine()}")
            if is_raspberry_pi():
                model = Path("/proc/device-tree/model").read_text(errors="ignore").replace("\x00", "")
                self.log(f"Raspberry Pi: {model}")
            ports = list_serial_ports()
            self.log("Serial-порты: " + (", ".join(ports) if ports else "не найдены"))
        self.run_threaded("Проверка системы", action)
    def prepare_project(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Подготовка проекта", lambda: copy_project_to_install_dir(install_dir, self.log))
    def install_system_deps(self) -> None:
        self.run_threaded(
            "Системные зависимости",
            lambda: (install_linux_system_dependencies(self.log), configure_serial_groups(self.log)),
        )
    def install_python_deps(self) -> None:
        install_dir = self.install_dir()
        def action() -> None:
            copy_project_to_install_dir(install_dir, self.log)
            create_venv(install_dir, self.log)
            install_python_dependencies(install_dir, self.log)
            imports = check_imports(install_dir, self.log)
            self.log("Импорты: " + ", ".join(f"{k}={'OK' if v else 'FAIL'}" for k, v in imports.items()))
        self.run_threaded("Python-зависимости", action)
    def detect_ports(self) -> None:
        ports = list_serial_ports()
        if ports:
            self.port_var.set(ports[0])
            self.log("Serial-порты: " + ", ".join(ports))
        else:
            self.log("Serial-порты не найдены.")
    def prepare_arduino(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Arduino CLI", lambda: prepare_arduino_cli(install_dir, self.log))
    def flash_arduino(self) -> None:
        import tkinter.messagebox as messagebox
        if not messagebox.askyesno("Прошивка Arduino", "Прошить Arduino сейчас? Это действие будет выполнено только после вашего подтверждения."):
            self.log("Прошивка отменена пользователем.")
            return
        install_dir = self.install_dir()
        fqbn = self.fqbn_var.get().strip()
        port = self.port_var.get().strip()
        self.run_threaded(
            "Прошивка Arduino",
            lambda: flash_arduino(install_dir, fqbn, port, self.log),
        )
    def test_camera(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Проверка камеры", lambda: self.log("Камера: " + ("OK" if check_camera(install_dir, self.log) else "не найдена")))
    def save_settings(self) -> None:
        install_dir = self.install_dir()
        weather_city = self.weather_var.get().strip() or "Moscow"
        fullscreen = self.fullscreen_var.get()
        kiosk_mode = self.kiosk_var.get()
        port = self.port_var.get().strip()
        self.run_threaded(
            "Сохранение settings.json",
            lambda: merge_settings(
                install_dir,
                weather_city,
                fullscreen,
                kiosk_mode,
                port,
                self.log,
            ),
        )
    def setup_autostart(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Автозапуск", lambda: configure_autostart(install_dir, self.log))
    def final_check(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Финальная проверка", lambda: compile_project(install_dir, self.log))
    def full_install(self) -> None:
        install_dir = self.install_dir()
        weather_city = self.weather_var.get().strip() or "Moscow"
        fullscreen = self.fullscreen_var.get()
        kiosk_mode = self.kiosk_var.get()
        selected_port = self.port_var.get().strip()
        autostart = self.autostart_var.get()
        def action() -> None:
            if is_linux():
                install_linux_system_dependencies(self.log)
                configure_serial_groups(self.log)
            copy_project_to_install_dir(install_dir, self.log)
            create_venv(install_dir, self.log)
            install_python_dependencies(install_dir, self.log)
            ports = list_serial_ports()
            self.log("Serial-порты: " + (", ".join(ports) if ports else "не найдены"))
            port = selected_port or (ports[0] if ports else "")
            if port:
                test_serial_port(install_dir, port, self.log)
            prepare_arduino_cli(install_dir, self.log)
            camera_ok = check_camera(install_dir, self.log)
            self.log("Камера: " + ("OK" if camera_ok else "не найдена"))
            merge_settings(
                install_dir,
                weather_city,
                fullscreen,
                kiosk_mode,
                port,
                self.log,
            )
            if autostart:
                configure_autostart(install_dir, self.log)
            if not compile_project(install_dir, self.log):
                self.log("Финальная проверка не пройдена. Приложение не запускаю.")
                return
            run_command_text = "installer\\run.bat" if is_windows() else "./installer/run.sh"
            self.log(f"Запуск: cd {install_dir} && {run_command_text}")
            if is_linux():
                self.log("Если добавлялись группы dialout/tty, перезагрузите Raspberry Pi или перелогиньтесь.")
            if run_app(install_dir, self.log):
                self.log("Установщик закроется после запуска приложения.")
                self.close_after_launch()
        self.run_threaded("Полная установка", action)
    def launch_app(self) -> None:
        install_dir = self.install_dir()
        self.run_threaded("Запуск ФитоДомик", lambda: run_app(install_dir, self.log))
    def run(self) -> None:
        self.root.mainloop()
def main() -> None:
    if "--console" in sys.argv:
        ConsoleWizard().run()
        return
    try:
        TkWizard().run()
    except Exception as exc:
        print(f"Tkinter недоступен или GUI не запустился: {exc}")
        ConsoleWizard().run()
if __name__ == "__main__":
    main()