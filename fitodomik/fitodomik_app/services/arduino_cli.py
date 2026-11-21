from __future__ import annotations
import json
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path
SUPPORTED_FQBNS = {
    "arduino:avr:nano",
    "arduino:avr:nano:cpu=atmega328old",
    "arduino:avr:uno",
    "arduino:avr:mega",
}
SUPPORTED_SENSOR_LIBRARIES = (
    "RTClib",
    "DHT sensor library",
    "OneWire",
    "DallasTemperature",
    "BH1750",
    "Adafruit BMP280 Library",
    "Adafruit BME280 Library",
    "Adafruit AHTX0",
    "Adafruit Unified Sensor",
    "Adafruit BusIO",
)
def _to_text(value: str | bytes | None) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return str(value)
@dataclass(slots=True)
class ArduinoCliResult:
    ok: bool
    message: str
    stdout: str = ""
    stderr: str = ""
    command: list[str] | None = None
class ArduinoCliService:
    def __init__(self, executable: str = "arduino-cli") -> None:
        self.executable = executable
    def available(self) -> bool:
        return shutil.which(self.executable) is not None
    def version(self) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        return self._run(["version"], 20)
    def core_list(self) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        return self._run(["core", "list"], 30)
    def board_list(self) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        return self._run(["board", "list"], 30)
    def lib_list(self) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        return self._run(["lib", "list"], 30)
    def check_required_commands(self) -> list[ArduinoCliResult]:
        if not self.available():
            return [self._missing()]
        return [self.version(), self.core_list(), self.board_list(), self.lib_list()]
    def supported_fqbns(self) -> list[str]:
        return sorted(SUPPORTED_FQBNS)
    def validate_fqbn(self, fqbn: str, allow_manual: bool = True) -> ArduinoCliResult:
        if not fqbn:
            return ArduinoCliResult(False, "FQBN платы не задан")
        if fqbn in SUPPORTED_FQBNS:
            return ArduinoCliResult(True, f"FQBN поддерживается: {fqbn}")
        if allow_manual:
            return ArduinoCliResult(True, f"Используется ручной FQBN: {fqbn}")
        return ArduinoCliResult(False, "FQBN не входит в поддержанный список: " + ", ".join(self.supported_fqbns()))
    def compile(
        self,
        sketch_dir: Path,
        fqbn: str,
        timeout_seconds: int = 120,
        library_paths: list[Path] | None = None,
        library_names: list[str] | None = None,
    ) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        fqbn_check = self.validate_fqbn(fqbn)
        if not fqbn_check.ok:
            return fqbn_check
        core = self._ensure_core_for_fqbn(fqbn)
        if not core.ok:
            return core
        library_result = self.ensure_libraries(library_names or [])
        args = ["compile", "--fqbn", fqbn]
        for path in library_paths or []:
            if path.exists():
                args.extend(["--libraries", str(path)])
        args.append(str(sketch_dir))
        result = self._run(args, timeout_seconds)
        if library_result.stdout:
            result.stdout = (library_result.stdout + "\n" + result.stdout).strip()
        if not library_result.ok and not result.ok:
            result.message = "Arduino CLI: не все библиотеки установлены, сборка завершилась с ошибкой"
        return result
    def upload(self, sketch_dir: Path, fqbn: str, port: str, timeout_seconds: int = 180) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        fqbn_check = self.validate_fqbn(fqbn)
        if not fqbn_check.ok:
            return fqbn_check
        if not port:
            return ArduinoCliResult(False, "Serial-порт Arduino не выбран")
        return self._run(["upload", "-p", port, "--fqbn", fqbn, str(sketch_dir)], timeout_seconds)
    def ensure_supported_libraries(self) -> ArduinoCliResult:
        return self.ensure_libraries(list(SUPPORTED_SENSOR_LIBRARIES))
    def ensure_libraries(self, library_names: list[str]) -> ArduinoCliResult:
        if not self.available():
            return self._missing()
        messages: list[str] = []
        failed: list[str] = []
        listed = self._run(["lib", "list", "--format", "json"], 30)
        listed_text = listed.stdout if listed.ok else ""
        seen: set[str] = set()
        for raw_name in library_names:
            name = str(raw_name or "").strip()
            if not name or name in seen:
                continue
            seen.add(name)
            if self._library_list_contains(listed_text, name):
                messages.append(f"Библиотека уже установлена: {name}")
                continue
            result = self._run(["lib", "install", name], 240)
            if result.ok:
                messages.append(f"Библиотека установлена: {name}")
            else:
                detail = result.stderr or result.stdout or "нет вывода"
                messages.append(f"Библиотека не установлена ({name}): {detail[-400:]}")
                failed.append(name)
        return ArduinoCliResult(
            not failed,
            "Библиотеки Arduino проверены" if not failed else "Не все библиотеки Arduino установлены",
            "\n".join(messages),
        )
    def _run(self, args: list[str], timeout_seconds: int) -> ArduinoCliResult:
        command = [self.executable, *args]
        try:
            completed = subprocess.run(
                command,
                capture_output=True,
                text=True,
                timeout=timeout_seconds,
                check=False,
            )
        except subprocess.TimeoutExpired as exc:
            return ArduinoCliResult(False, "Arduino CLI не успел завершиться", _to_text(exc.stdout), _to_text(exc.stderr), command)
        except Exception as exc:
            return ArduinoCliResult(False, f"Ошибка запуска Arduino CLI: {exc}", command=command)
        output = _to_text(completed.stdout).strip()
        error = _to_text(completed.stderr).strip()
        ok = completed.returncode == 0
        message = "Arduino CLI выполнен успешно" if ok else "Arduino CLI завершился с ошибкой"
        return ArduinoCliResult(ok, message, output, error, command)
    def _ensure_core_for_fqbn(self, fqbn: str) -> ArduinoCliResult:
        core = self._core_from_fqbn(fqbn)
        if not core:
            return ArduinoCliResult(True, "Core платы не определён")
        listed = self._run(["core", "list", "--format", "json"], 30)
        if listed.ok and self._core_list_contains(listed.stdout, core):
            return ArduinoCliResult(True, f"Core установлен: {core}")
        update = self._run(["core", "update-index"], 180)
        if not update.ok:
            detail = update.stderr or update.stdout
            return ArduinoCliResult(
                False,
                "Не удалось обновить индекс Arduino core. Выполните: arduino-cli core update-index",
                update.stdout,
                detail,
                update.command,
            )
        install = self._run(["core", "install", core], 240)
        if install.ok:
            return ArduinoCliResult(True, f"Core установлен: {core}", install.stdout, install.stderr, install.command)
        detail = install.stderr or install.stdout
        return ArduinoCliResult(
            False,
            f"Core {core} не установлен и auto install не прошёл. Выполните: arduino-cli core install {core}",
            install.stdout,
            detail,
            install.command,
        )
    def _core_from_fqbn(self, fqbn: str) -> str:
        parts = fqbn.split(":")
        if len(parts) >= 2 and parts[0] and parts[1]:
            return f"{parts[0]}:{parts[1]}"
        return ""
    def _core_list_contains(self, stdout: str, core: str) -> bool:
        if not stdout:
            return False
        try:
            data = json.loads(stdout)
        except json.JSONDecodeError:
            return core in stdout
        if isinstance(data, list):
            return any(str(item.get("id") or item.get("ID") or "") == core for item in data if isinstance(item, dict))
        if isinstance(data, dict):
            platforms = data.get("platforms") or data.get("installed_platforms") or data.get("items") or []
            if isinstance(platforms, list):
                return any(str(item.get("id") or item.get("ID") or "") == core for item in platforms if isinstance(item, dict))
        return core in stdout
    def _library_list_contains(self, stdout: str, library_name: str) -> bool:
        if not stdout:
            return False
        try:
            data = json.loads(stdout)
        except json.JSONDecodeError:
            return library_name.lower() in stdout.lower()
        if isinstance(data, list):
            libraries = data
        elif isinstance(data, dict):
            libraries = data.get("libraries", [])
        else:
            libraries = []
        if isinstance(libraries, list):
            for item in libraries:
                if not isinstance(item, dict):
                    continue
                installed_name = str(item.get("name") or item.get("library") or "").strip().lower()
                if installed_name == library_name.lower():
                    return True
        return library_name.lower() in stdout.lower()
    def install_instructions(self) -> str:
        return (
            "Arduino CLI не найден. Установите Arduino CLI и добавьте arduino-cli.exe в PATH. "
            "Windows: winget install ArduinoSA.CLI или скачайте архив с официального сайта Arduino CLI, "
            "распакуйте arduino-cli.exe и перезапустите ФитоДомик. После установки проверьте: arduino-cli version."
        )
    def _missing(self) -> ArduinoCliResult:
        return ArduinoCliResult(
            False,
            self.install_instructions(),
        )
