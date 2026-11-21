from __future__ import annotations
import json
import re
import shutil
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any
from fitodomik_app.services.arduino_cli import SUPPORTED_SENSOR_LIBRARIES
from fitodomik_app.services.extra_sensors import ExtraSensorConfigManager, ExtraSensorRegistry
from fitodomik_app.services.sensor_profiles import SensorProfileManager
BEGIN_MARKER = "// FITODOMIK_EXTRA_SENSORS_BEGIN"
END_MARKER = "// FITODOMIK_EXTRA_SENSORS_END"
ENDIF_DIRECTIVE = "#endif"
GENERATED_FILES = ("GeneratedConfig.h", "ExtraSensors.h", "ExtraSensors.cpp")
BACKUP_DIR = "firmware_backups"
@dataclass(slots=True)
class FirmwareBackupInfo:
    path: Path
    name: str
    created_at: str
    legacy: bool = False
@dataclass(slots=True)
class FirmwareGenerationResult:
    ok: bool
    message: str
    sketch_path: Path
    backup_path: Path | None = None
    generated_files: list[Path] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
class ArduinoCodeGenerator:
    def __init__(
        self,
        config: ExtraSensorConfigManager,
        registry: ExtraSensorRegistry | None = None,
        profiles: SensorProfileManager | None = None,
    ) -> None:
        self.config = config
        self.registry = registry or config.registry
        self.profiles = profiles
    def generate(self, sketch_path: Path) -> FirmwareGenerationResult:
        if not sketch_path.exists():
            return FirmwareGenerationResult(False, "fitodomik.ino не найден", sketch_path)
        warnings: list[str] = []
        sensors, sensor_warnings = self._configured_sensors()
        try:
            relays = self._configured_extra_relays()
        except ValueError as exc:
            return FirmwareGenerationResult(False, str(exc), sketch_path)
        warnings.extend(sensor_warnings)
        validation = self.config.validate_sensors()
        warnings.extend(validation.warnings)
        if not validation.ok:
            return FirmwareGenerationResult(
                False,
                "Генерация остановлена: " + "; ".join(validation.errors),
                sketch_path,
                warnings=warnings,
            )
        backup_path = self._backup(sketch_path)
        self._write_generated_config(sketch_path.parent / "GeneratedConfig.h", sensors, relays)
        self._write_if_changed(sketch_path.parent / "ExtraSensors.h", self._extra_sensors_h())
        self._write_if_changed(sketch_path.parent / "ExtraSensors.cpp", self._extra_sensors_cpp(sensors))
        text = sketch_path.read_text(encoding="utf-8")
        text = self._remove_old_inline_block(text)
        text = self._ensure_includes(text)
        text = self._ensure_setup_call(text)
        text = self._ensure_loop_call(text)
        self._write_if_changed(sketch_path, text)
        generated = [sketch_path.parent / name for name in GENERATED_FILES]
        return FirmwareGenerationResult(
            True,
            f"Generated-файлы обновлены. Датчиков: {len(sensors)}, доп. реле: {len(relays)}",
            sketch_path,
            backup_path,
            generated,
            warnings,
        )
    def create_backup(self, sketch_path: Path) -> FirmwareGenerationResult:
        if not sketch_path.exists():
            return FirmwareGenerationResult(False, "fitodomik.ino не найден", sketch_path)
        backup_path = self._backup(sketch_path)
        return FirmwareGenerationResult(True, f"Backup создан: {backup_path.name}", sketch_path, backup_path)
    def required_libraries(self, include_supported: bool = False) -> list[str]:
        sensors, _warnings = self._configured_sensors()
        names: list[str] = []
        seen: set[str] = set()
        for sensor in sensors:
            for name in self._library_names(sensor):
                if name and name not in seen:
                    seen.add(name)
                    names.append(name)
        if include_supported:
            for name in SUPPORTED_SENSOR_LIBRARIES:
                if name not in seen:
                    seen.add(name)
                    names.append(name)
        return names
    def list_backups(self, sketch_path: Path) -> list[FirmwareBackupInfo]:
        root = sketch_path.parent / BACKUP_DIR
        backups: list[FirmwareBackupInfo] = []
        if root.exists():
            for path in sorted(root.iterdir(), reverse=True):
                if not path.is_dir():
                    continue
                manifest = path / "manifest.json"
                created = path.name.replace("backup_", "")
                if manifest.exists():
                    try:
                        data = json.loads(manifest.read_text(encoding="utf-8"))
                        created = str(data.get("created_at") or created)
                    except Exception:
                        pass
                backups.append(FirmwareBackupInfo(path=path, name=path.name, created_at=created))
        for legacy in sorted(sketch_path.parent.glob(f"{sketch_path.name}.backup_*"), reverse=True):
            backups.append(
                FirmwareBackupInfo(
                    path=legacy,
                    name=legacy.name,
                    created_at=legacy.name.split(".backup_", 1)[-1],
                    legacy=True,
                )
            )
        return backups
    def rollback_latest(self, sketch_path: Path, backup_path: Path | None = None) -> FirmwareGenerationResult:
        backups = self.list_backups(sketch_path)
        if backup_path is None:
            if not backups:
                return FirmwareGenerationResult(False, "Backup прошивки не найден", sketch_path)
            selected = backups[0]
        else:
            selected = next((item for item in backups if item.path == backup_path), None)
            if selected is None:
                return FirmwareGenerationResult(False, "Выбранный backup не найден", sketch_path)
        if selected.legacy:
            shutil.copy2(selected.path, sketch_path)
            return FirmwareGenerationResult(True, f"Прошивка восстановлена из {selected.name}", sketch_path, selected.path)
        sketch_backup = selected.path / sketch_path.name
        if not sketch_backup.exists():
            return FirmwareGenerationResult(False, "В backup нет fitodomik.ino", sketch_path, selected.path)
        shutil.copy2(sketch_backup, sketch_path)
        for filename in GENERATED_FILES:
            target = sketch_path.parent / filename
            source = selected.path / filename
            if source.exists():
                shutil.copy2(source, target)
            elif target.exists():
                target.unlink()
        return FirmwareGenerationResult(True, f"Прошивка восстановлена из {selected.name}", sketch_path, selected.path)
    def _backup(self, sketch_path: Path) -> Path:
        root = sketch_path.parent / BACKUP_DIR
        root.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        backup = root / f"backup_{stamp}"
        suffix = 1
        while backup.exists():
            backup = root / f"backup_{stamp}_{suffix}"
            suffix += 1
        backup.mkdir(parents=True)
        shutil.copy2(sketch_path, backup / sketch_path.name)
        files: dict[str, bool] = {sketch_path.name: True}
        for filename in GENERATED_FILES:
            source = sketch_path.parent / filename
            files[filename] = source.exists()
            if source.exists():
                shutil.copy2(source, backup / filename)
        (backup / "manifest.json").write_text(
            json.dumps(
                {
                    "created_at": datetime.now().isoformat(timespec="seconds"),
                    "sketch": sketch_path.name,
                    "files": files,
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )
        return backup
    def _configured_sensors(self) -> tuple[list[dict[str, Any]], list[str]]:
        warnings: list[str] = []
        result: list[dict[str, Any]] = []
        seen: set[str] = set()
        self._append_configured_extra_sensors(result, warnings, seen)
        self._append_device_analog_sensors(result, seen)
        return result, warnings
    def _append_configured_extra_sensors(
        self,
        result: list[dict[str, Any]],
        warnings: list[str],
        seen: set[str],
    ) -> None:
        for item in self.config.sensors():
            sensor = self._enabled_sensor_config(item)
            if sensor is None:
                continue
            uid = str(sensor.get("id") or sensor.get("sensor_uid") or "").strip()
            self._append_normalized_sensor(sensor, uid, result, warnings, seen)
    def _append_device_analog_sensors(self, result: list[dict[str, Any]], seen: set[str]) -> None:
        device_sensors = self.config.settings.get("device_sensors", [])
        if not isinstance(device_sensors, list):
            return
        for item in device_sensors:
            sensor = self._enabled_device_analog_config(item)
            if sensor is None:
                continue
            uid = str(sensor.get("sensor_uid") or sensor.get("id") or "").strip()
            if not uid or uid in seen:
                continue
            seen.add(uid)
            result.append(self._normalize_sensor(sensor, uid))
    def _enabled_sensor_config(self, item: Any) -> dict[str, Any] | None:
        if not isinstance(item, dict) or not item.get("enabled", True):
            return None
        return dict(item)
    def _enabled_device_analog_config(self, item: Any) -> dict[str, Any] | None:
        if not isinstance(item, dict) or not item.get("enabled", True) or item.get("core", False):
            return None
        if str(item.get("connection", "")).lower() != "analog":
            return None
        return dict(item)
    def _append_normalized_sensor(
        self,
        sensor: dict[str, Any],
        uid: str,
        result: list[dict[str, Any]],
        warnings: list[str],
        seen: set[str],
    ) -> None:
        if not uid:
            warnings.append("Дополнительный датчик без uid пропущен")
            return
        if uid in seen:
            warnings.append(f"Дубликат uid датчика пропущен: {uid}")
            return
        seen.add(uid)
        normalized = self._normalize_sensor(sensor, uid)
        if normalized["connection"] != "analog" and not normalized.get("_profile"):
            warnings.append(f"{uid}: не найден JSON-профиль, в прошивке будет объект ошибки")
        result.append(normalized)
    def _normalize_sensor(self, sensor: dict[str, Any], uid: str) -> dict[str, Any]:
        sensor_type = str(sensor.get("type") or sensor.get("sensor_type") or "analog_raw")
        pin = str(sensor.get("pin") or "").strip().upper()
        unit = str(sensor.get("unit") or "raw")
        connection = str(sensor.get("connection") or "analog").strip().lower()
        profile = self._profile_for_sensor(sensor)
        address = str(sensor.get("address") or sensor.get("i2c_address") or (profile or {}).get("default_address") or "").strip()
        raw_calibration = sensor.get("calibration") or {}
        calibration = raw_calibration if isinstance(raw_calibration, dict) else {}
        calibration_enabled, raw_min, raw_max, invert = self._calibration_fields(calibration)
        return {
            "uid": uid,
            "type": self._firmware_sensor_type(sensor_type),
            "source_type": sensor_type,
            "connection": connection,
            "pin": pin,
            "address": self._normalize_i2c_address(address),
            "unit": unit,
            "field": str(sensor.get("profile_output") or "value"),
            "profile": str(sensor.get("profile") or (profile or {}).get("profile_id") or ""),
            "profile_output": str(sensor.get("profile_output") or ""),
            "profile_group_uid": str(sensor.get("profile_group_uid") or ""),
            "calibration_enabled": calibration_enabled,
            "raw_min": raw_min,
            "raw_max": raw_max,
            "invert": invert,
            "library_name": str(sensor.get("library_name") or (profile or {}).get("library_name") or ""),
            "_profile": profile,
        }
    def _profile_for_sensor(self, sensor: dict[str, Any]) -> dict[str, Any] | None:
        if self.profiles is None:
            return None
        sensor_type = str(sensor.get("type") or sensor.get("sensor_type") or "").strip()
        profile_id = str(sensor.get("profile") or sensor.get("profile_id") or "").strip()
        candidates = [profile_id]
        if sensor_type:
            candidates.extend(
                [
                    sensor_type,
                    f"{sensor_type}_default",
                    f"{sensor_type.replace('_i2c', '')}_default",
                    f"{sensor_type.replace('_default', '')}_default",
                ]
            )
        aliases = {
            "aht10": "aht10_aht20_default",
            "aht20": "aht10_aht20_default",
            "aht10_aht20": "aht10_aht20_default",
        }
        if sensor_type in aliases:
            candidates.append(aliases[sensor_type])
        for candidate in [item for item in candidates if item]:
            profile = self.profiles.profile(candidate)
            if profile:
                return profile
        normalized_type = sensor_type.replace("_i2c", "")
        for profile in self.profiles.profiles():
            if str(profile.get("sensor_type") or "").strip() in {sensor_type, normalized_type}:
                return profile
        return None
    def _configured_extra_relays(self) -> list[dict[str, Any]]:
        relays = self.config.settings.get("device_relays", [])
        if not isinstance(relays, list):
            return []
        result: list[dict[str, Any]] = []
        used_pins: set[int] = set()
        used_uids: set[str] = set()
        sensor_pins = {
            int(match.group(1))
            for sensor in self.config.sensors()
            for match in re.finditer(r"(?:^|[/, ])D(\d+)(?=$|[/, ])", str(sensor.get("pin", "")).upper())
        }
        for item in relays:
            if not isinstance(item, dict) or not item.get("enabled", True) or item.get("core", False):
                continue
            uid = str(item.get("relay_uid") or item.get("id") or "").strip()
            pin = self._pin_number(str(item.get("pin", "")))
            if not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", uid):
                raise ValueError("Дополнительное реле имеет недопустимый UID")
            if pin is None or pin < 8 or pin > 13:
                raise ValueError(f"Дополнительное реле {uid}: используйте свободный D8–D13")
            if pin in used_pins or uid.upper() in used_uids:
                raise ValueError(f"Дополнительное реле {uid}: повтор UID или вывода D{pin}")
            if pin in sensor_pins or uid.upper() in {"FAN", "PUMP", "CURTAINS", "LAMP", "LED", "R1", "R2", "R3", "R4", "RELAY1", "RELAY2", "RELAY3", "RELAY4"}:
                raise ValueError(f"Дополнительное реле {uid}: конфликт с датчиком или базовым реле")
            used_pins.add(pin)
            used_uids.add(uid.upper())
            result.append(
                {
                    "uid": uid,
                    "name": str(item.get("name") or uid),
                    "pin": pin,
                    "active_low": bool(item.get("active_low", True)),
                }
            )
        return result
    def _calibration_fields(self, calibration: dict[str, Any]) -> tuple[bool, int, int, bool]:
        if not calibration or not calibration.get("enabled", True):
            return False, 0, 1023, False
        try:
            raw_min = calibration.get("raw_min", calibration.get("dry_raw"))
            raw_max = calibration.get("raw_max", calibration.get("wet_raw"))
            if raw_min is None or raw_max is None:
                return False, 0, 1023, False
            return True, int(float(raw_min)), int(float(raw_max)), bool(calibration.get("invert", False))
        except (TypeError, ValueError):
            return False, 0, 1023, False
    def _firmware_sensor_type(self, sensor_type: str) -> str:
        normalized = sensor_type.strip().lower()
        if normalized in {"soil_analog", "soil", "soil_moisture_analog"}:
            return "soil_moisture"
        if normalized in {"water_level_analog", "water_level_sensor"}:
            return "water_level"
        if normalized.startswith("mq"):
            return "mq_sensor"
        if normalized in {"analog", "raw"}:
            return "analog_raw"
        return normalized or "analog_raw"
    def _write_generated_config(self, path: Path, sensors: list[dict[str, Any]], relays: list[dict[str, Any]]) -> None:
        lines = [
            "// This file is generated by FitoDomik desktop.",
            "// Do not edit manually: use Settings -> Third-party sensors -> Generate code.",
            "#pragma once",
            "",
            "#include <Arduino.h>",
            "",
            f"#define FITODOMIK_EXTRA_SENSORS_ENABLED {1 if sensors and self.config.enabled() else 0}",
            f"#define FITODOMIK_EXTRA_SENSOR_COUNT {len(sensors)}",
            f"#define FITODOMIK_EXTRA_SENSOR_INTERVAL_MS {max(1, int(self.config.settings.get('extra_sensors_poll_interval', 5))) * 1000}UL",
            "",
            "struct ExtraSensorConfig {",
            "  const char* uid;",
            "  const char* type;",
            "  const char* connection;",
            "  const char* pin;",
            "  const char* address;",
            "  const char* unit;",
            "  const char* field;",
            "  const char* profile;",
            "  bool calibrationEnabled;",
            "  int calibrationRawMin;",
            "  int calibrationRawMax;",
            "  bool calibrationInvert;",
            "};",
            "",
        ]
        if sensors:
            lines.append("static const ExtraSensorConfig EXTRA_SENSORS[] = {")
            for sensor in sensors:
                lines.append(
                    "  {"
                    f"{self._cpp_string(sensor['uid'])}, "
                    f"{self._cpp_string(sensor['type'])}, "
                    f"{self._cpp_string(sensor['connection'])}, "
                    f"{self._cpp_string(sensor['pin'])}, "
                    f"{self._cpp_string(sensor['address'])}, "
                    f"{self._cpp_string(sensor['unit'])}, "
                    f"{self._cpp_string(sensor['field'])}, "
                    f"{self._cpp_string(sensor['profile'])}, "
                    f"{self._cpp_bool(sensor['calibration_enabled'])}, "
                    f"{sensor['raw_min']}, "
                    f"{sensor['raw_max']}, "
                    f"{self._cpp_bool(sensor['invert'])}"
                    "},"
                )
            lines.append("};")
        lines.extend(
            [
                "",
                f"#define FITODOMIK_EXTRA_RELAY_COUNT {len(relays)}",
                "",
                "struct ExtraRelayConfig {",
                "  const char* uid;",
                "  const char* name;",
                "  uint8_t pin;",
                "  bool activeLow;",
                "};",
                "",
            ]
        )
        if relays:
            lines.append("static const ExtraRelayConfig EXTRA_RELAYS[] = {")
            for relay in relays:
                lines.append(
                    "  {"
                    f"{self._cpp_string(relay['uid'])}, "
                    f"{self._cpp_string(relay['name'])}, "
                    f"{relay['pin']}, "
                    f"{self._cpp_bool(relay['active_low'])}"
                    "},"
                )
            lines.append("};")
        lines.append("")
        self._write_if_changed(path, "\n".join(lines))
    def _extra_sensors_h(self) -> str:
        return "\n".join(
            [
                "#pragma once",
                '#include <Arduino.h>',
                '#include "GeneratedConfig.h"',
                "int readAnalogPin(const char* pinName);",
                "void setupExtraSensors();",
                "void sendExtraSensorsJson();",
                "void sendExtraSensorsData();",
                "",
            ]
        )
    def _extra_sensors_cpp(self, sensors: list[dict[str, Any]]) -> str:
        groups = self._profile_groups(sensors)
        includes = self._profile_includes(sensors)
        declarations = self._profile_declarations(groups)
        setup_body = self._setup_body(sensors, groups)
        read_body = self._read_body(sensors, groups)
        helpers = self._cpp_helpers(needs_hcsr04=self._needs_hcsr04(sensors))
        parts = [
            '#include "ExtraSensors.h"',
            "",
            "#include <math.h>",
            "#include <string.h>",
            *includes,
            "",
            "#ifndef FITODOMIK_EXTRA_SENSOR_INTERVAL_MS",
            "#define FITODOMIK_EXTRA_SENSOR_INTERVAL_MS 5000UL",
            ENDIF_DIRECTIVE,
            "",
            "static unsigned long lastExtraSensorsAt = 0;",
            "",
            helpers.rstrip(),
            "",
        ]
        if declarations:
            parts.extend([declarations.rstrip(), ""])
        parts.extend(
            [
                "void setupExtraSensors() {",
                "#if FITODOMIK_EXTRA_SENSORS_ENABLED && FITODOMIK_EXTRA_SENSOR_COUNT > 0",
                setup_body.rstrip() or "  // No setup required.",
                ENDIF_DIRECTIVE,
                "}",
                "",
                "void sendExtraSensorsJson() {",
                "  unsigned long now = millis();",
                "  if (lastExtraSensorsAt != 0 && now - lastExtraSensorsAt < FITODOMIK_EXTRA_SENSOR_INTERVAL_MS) {",
                "    return;",
                "  }",
                "  lastExtraSensorsAt = now;",
                "",
                '  Serial.print("EXTRA_SENSORS:{");',
                "  bool first = true;",
                "#if FITODOMIK_EXTRA_SENSORS_ENABLED && FITODOMIK_EXTRA_SENSOR_COUNT > 0",
                read_body.rstrip() or "  // No extra sensors configured.",
                ENDIF_DIRECTIVE,
                '  Serial.println("}");',
                "}",
                "",
                "void sendExtraSensorsData() {",
                "  sendExtraSensorsJson();",
                "}",
                "",
            ]
        )
        return "\n".join(parts)
    def _cpp_helpers(self, needs_hcsr04: bool = False) -> str:
        helper = ""
        if not needs_hcsr04:
            return helper
        return (
            helper
            + """
static float readHcSr04Cm(uint8_t trigPin, uint8_t echoPin) {
  digitalWrite(trigPin, LOW);
  delayMicroseconds(2);
  digitalWrite(trigPin, HIGH);
  delayMicroseconds(10);
  digitalWrite(trigPin, LOW);
  unsigned long duration = pulseIn(echoPin, HIGH, 30000UL);
  if (duration == 0) return -1.0;
  return duration / 58.0;
}
"""
        )
    def _profile_groups(self, sensors: list[dict[str, Any]]) -> list[dict[str, Any]]:
        groups: dict[tuple[str, str, str, str, str], dict[str, Any]] = {}
        order: list[tuple[str, str, str, str, str]] = []
        for sensor in sensors:
            if sensor["connection"] == "analog":
                continue
            profile = sensor.get("_profile")
            profile_id = str((profile or {}).get("profile_id") or sensor.get("profile") or sensor["type"])
            owner = str(sensor.get("profile_group_uid") or "")
            key = (profile_id, sensor["connection"], sensor["pin"], sensor["address"], owner)
            if key not in groups:
                order.append(key)
                groups[key] = {
                    "profile": profile,
                    "profile_id": profile_id,
                    "connection": sensor["connection"],
                    "pin": sensor["pin"],
                    "address": sensor["address"],
                    "var_name": self._cpp_identifier("_".join(["extra", profile_id, sensor["pin"], sensor["address"]]) or sensor["uid"]),
                    "sensors": [],
                }
            groups[key]["sensors"].append(sensor)
        return [groups[key] for key in order]
    def _profile_includes(self, sensors: list[dict[str, Any]]) -> list[str]:
        includes: list[str] = []
        seen: set[str] = set()
        for sensor in sensors:
            profile = sensor.get("_profile")
            raw = profile.get("includes", []) if isinstance(profile, dict) else []
            if not isinstance(raw, list):
                continue
            for include in raw:
                line = str(include).strip()
                if line and line not in seen:
                    seen.add(line)
                    includes.append(line)
        return includes
    def _profile_declarations(self, groups: list[dict[str, Any]]) -> str:
        lines: list[str] = []
        for group in groups:
            profile = group.get("profile")
            if not isinstance(profile, dict):
                continue
            template = str(profile.get("constructor_template") or "").strip()
            if not template:
                continue
            rendered = self._format_template(template, group)
            if rendered.strip():
                lines.extend(rendered.splitlines())
        return "\n".join(lines)
    def _setup_body(self, sensors: list[dict[str, Any]], groups: list[dict[str, Any]]) -> str:
        lines: list[str] = []
        for sensor in sensors:
            if sensor["connection"] != "analog":
                continue
            lines.append(f"  (void)readAnalogPin({self._cpp_string(sensor['pin'])});")
        for group in groups:
            profile = group.get("profile")
            if not isinstance(profile, dict):
                continue
            template = str(profile.get("setup_template") or "").strip()
            if not template:
                continue
            for line in self._format_template(template, group).splitlines():
                lines.append(f"  {line}")
        return "\n".join(lines)
    def _read_body(self, sensors: list[dict[str, Any]], groups: list[dict[str, Any]]) -> str:
        lines: list[str] = []
        grouped_ids = {sensor["uid"] for group in groups for sensor in group["sensors"]}
        for sensor in sensors:
            if sensor["uid"] in grouped_ids:
                continue
            if sensor["connection"] == "analog":
                lines.extend(self._render_analog_read(sensor))
            else:
                lines.append(f"  printExtraError(first, {self._cpp_string(sensor['uid'])}, \"unsupported_profile\");")
        for group in groups:
            lines.extend(self._render_profile_group(group))
        return "\n".join(lines)
    def _render_analog_read(self, sensor: dict[str, Any]) -> list[str]:
        raw_var = self._cpp_identifier(f"raw_{sensor['uid']}")
        value_var = self._cpp_identifier(f"value_{sensor['uid']}")
        return [
            "  {",
            f"    int {raw_var} = readAnalogPin({self._cpp_string(sensor['pin'])});",
            f"    if ({raw_var} < 0) {{",
            f"      printExtraError(first, {self._cpp_string(sensor['uid'])}, \"pin_unavailable\");",
            "    } else {",
            (
                f"      int {value_var} = calibratedAnalogValue({raw_var}, "
                f"{self._cpp_bool(sensor['calibration_enabled'])}, {sensor['raw_min']}, {sensor['raw_max']}, "
                f"{self._cpp_bool(sensor['invert'])});"
            ),
            f"      printExtraLongValue(first, {self._cpp_string(sensor['uid'])}, {self._cpp_string(sensor['field'])}, {value_var});",
            "    }",
            "  }",
        ]
    def _render_profile_group(self, group: dict[str, Any]) -> list[str]:
        profile = group.get("profile")
        if not isinstance(profile, dict):
            return self._render_profile_missing_group(group)
        lines = ["  {"]
        self._append_profile_template(lines, profile.get("read_before_template"), group)
        templates = self._read_templates(profile)
        for sensor in group["sensors"]:
            lines.extend(self._render_profile_sensor(sensor, group, profile, templates))
        self._append_profile_template(lines, profile.get("read_after_template"), group)
        lines.append("  }")
        return lines
    def _render_profile_missing_group(self, group: dict[str, Any]) -> list[str]:
        lines = ["  {"]
        for sensor in group["sensors"]:
            lines.append(f"    printExtraError(first, {self._cpp_string(sensor['uid'])}, \"profile_not_found\");")
        lines.append("  }")
        return lines
    def _append_profile_template(self, lines: list[str], template: Any, group: dict[str, Any]) -> None:
        text = str(template or "").strip()
        if not text:
            return
        for line in self._format_template(text, group).splitlines():
            lines.append(f"    {line}")
    def _render_profile_sensor(
        self,
        sensor: dict[str, Any],
        group: dict[str, Any],
        profile: dict[str, Any],
        templates: list[dict[str, Any]],
    ) -> list[str]:
        selected = self._selected_templates(sensor, templates)
        if not selected:
            return [f"    printExtraError(first, {self._cpp_string(sensor['uid'])}, \"output_not_found\");"]
        if len(selected) == 1:
            return self._render_profile_value(sensor, group, profile, selected[0])
        return self._render_profile_multi_value(sensor, group, profile, selected)
    def _render_profile_value(
        self,
        sensor: dict[str, Any],
        group: dict[str, Any],
        profile: dict[str, Any],
        template: dict[str, Any],
    ) -> list[str]:
        field = str(template.get("key") or sensor.get("field") or "value")
        value_var = self._cpp_identifier(f"{group['var_name']}_{sensor['uid']}_{field}")
        invalid_var = self._cpp_identifier(f"invalid_{sensor['uid']}_{field}")
        expression = self._format_template(str(template.get("code") or "0"), group)
        invalid_condition = str(template.get("invalid_condition") or profile.get("invalid_condition") or "").strip()
        if invalid_condition:
            invalid_condition = self._format_template(invalid_condition, group).replace("{value_var}", value_var)
        lines = [
            f"    float {value_var} = (float)({expression});",
            f"    bool {invalid_var} = isnan({value_var});",
        ]
        if invalid_condition:
            lines.append(f"    {invalid_var} = {invalid_var} || ({invalid_condition});")
        lines.append(
            f"    printExtraFloatValue(first, {self._cpp_string(sensor['uid'])}, "
            f"{self._cpp_string(field)}, {value_var}, {invalid_var});"
        )
        return lines
    def _render_profile_multi_value(
        self,
        sensor: dict[str, Any],
        group: dict[str, Any],
        profile: dict[str, Any],
        templates: list[dict[str, Any]],
    ) -> list[str]:
        fields_var = self._cpp_identifier(f"first_field_{sensor['uid']}")
        ok_var = self._cpp_identifier(f"ok_{sensor['uid']}")
        lines = [
            f"    printExtraObjectPrefix(first, {self._cpp_string(sensor['uid'])});",
            f"    bool {fields_var} = true;",
            f"    bool {ok_var} = true;",
        ]
        for template in templates:
            field = str(template.get("key") or "value")
            value_var = self._cpp_identifier(f"{group['var_name']}_{sensor['uid']}_{field}")
            invalid_var = self._cpp_identifier(f"invalid_{sensor['uid']}_{field}")
            expression = self._format_template(str(template.get("code") or "0"), group)
            invalid_condition = str(template.get("invalid_condition") or profile.get("invalid_condition") or "").strip()
            if invalid_condition:
                invalid_condition = self._format_template(invalid_condition, group).replace("{value_var}", value_var)
            lines.extend(
                [
                    f"    float {value_var} = (float)({expression});",
                    f"    bool {invalid_var} = isnan({value_var});",
                ]
            )
            if invalid_condition:
                lines.append(f"    {invalid_var} = {invalid_var} || ({invalid_condition});")
            lines.append(
                f"    printExtraFloatField({fields_var}, {self._cpp_string(field)}, {value_var}, {invalid_var}, {ok_var});"
            )
        lines.append(f"    printExtraObjectEnd({ok_var});")
        return lines
    def _read_templates(self, profile: dict[str, Any]) -> list[dict[str, Any]]:
        raw = profile.get("read_templates", [])
        return [dict(item) for item in raw if isinstance(item, dict)]
    def _selected_templates(self, sensor: dict[str, Any], templates: list[dict[str, Any]]) -> list[dict[str, Any]]:
        output = str(sensor.get("profile_output") or "").strip()
        if not output and str(sensor.get("field") or "") != "value":
            output = str(sensor.get("field") or "").strip()
        if output:
            return [item for item in templates if str(item.get("key") or "").strip() == output]
        return templates
    def _needs_hcsr04(self, sensors: list[dict[str, Any]]) -> bool:
        for sensor in sensors:
            profile = sensor.get("_profile")
            if not isinstance(profile, dict):
                continue
            for template in self._read_templates(profile):
                if "readHcSr04Cm" in str(template.get("code") or ""):
                    return True
        return False
    def _format_template(self, template: str, group: dict[str, Any]) -> str:
        pin_names = self._pin_names(str(group.get("pin") or ""))
        address = self._address_expr(str(group.get("address") or ""))
        if len(pin_names) > 1:
            echo_pin_name = pin_names[1]
        elif pin_names:
            echo_pin_name = pin_names[0]
        else:
            echo_pin_name = ""
        replacements = {
            "var_name": str(group.get("var_name") or "extraSensor"),
            "pin": self._pin_expr(pin_names[0] if pin_names else ""),
            "pin_name": pin_names[0] if pin_names else "",
            "trig_pin": self._pin_expr(pin_names[0] if pin_names else ""),
            "echo_pin": self._pin_expr(echo_pin_name),
            "address": address,
        }
        result = template
        for key, value in replacements.items():
            result = result.replace("{" + key + "}", value)
        return result
    def _pin_names(self, pin: str) -> list[str]:
        return [item.strip().upper() for item in str(pin or "").split("/") if item.strip()]
    def _pin_expr(self, pin: str) -> str:
        normalized = pin.strip().upper()
        if normalized.startswith("D") and normalized[1:].isdigit():
            return normalized[1:]
        if normalized.startswith("A") and normalized[1:].isdigit():
            return normalized
        if normalized.isdigit():
            return normalized
        return "0"
    def _address_expr(self, address: str) -> str:
        normalized = self._normalize_i2c_address(address)
        return normalized or "0x76"
    def _library_names(self, sensor: dict[str, Any]) -> list[str]:
        profile = sensor.get("_profile")
        typed_profile = profile if isinstance(profile, dict) else None
        return self._unique_library_names(self._raw_library_names(sensor, typed_profile))
    def _raw_library_names(self, sensor: dict[str, Any], profile: dict[str, Any] | None) -> list[Any]:
        raw: list[Any] = []
        if profile is not None:
            names = profile.get("library_names", [])
            if isinstance(names, list) and names:
                raw.extend(names)
            elif profile.get("library_name"):
                raw.append(profile.get("library_name"))
        if sensor.get("library_name") and (
            profile is None or str(sensor.get("library_name")) != str(profile.get("library_name") or "")
        ):
            raw.append(sensor.get("library_name"))
        return raw
    def _unique_library_names(self, raw: list[Any]) -> list[str]:
        result: list[str] = []
        for item in raw:
            for part in str(item or "").replace("+", ",").split(","):
                name = part.strip()
                if name and name not in result:
                    result.append(name)
        return result
    def _remove_old_inline_block(self, text: str) -> str:
        if BEGIN_MARKER not in text or END_MARKER not in text:
            return text
        pattern = re.compile(r"\n?\s*" + re.escape(BEGIN_MARKER) + r".*?" + re.escape(END_MARKER) + r"\s*", re.S)
        return pattern.sub("\n", text).rstrip() + "\n"
    def _ensure_includes(self, text: str) -> str:
        includes = ['#include "GeneratedConfig.h"', '#include "ExtraSensors.h"']
        missing = [line for line in includes if line not in text]
        if not missing:
            return text
        lines = text.splitlines()
        insert_at = 0
        for index, line in enumerate(lines):
            if line.strip().startswith("#include"):
                insert_at = index + 1
        for line in reversed(missing):
            lines.insert(insert_at, line)
        return "\n".join(lines) + ("\n" if text.endswith("\n") else "")
    def _ensure_setup_call(self, text: str) -> str:
        if "setupExtraSensors();" in text:
            return text
        pattern = r"(void\s+setup\s*\(\s*\)\s*\{)"
        match = re.search(pattern, text)
        if not match:
            return text
        return text[: match.end()] + "\n  setupExtraSensors();" + text[match.end() :]
    def _ensure_loop_call(self, text: str) -> str:
        text = text.replace("sendExtraSensorsData();", "sendExtraSensorsJson();")
        if "sendExtraSensorsJson();" in text:
            return text
        target = "sendSerialData();"
        match = re.search(r"^([ \t]*)" + re.escape(target), text, flags=re.M)
        if not match:
            return text
        insert = f"{match.group(0)}\n{match.group(1)}sendExtraSensorsJson();"
        return text[: match.start()] + insert + text[match.end() :]
    def _write_if_changed(self, path: Path, content: str) -> None:
        content = content.replace("\r\n", "\n")
        if path.exists() and path.read_text(encoding="utf-8").replace("\r\n", "\n") == content:
            return
        path.write_text(content, encoding="utf-8")
    def _pin_number(self, pin: str) -> int | None:
        normalized = pin.strip().upper()
        if normalized.startswith("D") and normalized[1:].isdigit():
            return int(normalized[1:])
        if normalized.isdigit():
            return int(normalized)
        return None
    def _normalize_i2c_address(self, address: Any) -> str:
        text = str(address or "").strip().lower()
        if not text:
            return ""
        try:
            value = int(text, 16) if text.startswith("0x") else int(text)
        except ValueError:
            return ""
        if value < 0x03 or value > 0x77:
            return ""
        return f"0x{value:02x}"
    def _cpp_identifier(self, value: Any) -> str:
        text = str(value or "")
        result = "".join(ch if ch.isalnum() else "_" for ch in text)
        while "__" in result:
            result = result.replace("__", "_")
        result = result.strip("_") or "extra_sensor"
        if result[0].isdigit():
            result = "extra_" + result
        return result
    def _cpp_string(self, value: Any) -> str:
        text = str(value).replace("\\", "\\\\").replace('"', '\\"')
        return f'"{text}"'
    def _cpp_bool(self, value: Any) -> str:
        return "true" if bool(value) else "false"
