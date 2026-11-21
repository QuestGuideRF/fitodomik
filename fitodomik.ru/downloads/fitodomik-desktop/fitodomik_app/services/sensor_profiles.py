from __future__ import annotations
import json
import shutil
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
ALLOWED_TEMPLATE_STATUS = {"supported", "partial", "library_required"}
REQUIRED_FIELDS = {
    "profile_id",
    "sensor_type",
    "name",
    "connection",
    "includes",
    "constructor_template",
    "setup_template",
    "read_templates",
    "template_status",
}
OPTIONAL_STRING_FIELDS = {
    "read_before_template",
    "read_after_template",
    "invalid_condition",
    "default_address",
}
@dataclass(slots=True)
class SensorProfileValidationResult:
    ok: bool
    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    profile: dict[str, Any] | None = None
@dataclass(slots=True)
class SensorProfileImportResult:
    status: str
    message: str
    destination: Path | None = None
    validation: SensorProfileValidationResult | None = None
class SensorProfileManager:
    def __init__(self, builtin_dir: Path, user_dir: Path) -> None:
        self.builtin_dir = builtin_dir
        self.user_dir = user_dir
        self.user_dir.mkdir(parents=True, exist_ok=True)
    def profiles(self) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        for source, label in ((self.builtin_dir, "builtin"), (self.user_dir, "user")):
            if not source.exists():
                continue
            for path in sorted(source.glob("*.json"), key=lambda item: item.name.lower()):
                validation = self.validate_file(path)
                if validation.profile:
                    profile = dict(validation.profile)
                    profile["_source"] = label
                    profile["_path"] = str(path)
                    profile["_errors"] = validation.errors
                    profile["_warnings"] = validation.warnings
                    items.append(profile)
        return items
    def profile(self, profile_id: str) -> dict[str, Any] | None:
        for item in self.profiles():
            if str(item.get("profile_id")) == profile_id:
                return item
        return None
    def validate_file(self, path: Path) -> SensorProfileValidationResult:
        if not path.exists():
            return SensorProfileValidationResult(False, [f"Файл не найден: {path}"])
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:
            return SensorProfileValidationResult(False, [f"JSON не читается: {exc}"])
        return self.validate_profile(data)
    def validate_profile(self, profile: Any) -> SensorProfileValidationResult:
        errors: list[str] = []
        warnings: list[str] = []
        if not isinstance(profile, dict):
            return SensorProfileValidationResult(False, ["Профиль должен быть JSON-объектом"])
        self._validate_required_fields(profile, errors)
        self._validate_profile_id(profile, errors)
        self._validate_connection(profile, errors)
        self._validate_includes(profile, errors, warnings)
        read_templates = self._validate_read_templates(profile, errors)
        self._validate_template_status(profile, errors)
        self._validate_library_fields(profile, errors, warnings)
        self._validate_required_pins(profile, errors)
        self._validate_template_strings(profile, read_templates, errors)
        return SensorProfileValidationResult(not errors, errors, warnings, dict(profile))
    def _validate_required_fields(self, profile: dict[str, Any], errors: list[str]) -> None:
        missing = sorted(field for field in REQUIRED_FIELDS if field not in profile)
        if missing:
            errors.append("Нет обязательных полей: " + ", ".join(missing))
    def _validate_profile_id(self, profile: dict[str, Any], errors: list[str]) -> None:
        profile_id = str(profile.get("profile_id", "")).strip()
        if not profile_id:
            errors.append("profile_id пустой")
            return
        if not all(ch.isalnum() or ch in {"_", "-"} for ch in profile_id):
            errors.append("profile_id может содержать только буквы, цифры, _ и -")
    def _validate_connection(self, profile: dict[str, Any], errors: list[str]) -> None:
        connection = str(profile.get("connection", "")).strip().lower()
        if connection not in {"digital", "i2c", "analog", "uart", "spi"}:
            errors.append("connection должен быть digital, i2c, analog, uart или spi")
    def _validate_includes(self, profile: dict[str, Any], errors: list[str], warnings: list[str]) -> None:
        includes = profile.get("includes", [])
        if not isinstance(includes, list) or not all(isinstance(item, str) for item in includes):
            errors.append("includes должен быть массивом строк")
            return
        if any(";" in item for item in includes):
            warnings.append("includes содержит необычный символ ';', проверьте профиль вручную")
    def _validate_read_templates(self, profile: dict[str, Any], errors: list[str]) -> list[Any]:
        read_templates = profile.get("read_templates", [])
        if not isinstance(read_templates, list) or not read_templates:
            errors.append("read_templates должен быть непустым массивом")
            return []
        for index, item in enumerate(read_templates, start=1):
            self._validate_read_template_item(index, item, errors)
        return read_templates
    def _validate_read_template_item(self, index: int, item: Any, errors: list[str]) -> None:
        if not isinstance(item, dict):
            errors.append(f"read_templates[{index}] должен быть объектом")
            return
        for key in ("key", "unit", "code"):
            if not str(item.get(key, "")).strip():
                errors.append(f"read_templates[{index}] не содержит {key}")
    def _validate_template_status(self, profile: dict[str, Any], errors: list[str]) -> None:
        status = str(profile.get("template_status", "")).strip()
        if status not in ALLOWED_TEMPLATE_STATUS:
            errors.append("template_status должен быть supported, partial или library_required")
    def _validate_library_fields(self, profile: dict[str, Any], errors: list[str], warnings: list[str]) -> None:
        if profile.get("library_required", False) and not str(profile.get("library_name", "")).strip():
            warnings.append("library_required=true, но library_name пустой")
        library_names = profile.get("library_names", [])
        if library_names and not self._valid_library_names(library_names):
            errors.append("library_names должен быть массивом непустых строк")
    def _valid_library_names(self, library_names: Any) -> bool:
        return isinstance(library_names, list) and all(isinstance(item, str) and item.strip() for item in library_names)
    def _validate_required_pins(self, profile: dict[str, Any], errors: list[str]) -> None:
        required_pins = profile.get("required_pins")
        if required_pins is None:
            return
        try:
            if int(required_pins) < 0:
                errors.append("required_pins должен быть 0 или больше")
        except (TypeError, ValueError):
            errors.append("required_pins должен быть числом")
    def _validate_template_strings(self, profile: dict[str, Any], read_templates: list[Any], errors: list[str]) -> None:
        for key in ("constructor_template", "setup_template"):
            value = profile.get(key, "")
            if not isinstance(value, str):
                errors.append(f"{key} должен быть строкой")
        for key in OPTIONAL_STRING_FIELDS:
            if key in profile and not isinstance(profile.get(key), str):
                errors.append(f"{key} должен быть строкой")
        for index, item in enumerate(read_templates, start=1):
            if isinstance(item, dict) and "invalid_condition" in item and not isinstance(item.get("invalid_condition"), str):
                errors.append(f"read_templates[{index}].invalid_condition должен быть строкой")
    def import_profile(self, source: Path, overwrite: bool = False) -> SensorProfileImportResult:
        validation = self.validate_file(source)
        if not validation.ok or not validation.profile:
            return SensorProfileImportResult("error", "Профиль не импортирован: есть ошибки", None, validation)
        profile_id = str(validation.profile["profile_id"])
        destination = self.user_dir / f"{profile_id}.json"
        if destination.exists() and not overwrite:
            return SensorProfileImportResult("exists", "Профиль уже есть, перезапись не выполнена", destination, validation)
        shutil.copy2(source, destination)
        return SensorProfileImportResult("ok", f"Профиль импортирован: {profile_id}", destination, validation)
    def save_profile(self, profile: dict[str, Any], overwrite: bool = True) -> SensorProfileImportResult:
        validation = self.validate_profile(profile)
        if not validation.ok or not validation.profile:
            return SensorProfileImportResult("error", "Профиль не сохранён: есть ошибки", None, validation)
        profile_id = str(validation.profile["profile_id"])
        destination = self.user_dir / f"{profile_id}.json"
        if destination.exists() and not overwrite:
            return SensorProfileImportResult("exists", "Профиль уже есть, перезапись не выполнена", destination, validation)
        destination.write_text(json.dumps(validation.profile, ensure_ascii=False, indent=2), encoding="utf-8")
        return SensorProfileImportResult("ok", f"Профиль сохранён: {profile_id}", destination, validation)