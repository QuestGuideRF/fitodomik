from __future__ import annotations
from copy import deepcopy
from typing import Any, Iterable
from fitodomik_app.core.models import SensorSnapshot
from fitodomik_app.core.settings import SettingsManager
STANDARD_DEVICE_SENSORS: list[dict[str, Any]] = [
    {
        "sensor_uid": "bme280_temperature",
        "name": "Температура",
        "sensor_type": "bme280_temperature",
        "connection": "i2c",
        "address": "0x76",
        "unit": "°C",
        "source_field": "temperature",
        "icon": "temp",
        "digits": 1,
        "enabled": True,
        "send_to_server": True,
        "show_in_monitoring": True,
        "show_in_charts": True,
        "core": True,
    },
    {
        "sensor_uid": "bme280_humidity",
        "name": "Влажность воздуха",
        "sensor_type": "bme280_humidity",
        "connection": "i2c",
        "address": "0x76",
        "unit": "%",
        "source_field": "humidity",
        "icon": "humidity",
        "digits": 1,
        "enabled": True,
        "send_to_server": True,
        "show_in_monitoring": True,
        "show_in_charts": True,
        "core": True,
    },
    {
        "sensor_uid": "soil_A6",
        "name": "Влажность почвы",
        "sensor_type": "soil_moisture",
        "connection": "analog",
        "pin": "A6",
        "unit": "%",
        "source_field": "soil_moisture",
        "icon": "soil",
        "digits": 1,
        "enabled": True,
        "send_to_server": True,
        "show_in_monitoring": True,
        "show_in_charts": True,
        "core": True,
        "calibration": {
            "enabled": True,
            "type": "linear_percent",
            "min_value": 0,
            "max_value": 100,
        },
    },
    {
        "sensor_uid": "mh_z19b_co2",
        "name": "CO2",
        "sensor_type": "mh_z19b",
        "connection": "uart",
        "pin": "D3/D2",
        "unit": "ppm",
        "source_field": "co2",
        "icon": "co2",
        "digits": 0,
        "enabled": True,
        "send_to_server": True,
        "show_in_monitoring": True,
        "show_in_charts": True,
        "core": True,
    },
    {
        "sensor_uid": "bme280_pressure",
        "name": "Давление",
        "sensor_type": "bme280_pressure",
        "connection": "i2c",
        "address": "0x76",
        "unit": "мм рт. ст.",
        "source_field": "pressure",
        "icon": "pressure",
        "digits": 1,
        "enabled": True,
        "send_to_server": True,
        "show_in_monitoring": True,
        "show_in_charts": True,
        "core": True,
    },
]
STANDARD_DEVICE_RELAYS: list[dict[str, Any]] = [
    {
        "relay_uid": "lamp_D4",
        "name": "Освещение",
        "pin": "D4",
        "type": "relay",
        "active_low": True,
        "state_field": "lamp_state",
        "command": "LAMP",
        "icon": "lamp",
        "enabled": True,
        "show_in_control": True,
        "on_label": "Вкл",
        "off_label": "Выкл",
        "turn_on_text": "Включить лампу",
        "turn_off_text": "Выключить лампу",
        "core": True,
    },
    {
        "relay_uid": "curtains_D5",
        "name": "Шторы",
        "pin": "D5",
        "type": "relay",
        "active_low": True,
        "state_field": "curtains_state",
        "command": "CURTAINS",
        "icon": "curtains",
        "enabled": True,
        "show_in_control": True,
        "on_label": "Открыты",
        "off_label": "Закрыты",
        "turn_on_text": "Открыть шторы",
        "turn_off_text": "Закрыть шторы",
        "core": True,
    },
    {
        "relay_uid": "pump_D6",
        "name": "Насос",
        "pin": "D6",
        "type": "relay",
        "active_low": True,
        "state_field": "pump_state",
        "command": "PUMP",
        "icon": "pump",
        "enabled": True,
        "show_in_control": True,
        "on_label": "Вкл",
        "off_label": "Выкл",
        "turn_on_text": "Включить насос",
        "turn_off_text": "Выключить насос",
        "core": True,
    },
    {
        "relay_uid": "fan_D7",
        "name": "Вентилятор",
        "pin": "D7",
        "type": "relay",
        "active_low": True,
        "state_field": "fan_state",
        "command": "FAN",
        "icon": "fan",
        "enabled": True,
        "show_in_control": True,
        "on_label": "Вкл",
        "off_label": "Выкл",
        "turn_on_text": "Включить вентилятор",
        "turn_off_text": "Выключить вентилятор",
        "core": True,
    },
]
class DeviceConfigManager:
    VALID_MODES = {"standard", "empty", "custom"}
    def __init__(self, settings: SettingsManager, extra_config: Any | None = None) -> None:
        self.settings = settings
        self.extra_config = extra_config
    def mode(self) -> str:
        mode = str(self.settings.get("device_config_mode", "standard")).strip().lower()
        return mode if mode in self.VALID_MODES else "standard"
    def sensors(self, include_disabled: bool = False) -> list[dict[str, Any]]:
        raw = self.settings.get("device_sensors", [])
        items = self._normalize_sensor_list(raw)
        if not items and self.mode() == "standard":
            items = self._normalize_sensor_list(STANDARD_DEVICE_SENSORS)
        if not include_disabled:
            items = [item for item in items if item.get("enabled", True)]
        return items
    def relays(self, include_disabled: bool = False) -> list[dict[str, Any]]:
        raw = self.settings.get("device_relays", [])
        items = self._normalize_relay_list(raw)
        if not items and self.mode() == "standard":
            items = self._normalize_relay_list(STANDARD_DEVICE_RELAYS)
        if not include_disabled:
            items = [item for item in items if item.get("enabled", True)]
        return items
    def monitoring_sensors(self) -> list[dict[str, Any]]:
        sensors = [item for item in self.sensors() if item.get("show_in_monitoring", True)]
        sensors.extend(item for item in self.extra_sensors() if item.get("show_in_monitoring", True))
        return sensors
    def chart_sensors(self) -> list[dict[str, Any]]:
        sensors = [item for item in self.sensors() if item.get("show_in_charts", True)]
        sensors.extend(item for item in self.extra_sensors() if item.get("show_in_charts", True))
        return sensors
    def server_sensors(self) -> list[dict[str, Any]]:
        sensors = [item for item in self.sensors() if item.get("send_to_server", True)]
        sensors.extend(item for item in self.extra_sensors() if item.get("send_to_server", False))
        return sensors
    def extra_sensors(self) -> list[dict[str, Any]]:
        if self.extra_config is None:
            return []
        try:
            configs = self.extra_config.sensors()
        except Exception:
            return []
        result: list[dict[str, Any]] = []
        for index, item in enumerate(configs):
            if not isinstance(item, dict) or not item.get("enabled", True):
                continue
            uid = str(item.get("id") or f"extra_{index}")
            result.append(
                {
                    "sensor_uid": uid,
                    "name": str(item.get("name") or uid),
                    "sensor_type": str(item.get("type") or "extra"),
                    "connection": str(item.get("connection") or ""),
                    "pin": str(item.get("pin") or ""),
                    "unit": str(item.get("unit") or ""),
                    "address": str(item.get("address") or ""),
                    "source_kind": "extra",
                    "icon": "monitor",
                    "digits": 1,
                    "enabled": True,
                    "send_to_server": bool(item.get("send_to_server", False)),
                    "show_in_monitoring": bool(item.get("show_in_monitoring", True)),
                    "show_in_charts": bool(item.get("show_in_charts", True)),
                    "calibration": item.get("calibration", {}) if isinstance(item.get("calibration"), dict) else {},
                    "profile": item.get("profile", ""),
                    "extra": True,
                }
            )
        return result
    def control_relays(self) -> list[dict[str, Any]]:
        return [item for item in self.relays() if item.get("show_in_control", True)]
    def has_configured_sensors(self) -> bool:
        return bool(self.monitoring_sensors() or self.chart_sensors() or self.server_sensors())
    def has_configured_relays(self) -> bool:
        return bool(self.control_relays())
    def has_extra_sensors(self) -> bool:
        if self.extra_config is None:
            return False
        try:
            return any(item.get("enabled", True) for item in self.extra_config.sensors())
        except Exception:
            return False
    def sensor_value(self, sensor: dict[str, Any], snapshot: SensorSnapshot) -> float | None:
        if self.is_extra_sensor(sensor):
            return None
        source_field = str(sensor.get("source_field", "")).strip()
        if not source_field:
            return None
        value = getattr(snapshot, source_field, None)
        if value is None:
            return None
        try:
            return float(value)
        except (TypeError, ValueError):
            return None
    def sensor_points(
        self,
        sensor: dict[str, Any],
        snapshots: Iterable[SensorSnapshot],
    ) -> list[tuple[Any, float]]:
        points: list[tuple[Any, float]] = []
        for snapshot in snapshots:
            value = self.sensor_value(sensor, snapshot)
            if value is not None:
                points.append((snapshot.timestamp, value))
        return points
    def snapshot_has_configured_values(self, snapshot: SensorSnapshot) -> bool:
        return any(self.sensor_value(sensor, snapshot) is not None for sensor in self.server_sensors())
    def is_extra_sensor(self, sensor: dict[str, Any]) -> bool:
        return str(sensor.get("source_kind", "")).lower() == "extra" or bool(sensor.get("extra", False))
    def relay_state(self, relay: dict[str, Any], snapshot: SensorSnapshot) -> bool:
        state_field = str(relay.get("state_field", "")).strip()
        if state_field:
            return bool(getattr(snapshot, state_field, False))
        return snapshot.extra_relay_states.get(str(relay.get("relay_uid", "")).upper(), False)
    def feature_flags(self) -> dict[str, bool]:
        has_sensors = bool(self.monitoring_sensors())
        has_chart_sensors = bool(self.chart_sensors())
        has_core_sensors = bool(self.sensors())
        has_relays = bool(self.control_relays())
        has_extra = self.has_extra_sensors()
        has_device = has_sensors or has_relays or has_extra
        has_core_device = bool(self.sensors() or self.relays())
        return {
            "weather": True,
            "settings": True,
            "monitoring": has_sensors,
            "charts": has_chart_sensors,
            "control": has_relays,
            "thresholds": has_core_sensors or has_relays,
            "analysis": has_core_device and bool(self.settings.get("analysis_feature_enabled", True)),
            "notifications": has_device and bool(self.settings.get("show_notification_center", True)),
            "extra_devices": has_extra and bool(self.settings.get("show_extra_devices_tab", True)),
            "automation_scenarios": has_sensors and has_relays,
        }
    def use_standard_config(self, save: bool = True) -> None:
        self.settings.update(
            {
                "device_config_mode": "standard",
                "device_sensors": deepcopy(STANDARD_DEVICE_SENSORS),
                "device_relays": deepcopy(STANDARD_DEVICE_RELAYS),
            },
            save=save,
        )
    def use_empty_config(self, save: bool = True) -> None:
        self.settings.update(
            {
                "device_config_mode": "empty",
                "device_sensors": [],
                "device_relays": [],
            },
            save=save,
        )
    def set_mode(self, mode: str, save: bool = True) -> None:
        mode = mode if mode in self.VALID_MODES else "standard"
        if mode == "standard":
            self.use_standard_config(save=save)
        elif mode == "empty":
            self.use_empty_config(save=save)
        else:
            self.settings.set("device_config_mode", "custom", save=save)
    def sensor_label(self, sensor: dict[str, Any]) -> str:
        return str(sensor.get("name") or sensor.get("sensor_uid") or "Датчик")
    def relay_label(self, relay: dict[str, Any]) -> str:
        return str(relay.get("name") or relay.get("relay_uid") or "Реле")
    def format_sensor_value(self, sensor: dict[str, Any], value: float | None) -> str:
        unit = str(sensor.get("unit", "")).strip()
        suffix = f" {unit}" if unit else ""
        if value is None:
            return f"--{suffix}"
        digits = int(sensor.get("digits", 1))
        if digits <= 0:
            return f"{value:.0f}{suffix}"
        return f"{value:.{digits}f}{suffix}"
    def _normalize_sensor_list(self, value: Any) -> list[dict[str, Any]]:
        if not isinstance(value, list):
            return []
        result: list[dict[str, Any]] = []
        for index, item in enumerate(value):
            if not isinstance(item, dict):
                continue
            sensor = dict(item)
            uid = str(sensor.get("sensor_uid") or sensor.get("id") or sensor.get("source_field") or f"sensor_{index}")
            sensor["sensor_uid"] = uid
            sensor.setdefault("name", uid)
            sensor.setdefault("sensor_type", sensor.get("type", uid))
            sensor.setdefault("connection", "")
            sensor.setdefault("unit", "")
            sensor.setdefault("icon", "monitor")
            sensor.setdefault("digits", 1)
            sensor.setdefault("enabled", True)
            sensor.setdefault("send_to_server", True)
            sensor.setdefault("show_in_monitoring", True)
            sensor.setdefault("show_in_charts", True)
            result.append(sensor)
        return result
    def _normalize_relay_list(self, value: Any) -> list[dict[str, Any]]:
        if not isinstance(value, list):
            return []
        result: list[dict[str, Any]] = []
        for index, item in enumerate(value):
            if not isinstance(item, dict):
                continue
            relay = dict(item)
            uid = str(relay.get("relay_uid") or relay.get("id") or relay.get("state_field") or f"relay_{index}")
            relay["relay_uid"] = uid
            relay.setdefault("name", uid)
            relay.setdefault("type", relay.get("relay_type", "relay"))
            relay.setdefault("pin", "")
            relay.setdefault("active_low", True)
            relay.setdefault("command", uid)
            relay.setdefault("icon", "control")
            relay.setdefault("state_field", "")
            relay.setdefault("enabled", True)
            relay.setdefault("show_in_control", True)
            relay.setdefault("on_label", "Вкл")
            relay.setdefault("off_label", "Выкл")
            relay.setdefault("turn_on_text", f"Включить {relay['name']}")
            relay.setdefault("turn_off_text", f"Выключить {relay['name']}")
            result.append(relay)
        return result
