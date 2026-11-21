from __future__ import annotations
import logging
import ipaddress
import platform
import re
import shutil
import socket
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from typing import Callable, cast
WIFI_TIMEOUT_SECONDS = 5.0
INTERNET_TIMEOUT_SECONDS = 4.0
PROJECT_URLS = ("https://fitodomik.ru", "https://fitodomik.ru/farm.php")
PROJECT_HOST = "fitodomik.ru"
PROJECT_PATHS = {"", "/", "/farm.php"}
WIFI_UNSUPPORTED_MESSAGE = "Настройка Wi-Fi доступна только на Raspberry Pi/Linux"
NMCLI_PASSWORD_OPTION = "password"  
NMCLI_SECRET_SPACE_PATTERN = re.compile(
    rf"{re.escape(NMCLI_PASSWORD_OPTION)}\s+\S+",
    flags=re.IGNORECASE,
)
NMCLI_SECRET_EQUALS_PATTERN = re.compile(
    rf"{re.escape(NMCLI_PASSWORD_OPTION)}=\S+",
    flags=re.IGNORECASE,
)
NMCLI_SECRET_SPACE_REDACTION = f"{NMCLI_PASSWORD_OPTION} ***"
NMCLI_SECRET_EQUALS_REDACTION = f"{NMCLI_PASSWORD_OPTION}=***"
CommandRunner = Callable[[list[str], float], subprocess.CompletedProcess[str]]
@dataclass(slots=True)
class WifiNetwork:
    ssid: str
    signal: int | None = None
    security: str = ""
    connected: bool = False
    @property
    def is_secured(self) -> bool:
        return bool(self.security and self.security.strip() not in {"--", ""})
@dataclass(slots=True)
class WifiStatus:
    available: bool
    manager: str = "none"
    enabled: bool = False
    connected: bool = False
    ssid: str = ""
    ip_address: str = ""
    signal: int | None = None
    message: str = ""
@dataclass(slots=True)
class WifiScanResult:
    status: WifiStatus
    networks: list[WifiNetwork] = field(default_factory=list)
@dataclass(slots=True)
class WifiOperationResult:
    ok: bool
    status: WifiStatus
    message: str
    networks: list[WifiNetwork] = field(default_factory=list)
class _ProjectRedirectHandler(urllib.request.HTTPRedirectHandler):
    max_redirections = 3
    def redirect_request(self, req, fp, code, msg, headers, newurl):  
        if not _is_trusted_project_url(newurl, resolve_host=True):
            raise urllib.error.HTTPError(newurl, 403, "Unsafe redirect", headers, fp)
        return super().redirect_request(req, fp, code, msg, headers, newurl)
def _host_has_only_public_addresses(hostname: str) -> bool:
    try:
        addresses = {
            item[4][0]
            for item in socket.getaddrinfo(hostname, 443, type=socket.SOCK_STREAM)
        }
    except OSError:
        return False
    try:
        return bool(addresses) and all(ipaddress.ip_address(address).is_global for address in addresses)
    except ValueError:
        return False
def _is_trusted_project_url(url: str, *, resolve_host: bool) -> bool:
    try:
        parsed = urllib.parse.urlsplit(url)
        port = parsed.port
    except ValueError:
        return False
    structurally_safe = (
        parsed.scheme == "https"
        and parsed.hostname == PROJECT_HOST
        and port in {None, 443}
        and parsed.username is None
        and parsed.password is None
        and parsed.path in PROJECT_PATHS
        and not parsed.query
        and not parsed.fragment
    )
    return structurally_safe and (not resolve_host or _host_has_only_public_addresses(PROJECT_HOST))
class WifiManager:
    def __init__(self, logger: logging.Logger | None = None, runner: CommandRunner | None = None) -> None:
        self.logger = (logger or logging.getLogger("fitodomik")).getChild("wifi")
        self._runner = runner or self._run_command
    def scan(self) -> WifiScanResult:
        if not self._is_linux():
            status = self._unsupported_status()
            return WifiScanResult(status=status, networks=[])
        if self._has_nmcli():
            return self._nmcli_scan()
        status = self._fallback_status()
        return WifiScanResult(status=status, networks=[])
    def connect(self, ssid: str, password: str = "") -> WifiOperationResult:
        clean_ssid = ssid.strip()
        if not clean_ssid:
            return WifiOperationResult(False, self.status(), "Выберите Wi-Fi сеть")
        if not self._is_linux():
            return WifiOperationResult(False, self._unsupported_status(), WIFI_UNSUPPORTED_MESSAGE)
        if not self._has_nmcli():
            return WifiOperationResult(False, self._fallback_status(), "nmcli недоступен. Подключение из приложения невозможно")
        args = ["nmcli", "dev", "wifi", "connect", clean_ssid]
        if password:
            args.extend([NMCLI_PASSWORD_OPTION, password])
        result = self._run_masked(args, timeout=20.0)
        scan = self.scan()
        if result.returncode == 0:
            return WifiOperationResult(True, scan.status, f"Подключено к сети {clean_ssid}", scan.networks)
        message = self._clean_error(
            result.stderr or result.stdout or "Не удалось подключиться",
            secrets=(password,),
        )
        return WifiOperationResult(False, scan.status, message, scan.networks)
    def disconnect(self) -> WifiOperationResult:
        if not self._is_linux():
            return WifiOperationResult(False, self._unsupported_status(), WIFI_UNSUPPORTED_MESSAGE)
        if not self._has_nmcli():
            return WifiOperationResult(False, self._fallback_status(), "nmcli недоступен. Отключение из приложения невозможно")
        device = self._wifi_device()
        if not device:
            return WifiOperationResult(False, self.status(), "Wi-Fi устройство не найдено")
        result = self._runner(["nmcli", "device", "disconnect", device], WIFI_TIMEOUT_SECONDS)
        status = self.status()
        if result.returncode == 0:
            return WifiOperationResult(True, status, "Wi-Fi отключён от сети")
        return WifiOperationResult(False, status, self._clean_error(result.stderr or result.stdout or "Не удалось отключить Wi-Fi"))
    def forget(self, ssid: str) -> WifiOperationResult:
        clean_ssid = ssid.strip()
        if not clean_ssid:
            return WifiOperationResult(False, self.status(), "Выберите Wi-Fi сеть")
        if not self._is_linux():
            return WifiOperationResult(False, self._unsupported_status(), WIFI_UNSUPPORTED_MESSAGE)
        if not self._has_nmcli():
            return WifiOperationResult(False, self._fallback_status(), "nmcli недоступен. Удаление сети невозможно")
        result = self._runner(["nmcli", "connection", "delete", "id", clean_ssid], WIFI_TIMEOUT_SECONDS)
        scan = self.scan()
        if result.returncode == 0:
            return WifiOperationResult(True, scan.status, f"Сеть {clean_ssid} забыта", scan.networks)
        return WifiOperationResult(False, scan.status, self._clean_error(result.stderr or result.stdout or "Не удалось забыть сеть"), scan.networks)
    def check_internet(self) -> WifiOperationResult:
        status = self.status()
        if not status.connected and self._is_linux():
            return WifiOperationResult(False, status, "Wi-Fi не подключен")
        dns_ok, dns_message = self._check_dns()
        if not dns_ok:
            return WifiOperationResult(False, status, dns_message)
        last_message = "нет ответа"
        for url in PROJECT_URLS:
            ok, message = self._check_url(url)
            if ok:
                return WifiOperationResult(True, status, f"Интернет работает. Доступен {url}")
            last_message = message
        return WifiOperationResult(False, status, f"DNS работает, но сайты проекта недоступны: {last_message}")
    def status(self) -> WifiStatus:
        if not self._is_linux():
            return self._unsupported_status()
        if self._has_nmcli():
            return self._nmcli_status()
        return self._fallback_status()
    def _nmcli_scan(self) -> WifiScanResult:
        status = self._nmcli_status()
        result = self._runner(
            ["nmcli", "-t", "-f", "IN-USE,SSID,SIGNAL,SECURITY", "dev", "wifi", "list", "--rescan", "yes"],
            12.0,
        )
        if result.returncode != 0:
            status.message = self._clean_error(result.stderr or result.stdout or "Не удалось просканировать Wi-Fi")
            return WifiScanResult(status=status, networks=[])
        networks = self._parse_nmcli_networks(result.stdout, status.ssid)
        return WifiScanResult(status=status, networks=networks)
    def _nmcli_status(self) -> WifiStatus:
        radio = self._runner(["nmcli", "-t", "-f", "WIFI", "radio"], WIFI_TIMEOUT_SECONDS)
        enabled = "enabled" in radio.stdout.lower() or "включ" in radio.stdout.lower()
        current = self._runner(["nmcli", "-t", "-f", "ACTIVE,SSID,SIGNAL", "dev", "wifi"], WIFI_TIMEOUT_SECONDS)
        ssid = ""
        signal: int | None = None
        for raw_line in current.stdout.splitlines():
            fields = self._split_nmcli_line(raw_line)
            if len(fields) >= 2 and fields[0].lower() in {"yes", "да"}:
                ssid = fields[1]
                signal = self._safe_int(fields[2]) if len(fields) > 2 else None
                break
        ip_address = self._ip_address()
        connected = bool(ssid)
        if connected:
            message = "подключено"
        elif enabled:
            message = "Wi-Fi включён, но сеть не подключена"
        else:
            message = "Wi-Fi выключен"
        return WifiStatus(
            available=True,
            manager="nmcli",
            enabled=enabled,
            connected=connected,
            ssid=ssid,
            ip_address=ip_address,
            signal=signal,
            message=message,
        )
    def _fallback_status(self) -> WifiStatus:
        ssid = self._fallback_ssid()
        ip_address = self._ip_address()
        return WifiStatus(
            available=False,
            manager="fallback",
            enabled=bool(ssid or ip_address),
            connected=bool(ssid),
            ssid=ssid,
            ip_address=ip_address,
            signal=None,
            message="nmcli недоступен. Можно показать статус, но управлять Wi-Fi нельзя",
        )
    def _unsupported_status(self) -> WifiStatus:
        return WifiStatus(
            available=False,
            manager="unsupported",
            message=WIFI_UNSUPPORTED_MESSAGE,
        )
    def _fallback_ssid(self) -> str:
        if shutil.which("iwgetid"):
            result = self._runner(["iwgetid", "-r"], WIFI_TIMEOUT_SECONDS)
            return result.stdout.strip() if result.returncode == 0 else ""
        return ""
    def _ip_address(self) -> str:
        if self._is_linux() and shutil.which("hostname"):
            result = self._runner(["hostname", "-I"], WIFI_TIMEOUT_SECONDS)
            if result.returncode == 0:
                return (result.stdout.strip().split() or [""])[0]
        try:
            return socket.gethostbyname(socket.gethostname())
        except OSError:
            return ""
    def _check_dns(self) -> tuple[bool, str]:
        try:
            socket.gethostbyname("fitodomik.ru")
            return True, "DNS работает"
        except OSError as exc:
            return False, f"Ошибка DNS: {exc}"
    def _check_url(self, url: str) -> tuple[bool, str]:
        if url not in PROJECT_URLS or not _is_trusted_project_url(url, resolve_host=True):
            return False, "недопустимый адрес проверки"
        try:
            request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "FitoDomikDesktop/1.0"})
            opener = urllib.request.build_opener(_ProjectRedirectHandler())
            with opener.open(request, timeout=INTERNET_TIMEOUT_SECONDS) as response:
                return response.status < 500, f"HTTP {response.status}"
        except urllib.error.HTTPError as exc:
            return exc.code < 500, f"HTTP {exc.code}"
        except urllib.error.URLError as exc:
            return False, str(exc.reason)
        except TimeoutError:
            return False, "timeout"
        except OSError as exc:
            return False, str(exc)
    def _has_nmcli(self) -> bool:
        return shutil.which("nmcli") is not None
    def _wifi_device(self) -> str:
        result = self._runner(["nmcli", "-t", "-f", "DEVICE,TYPE,STATE", "device", "status"], WIFI_TIMEOUT_SECONDS)
        if result.returncode != 0:
            return ""
        for raw_line in result.stdout.splitlines():
            fields = self._split_nmcli_line(raw_line)
            if len(fields) >= 2 and fields[1] == "wifi":
                return fields[0]
        return ""
    def _is_linux(self) -> bool:
        return platform.system().lower() == "linux"
    def _run_masked(self, args: list[str], timeout: float) -> subprocess.CompletedProcess[str]:
        masked = self._mask_command(args)
        self.logger.info("Wi-Fi command: %s", " ".join(masked))
        return self._runner(args, timeout)
    def _run_command(self, args: list[str], timeout: float) -> subprocess.CompletedProcess[str]:
        try:
            result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)
            return cast(subprocess.CompletedProcess[str], result)
        except subprocess.TimeoutExpired as exc:
            stdout = self._timeout_output(exc.stdout)
            stderr = self._timeout_output(exc.stderr) or "timeout"
            return subprocess.CompletedProcess(args, 124, stdout, stderr)
        except OSError as exc:
            return subprocess.CompletedProcess(args, 127, "", str(exc))
    def _timeout_output(self, value: bytes | str | None) -> str:
        if isinstance(value, bytes):
            return value.decode(errors="replace")
        return value or ""
    def _parse_nmcli_networks(self, output: str, current_ssid: str = "") -> list[WifiNetwork]:
        networks: dict[str, WifiNetwork] = {}
        for raw_line in output.splitlines():
            fields = self._split_nmcli_line(raw_line)
            if len(fields) < 4:
                continue
            connected = fields[0].strip() == "*"
            ssid = fields[1].strip()
            if not ssid:
                continue
            signal = self._safe_int(fields[2])
            security = fields[3].strip()
            existing = networks.get(ssid)
            candidate = WifiNetwork(
                ssid=ssid,
                signal=signal,
                security=security,
                connected=connected or ssid == current_ssid,
            )
            if existing is None or (candidate.signal or 0) > (existing.signal or 0):
                networks[ssid] = candidate
        return sorted(networks.values(), key=lambda item: (not item.connected, -(item.signal or 0), item.ssid.lower()))
    def _split_nmcli_line(self, line: str) -> list[str]:
        placeholder = "\0"
        escaped = line.replace(r"\:", placeholder)
        return [part.replace(placeholder, ":") for part in escaped.split(":")]
    def _safe_int(self, value: str) -> int | None:
        try:
            return int(value)
        except (TypeError, ValueError):
            return None
    def _clean_error(self, message: str, secrets: tuple[str, ...] = ()) -> str:
        clean = message
        for secret in secrets:
            if secret:
                clean = clean.replace(secret, "***")
        clean = NMCLI_SECRET_SPACE_PATTERN.sub(NMCLI_SECRET_SPACE_REDACTION, clean)
        clean = NMCLI_SECRET_EQUALS_PATTERN.sub(NMCLI_SECRET_EQUALS_REDACTION, clean)
        return clean.strip()
    def _mask_command(self, args: list[str]) -> list[str]:
        masked = list(args)
        for index, item in enumerate(masked[:-1]):
            if item.lower() == NMCLI_PASSWORD_OPTION:
                masked[index + 1] = "***"
        return masked
