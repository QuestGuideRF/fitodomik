from __future__ import annotations
import hashlib
import os
import re
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
from fitodomik_app.core.version import APP_VERSION
_REQUESTS_MODULE = None
def _requests_module():
    global _REQUESTS_MODULE
    if _REQUESTS_MODULE is None:
        import requests
        _REQUESTS_MODULE = requests
    return _REQUESTS_MODULE
@dataclass(slots=True)
class UpdateInfo:
    ok: bool
    message: str
    current_version: str = APP_VERSION
    latest_version: str = ""
    package_url: str = ""
    sha256_url: str = ""
    changelog: list[str] = field(default_factory=list)
    raw: dict[str, Any] = field(default_factory=dict)
@dataclass(slots=True)
class UpdateDownloadResult:
    ok: bool
    message: str
    package_path: Path | None = None
    sha256: str = ""
class DesktopUpdater:
    def __init__(self, latest_url: str, download_dir: Path) -> None:
        self.latest_url = latest_url
        self.download_dir = download_dir
        self.download_dir.mkdir(parents=True, exist_ok=True)
        self._session = None
    @property
    def session(self):
        if self._session is None:
            self._session = _requests_module().Session()
        return self._session
    def check(self) -> UpdateInfo:
        try:
            response = self.session.get(
                self.latest_url,
                timeout=12,
                verify=os.environ.get("REQUESTS_CA_BUNDLE", True),
            )
            data = response.json()
        except Exception as exc:
            return UpdateInfo(False, f"Не удалось проверить обновления: {exc}")
        if response.status_code != 200 or not isinstance(data, dict):
            return UpdateInfo(False, "Сайт обновлений вернул некорректный ответ")
        latest = str(data.get("version", "")).strip()
        if not latest:
            return UpdateInfo(False, "В latest.json нет версии")
        changelog = data.get("changelog", [])
        if not isinstance(changelog, list):
            changelog = []
        newer = self._version_tuple(latest) > self._version_tuple(APP_VERSION)
        return UpdateInfo(
            True,
            "Доступна новая версия" if newer else "Установлена актуальная версия",
            current_version=APP_VERSION,
            latest_version=latest,
            package_url=str(data.get("package_url", "")).strip(),
            sha256_url=str(data.get("sha256_url", "")).strip(),
            changelog=[str(item) for item in changelog],
            raw=data,
        )
    def download(self, info: UpdateInfo) -> UpdateDownloadResult:
        if not info.package_url:
            return UpdateDownloadResult(False, "В latest.json нет package_url")
        expected = self._download_sha256(info)
        if not re.fullmatch(r"[a-fA-F0-9]{64}", expected):
            return UpdateDownloadResult(False, "Нет корректной контрольной суммы SHA256: обновление отклонено")
        package_path = self.download_dir / "fitodomik-desktop-update.zip"
        try:
            with self.session.get(
                info.package_url,
                timeout=60,
                stream=True,
                verify=os.environ.get("REQUESTS_CA_BUNDLE", True),
            ) as response:
                if response.status_code != 200:
                    return UpdateDownloadResult(False, f"Пакет не скачан: HTTP {response.status_code}")
                with package_path.open("wb") as file:
                    for chunk in response.iter_content(chunk_size=1024 * 256):
                        if chunk:
                            file.write(chunk)
            actual = self._sha256(package_path)
            if actual.lower() != expected.lower():
                return UpdateDownloadResult(False, "SHA256 не совпал", package_path, actual)
            if not zipfile.is_zipfile(package_path):
                return UpdateDownloadResult(False, "Скачанный файл не является ZIP", package_path, actual)
            return UpdateDownloadResult(True, "Обновление скачано и проверено. Установку можно запустить после закрытия приложения.", package_path, actual)
        except Exception as exc:
            return UpdateDownloadResult(False, f"Не удалось скачать обновление: {exc}", package_path)
    def _download_sha256(self, info: UpdateInfo) -> str:
        if not info.sha256_url:
            return ""
        try:
            response = self.session.get(
                info.sha256_url,
                timeout=15,
                verify=os.environ.get("REQUESTS_CA_BUNDLE", True),
            )
            if response.status_code != 200:
                return ""
            return response.text.strip().split()[0]
        except Exception:
            return ""
    @staticmethod
    def _sha256(path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as file:
            for chunk in iter(lambda: file.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    @staticmethod
    def _version_tuple(value: str) -> tuple[int, ...]:
        parts: list[int] = []
        for item in value.replace("-", ".").split("."):
            digits = "".join(ch for ch in item if ch.isdigit())
            parts.append(int(digits or 0))
        return tuple(parts or [0])
