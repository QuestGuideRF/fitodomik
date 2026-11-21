from __future__ import annotations
import shutil
import threading
import zipfile
from collections import defaultdict, deque
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any
from fitodomik_app.core.models import ExtraSensorValue
from fitodomik_app.core.settings import SettingsManager
ANALOG_READ_TEMPLATE = "analogRead(pin)"
STATUS_WORKING = "работает"
@dataclass(frozen=True, slots=True)
class BoardDefinition:
    board_id: str
    name: str
    digital_pins: tuple[str, ...]
    analog_pins: tuple[str, ...]
    i2c_pins: tuple[str, ...]
    uart_pins: tuple[str, ...]
    spi_pins: tuple[str, ...]
    fqbn: str
@dataclass(frozen=True, slots=True)
class ExtraSensorDefinition:
    sensor_id: str
    name: str
    connection: str
    required_pins: int = 1
    supported_boards: tuple[str, ...] = ("arduino_nano", "arduino_uno", "arduino_mega")
    library_required: bool = False
    library_name: str = ""
    include_example: str = ""
    read_example: str = ""
    unit: str = "raw"
    value_range: str = "0-1023"
    conversion_formula: str = "raw"
    poll_interval_seconds: int = 5
    graph_enabled: bool = True
    template_status: str = "supported"
@dataclass(slots=True)
class PortUsage:
    pin: str
    label: str
    source: str
@dataclass(slots=True)
class LibraryImportResult:
    status: str
    message: str
    destination: Path | None = None
    warnings: list[str] = field(default_factory=list)
    library_name: str = ""
@dataclass(slots=True)
class ExtraSensorValidationResult:
    ok: bool
    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
@dataclass(slots=True)
class ExtraSensorValidationContext:
    board: BoardDefinition
    uid_seen: set[str]
    pin_owner: dict[str, str]
    i2c_owner: dict[str, str]
    errors: list[str]
    warnings: list[str]
class ExtraSensorRegistry:
    BOARDS: dict[str, BoardDefinition] = {
        "arduino_nano": BoardDefinition(
            board_id="arduino_nano",
            name="Arduino Nano",
            digital_pins=tuple(f"D{i}" for i in range(14)),
            analog_pins=tuple(f"A{i}" for i in range(8)),
            i2c_pins=("A4", "A5"),
            uart_pins=("D0", "D1"),
            spi_pins=("D10", "D11", "D12", "D13"),
            fqbn="arduino:avr:nano",
        ),
        "arduino_nano_old": BoardDefinition(
            board_id="arduino_nano_old",
            name="Arduino Nano old bootloader",
            digital_pins=tuple(f"D{i}" for i in range(14)),
            analog_pins=tuple(f"A{i}" for i in range(8)),
            i2c_pins=("A4", "A5"),
            uart_pins=("D0", "D1"),
            spi_pins=("D10", "D11", "D12", "D13"),
            fqbn="arduino:avr:nano:cpu=atmega328old",
        ),
        "arduino_uno": BoardDefinition(
            board_id="arduino_uno",
            name="Arduino Uno",
            digital_pins=tuple(f"D{i}" for i in range(14)),
            analog_pins=tuple(f"A{i}" for i in range(6)),
            i2c_pins=("A4", "A5"),
            uart_pins=("D0", "D1"),
            spi_pins=("D10", "D11", "D12", "D13"),
            fqbn="arduino:avr:uno",
        ),
        "arduino_mega": BoardDefinition(
            board_id="arduino_mega",
            name="Arduino Mega",
            digital_pins=tuple(f"D{i}" for i in range(54)),
            analog_pins=tuple(f"A{i}" for i in range(16)),
            i2c_pins=("D20", "D21"),
            uart_pins=("D0", "D1", "D14", "D15", "D16", "D17", "D18", "D19"),
            spi_pins=("D50", "D51", "D52", "D53"),
            fqbn="arduino:avr:mega",
        ),
        "manual": BoardDefinition(
            board_id="manual",
            name="Ручная настройка",
            digital_pins=tuple(f"D{i}" for i in range(14)),
            analog_pins=tuple(f"A{i}" for i in range(8)),
            i2c_pins=("A4", "A5"),
            uart_pins=("D0", "D1"),
            spi_pins=("D10", "D11", "D12", "D13"),
            fqbn="",
        ),
    }
    SENSOR_DEFINITIONS: tuple[ExtraSensorDefinition, ...] = (
        ExtraSensorDefinition("analog_raw", "Analog raw", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("soil_moisture", "Soil moisture", "analog", unit="%", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("water_level", "Water level", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq_sensor", "MQ-series sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("soil_analog", "YL-69 / FC-28 soil moisture", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("ldr", "LDR photoresistor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq2", "MQ-2 gas", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq3", "MQ-3 alcohol", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq4", "MQ-4 methane", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq5", "MQ-5 LPG", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq7", "MQ-7 CO", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq8", "MQ-8 hydrogen", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq9", "MQ-9 gas", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("mq135", "MQ-135 air quality", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("ph_analog", "analog pH sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE, template_status="partial"),
        ExtraSensorDefinition("tds_analog", "analog TDS sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE, template_status="partial"),
        ExtraSensorDefinition("water_level_analog", "analog water level sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("rain_analog", "analog rain sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("sound_analog", "analog sound sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("flame_analog", "analog flame sensor", "analog", unit="raw", conversion_formula=ANALOG_READ_TEMPLATE),
        ExtraSensorDefinition("dht11", "DHT11", "digital", library_required=True, library_name="DHT sensor library", include_example="#include <DHT.h>", unit="°C/%", template_status="library_required"),
        ExtraSensorDefinition("dht22", "DHT22", "digital", library_required=True, library_name="DHT sensor library", include_example="#include <DHT.h>", unit="°C/%", template_status="library_required"),
        ExtraSensorDefinition("ds18b20", "DS18B20", "digital", library_required=True, library_name="OneWire + DallasTemperature", include_example="#include <OneWire.h>\n#include <DallasTemperature.h>", unit="°C", template_status="library_required"),
        ExtraSensorDefinition("hc_sr04", "HC-SR04", "digital", required_pins=2, unit="cm", template_status="partial"),
        ExtraSensorDefinition("pir", "PIR motion sensor", "digital", unit="state"),
        ExtraSensorDefinition("rain_digital", "digital rain sensor", "digital", unit="state"),
        ExtraSensorDefinition("flame_digital", "digital flame sensor", "digital", unit="state"),
        ExtraSensorDefinition("water_leak", "water leak sensor", "digital", unit="state"),
        ExtraSensorDefinition("reed_switch", "reed switch", "digital", unit="state"),
        ExtraSensorDefinition("button", "button/switch", "digital", unit="state"),
        ExtraSensorDefinition("light_digital", "digital light sensor", "digital", unit="state"),
        ExtraSensorDefinition("bme280_i2c", "BME280", "i2c", library_required=True, library_name="Adafruit BME280", include_example="#include <Adafruit_BME280.h>", unit="°C/%/hPa", template_status="partial"),
        ExtraSensorDefinition("bmp280_i2c", "BMP280", "i2c", library_required=True, library_name="Adafruit BMP280", template_status="partial"),
        ExtraSensorDefinition("aht10", "AHT10", "i2c", library_required=True, library_name="AHT10", template_status="partial"),
        ExtraSensorDefinition("aht20", "AHT20", "i2c", library_required=True, library_name="AHT20", template_status="partial"),
        ExtraSensorDefinition("sht30", "SHT30", "i2c", library_required=True, library_name="SHT3x", template_status="partial"),
        ExtraSensorDefinition("sht31", "SHT31", "i2c", library_required=True, library_name="SHT3x", template_status="partial"),
        ExtraSensorDefinition("bh1750", "BH1750", "i2c", library_required=True, library_name="BH1750", unit="lux", template_status="partial"),
        ExtraSensorDefinition("oled_display", "OLED display", "i2c", library_required=True, library_name="Adafruit SSD1306", graph_enabled=False, template_status="partial"),
        ExtraSensorDefinition("ads1115", "ADS1115", "i2c", library_required=True, library_name="Adafruit ADS1X15", template_status="partial"),
        ExtraSensorDefinition("pcf8574", "PCF8574", "i2c", library_required=True, library_name="PCF8574", template_status="partial"),
        ExtraSensorDefinition("mh_z19b_uart", "MH-Z19B", "uart", library_required=True, library_name="MHZ19_uart", unit="ppm", template_status="partial"),
        ExtraSensorDefinition("pms5003", "PMS5003", "uart", library_required=True, library_name="PMS", unit="µg/m³", template_status="partial"),
        ExtraSensorDefinition("sds011", "SDS011", "uart", library_required=True, library_name="SDS011", unit="µg/m³", template_status="partial"),
        ExtraSensorDefinition("gps", "GPS module", "uart", library_required=True, library_name="TinyGPSPlus", graph_enabled=False, template_status="partial"),
        ExtraSensorDefinition("sd_card", "SD card module", "spi", library_required=True, library_name="SD", graph_enabled=False, template_status="partial"),
        ExtraSensorDefinition("rfid_rc522", "RFID RC522", "spi", library_required=True, library_name="MFRC522", graph_enabled=False, template_status="partial"),
    )
    def __init__(self) -> None:
        self._by_id = {item.sensor_id: item for item in self.SENSOR_DEFINITIONS}
    def board(self, board_id: str) -> BoardDefinition:
        return self.BOARDS.get(board_id, self.BOARDS["arduino_nano"])
    def sensors_for_connection(self, connection: str) -> list[ExtraSensorDefinition]:
        return [item for item in self.SENSOR_DEFINITIONS if item.connection == connection]
    def sensor(self, sensor_id: str) -> ExtraSensorDefinition | None:
        return self._by_id.get(sensor_id)
class ExtraSensorConfigManager:
    SERIAL_USAGE = (
        PortUsage("D0", "USB Serial", "reserved"),
        PortUsage("D1", "USB Serial", "reserved"),
    )
    BASE_USAGE = (
        PortUsage("D7", "Реле 1: вентилятор", "relay"),
        PortUsage("D6", "Реле 2: насос", "relay"),
        PortUsage("D5", "Реле 3: шторы", "relay"),
        PortUsage("D4", "Реле 4: лампа", "relay"),
        PortUsage("D3", "MH-Z19B UART RX/TX", "sensor"),
        PortUsage("D2", "MH-Z19B UART RX/TX", "sensor"),
        PortUsage("A6", "YL-69 влажность почвы", "sensor"),
    )
    def __init__(self, settings: SettingsManager, registry: ExtraSensorRegistry | None = None) -> None:
        self.settings = settings
        self.registry = registry or ExtraSensorRegistry()
    def enabled(self) -> bool:
        return bool(self.settings.get("extra_sensors_enabled", True))
    def board_id(self) -> str:
        board_id = str(self.settings.get("extra_sensors_board", "arduino_nano"))
        return board_id if board_id in self.registry.BOARDS else "arduino_nano"
    def board(self) -> BoardDefinition:
        return self.registry.board(self.board_id())
    def set_board(self, board_id: str) -> None:
        if board_id in self.registry.BOARDS:
            self.settings.set("extra_sensors_board", board_id, save=True)
    def sensors(self) -> list[dict[str, Any]]:
        sensors = self.settings.get("extra_sensors_config", [])
        return [dict(item) for item in sensors if isinstance(item, dict)]
    def save_sensors(self, sensors: list[dict[str, Any]]) -> None:
        self.settings.set("extra_sensors_config", sensors, save=True)
    def add_sensor(
        self,
        sensor_id: str,
        name: str,
        connection: str,
        pin: str,
        enabled: bool = True,
        *,
        send_to_server: bool = False,
        show_in_monitoring: bool = True,
        show_in_charts: bool = True,
        calibration: dict[str, Any] | None = None,
    ) -> tuple[bool, str]:
        definition = self.registry.sensor(sensor_id)
        if not definition:
            return False, "Неизвестный датчик"
        pin = pin.strip().upper()
        pins = self._pins_from_pin_string(pin)
        if connection in {"analog", "digital"} and len(pins) < max(1, definition.required_pins):
            return False, f"Для {definition.name} нужно пинов: {definition.required_pins}"
        if not self.is_pin_free(pin, connection):
            return False, f"Пин {pin} занят или недоступен"
        slug = self._safe_id(f"{sensor_id}_{pin}")
        sensors = self.sensors()
        if any(item.get("id") == slug for item in sensors):
            return False, "Такой датчик уже добавлен"
        sensors.append(
            {
                "id": slug,
                "name": name.strip() or definition.name,
                "type": sensor_id,
                "pin": pin,
                "connection": connection,
                "unit": definition.unit,
                "enabled": bool(enabled),
                "send_to_server": bool(send_to_server),
                "show_in_monitoring": bool(show_in_monitoring),
                "show_in_charts": bool(show_in_charts),
                "calibration": calibration or {},
                "template_status": definition.template_status,
            }
        )
        self.save_sensors(sensors)
        return True, "Датчик добавлен"
    def remove_sensor(self, sensor_config_id: str) -> tuple[bool, str]:
        sensors = self.sensors()
        kept = [item for item in sensors if str(item.get("id", "")) != sensor_config_id]
        if len(kept) == len(sensors):
            return False, "Датчик не найден"
        self.save_sensors(kept)
        return True, "Датчик удалён"
    def occupied_ports(self, include_user: bool = True) -> list[PortUsage]:
        board = self.board()
        occupied: list[PortUsage] = []
        valid = self._valid_board_ports(board)
        occupied.extend(item for item in self._base_usage() if item.pin in valid)
        occupied.extend(self._base_i2c_port_usage(board))
        if include_user:
            occupied.extend(self._user_port_usage())
        return occupied
    def _valid_board_ports(self, board: BoardDefinition) -> set[str]:
        return set(board.digital_pins) | set(board.analog_pins) | set(board.i2c_pins) | set(board.uart_pins) | set(board.spi_pins)
    def _base_i2c_port_usage(self, board: BoardDefinition) -> list[PortUsage]:
        if not self._uses_i2c_base():
            return []
        return [PortUsage(pin, "BME280 / I2C шина", "i2c") for pin in board.i2c_pins]
    def _user_port_usage(self) -> list[PortUsage]:
        occupied: list[PortUsage] = []
        for sensor in self.sensors():
            if not sensor.get("enabled", True):
                continue
            label = str(sensor.get("name") or sensor.get("type"))
            occupied.extend(PortUsage(pin, label, "extra") for pin in self._pins_from_config(sensor))
        return occupied
    def _base_usage(self) -> list[PortUsage]:
        mode = str(self.settings.get("device_config_mode", "standard")).strip().lower()
        sensors = self.settings.get("device_sensors", [])
        relays = self.settings.get("device_relays", [])
        if mode == "empty":
            return list(self.SERIAL_USAGE)
        if isinstance(sensors, list) and sensors or isinstance(relays, list) and relays:
            return self._custom_base_usage(sensors, relays)
        return [*self.BASE_USAGE, *self.SERIAL_USAGE]
    def _custom_base_usage(self, sensors: Any, relays: Any) -> list[PortUsage]:
        usage = list(self.SERIAL_USAGE)
        sensor_items = sensors if isinstance(sensors, list) else []
        relay_items = relays if isinstance(relays, list) else []
        usage.extend(self._sensor_base_usage(sensor_items))
        usage.extend(self._relay_base_usage(relay_items))
        return usage
    def _sensor_base_usage(self, sensors: list[Any]) -> list[PortUsage]:
        usage: list[PortUsage] = []
        for sensor in sensors:
            if not isinstance(sensor, dict) or not sensor.get("enabled", True):
                continue
            label = str(sensor.get("name") or sensor.get("sensor_type") or "Датчик")
            usage.extend(PortUsage(pin, label, "sensor") for pin in self._pins_from_config(sensor))
        return usage
    def _relay_base_usage(self, relays: list[Any]) -> list[PortUsage]:
        usage: list[PortUsage] = []
        for relay in relays:
            if not isinstance(relay, dict) or not relay.get("enabled", True):
                continue
            pin = str(relay.get("pin", "")).strip().upper()
            if pin:
                usage.append(PortUsage(pin, str(relay.get("name") or relay.get("relay_uid") or "Реле"), "relay"))
        return usage
    def _uses_i2c_base(self) -> bool:
        mode = str(self.settings.get("device_config_mode", "standard")).strip().lower()
        if mode == "empty":
            return False
        sensors = self.settings.get("device_sensors", [])
        if not isinstance(sensors, list) or not sensors:
            return True
        return any(
            isinstance(sensor, dict)
            and sensor.get("enabled", True)
            and str(sensor.get("connection", "")).lower() == "i2c"
            for sensor in sensors
        )
    def _base_i2c_addresses(self) -> set[str]:
        sensors = self.settings.get("device_sensors", [])
        if not isinstance(sensors, list) or not sensors:
            return {"0x76"} if self._uses_i2c_base() else set()
        result: set[str] = set()
        for sensor in sensors:
            if (
                isinstance(sensor, dict)
                and sensor.get("enabled", True)
                and str(sensor.get("connection", "")).lower() == "i2c"
            ):
                address = self._normalize_i2c_address(sensor.get("address") or sensor.get("i2c_address") or "")
                if address:
                    result.add(address)
        return result
    def _pins_from_config(self, config: dict[str, Any]) -> list[str]:
        connection = str(config.get("connection", "")).lower()
        if connection == "i2c":
            return []
        pin = str(config.get("pin", "")).strip().upper()
        return self._pins_from_pin_string(pin)
    def _pins_from_pin_string(self, pin: str) -> list[str]:
        pin = str(pin or "").strip().upper()
        if not pin:
            return []
        return [item.strip().upper() for item in pin.split("/") if item.strip()]
    def _pin_combinations(self, pins: list[str], required_pins: int) -> list[str]:
        if required_pins <= 1:
            return pins
        result: list[str] = []
        def collect(start: int, current: list[str]) -> None:
            if len(result) >= 120:
                return
            if len(current) == required_pins:
                result.append("/".join(current))
                return
            for index in range(start, len(pins)):
                collect(index + 1, [*current, pins[index]])
        collect(0, [])
        return result
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
    def free_pins(self, connection: str, required_pins: int = 1) -> list[str]:
        board = self.board()
        if connection == "analog":
            pins = list(board.analog_pins)
        elif connection == "digital":
            pins = list(board.digital_pins)
        elif connection == "i2c":
            pins = ["I2C"]
        elif connection == "uart":
            pins = ["/".join(board.uart_pins)]
        elif connection == "spi":
            pins = ["SPI"]
        else:
            pins = []
        occupied = {item.pin for item in self.occupied_ports(include_user=True)}
        if connection in {"i2c", "uart", "spi"}:
            return pins
        free = [pin for pin in pins if pin not in occupied]
        if connection == "digital" and required_pins > 1:
            return self._pin_combinations(free, required_pins)
        return free
    def is_pin_free(self, pin: str, connection: str) -> bool:
        if connection in {"i2c", "uart", "spi"}:
            return bool(pin)
        pins = self._pins_from_pin_string(pin)
        if not pins:
            return False
        available = set(self.free_pins(connection))
        return all(item in available for item in pins)
    def port_summary(self) -> dict[str, Any]:
        board = self.board()
        occupied = self.occupied_ports(include_user=True)
        occupied_pins = {item.pin for item in occupied}
        digital_total = len(board.digital_pins)
        analog_total = len(board.analog_pins)
        digital_occupied = len([pin for pin in board.digital_pins if pin in occupied_pins])
        analog_occupied = len([pin for pin in board.analog_pins if pin in occupied_pins])
        return {
            "board": board,
            "digital_total": digital_total,
            "digital_occupied": digital_occupied,
            "digital_free": max(0, digital_total - digital_occupied),
            "analog_total": analog_total,
            "analog_occupied": analog_occupied,
            "analog_free": max(0, analog_total - analog_occupied),
            "occupied": occupied,
            "free_digital": self.free_pins("digital"),
            "free_analog": self.free_pins("analog"),
        }
    def validate_sensors(self, sensors: list[dict[str, Any]] | None = None) -> ExtraSensorValidationResult:
        board = self.board()
        configs = self.sensors() if sensors is None else [dict(item) for item in sensors if isinstance(item, dict)]
        valid_pins = set(board.digital_pins) | set(board.analog_pins)
        context = ExtraSensorValidationContext(
            board=board,
            uid_seen=set(),
            pin_owner=self._initial_pin_owners(valid_pins),
            i2c_owner=self._initial_i2c_owners(),
            errors=[],
            warnings=[],
        )
        for index, sensor in enumerate(configs, start=1):
            self._validate_sensor_config(index, sensor, context)
        return ExtraSensorValidationResult(not context.errors, context.errors, context.warnings)
    def _initial_pin_owners(self, valid_pins: set[str]) -> dict[str, str]:
        return {item.pin: item.label for item in self._base_usage() if item.pin in valid_pins}
    def _initial_i2c_owners(self) -> dict[str, str]:
        if not self._uses_i2c_base():
            return {}
        return dict.fromkeys(self._base_i2c_addresses(), "BME280 / стандартная конфигурация")
    def _validate_sensor_config(
        self,
        index: int,
        sensor: dict[str, Any],
        context: ExtraSensorValidationContext,
    ) -> None:
        if not sensor.get("enabled", True):
            return
        uid, label = self._validate_sensor_uid(index, sensor, context)
        connection = str(sensor.get("connection", "")).strip().lower()
        if connection not in {"analog", "digital", "i2c", "uart", "spi"}:
            context.errors.append(f"{label}: неизвестный тип подключения {connection or 'пусто'}")
            return
        owner = str(sensor.get("profile_group_uid") or uid or label)
        required_pins = self._required_pins(sensor)
        if connection in {"analog", "digital"}:
            self._validate_pin_sensor(sensor, label, owner, connection, required_pins, context)
        elif connection == "i2c":
            self._validate_i2c_sensor(sensor, label, owner, context)
    def _validate_sensor_uid(
        self,
        index: int,
        sensor: dict[str, Any],
        context: ExtraSensorValidationContext,
    ) -> tuple[str, str]:
        uid = str(sensor.get("id") or sensor.get("sensor_uid") or "").strip()
        label = uid or f"датчик #{index}"
        if not uid:
            context.errors.append(f"{label}: sensor_uid пустой")
        elif uid in context.uid_seen:
            context.errors.append(f"{label}: sensor_uid повторяется")
        context.uid_seen.add(uid)
        return uid, label
    def _required_pins(self, sensor: dict[str, Any]) -> int:
        definition = self.registry.sensor(str(sensor.get("type") or sensor.get("sensor_type") or ""))
        return int(getattr(definition, "required_pins", 1) or 1)
    def _validate_pin_sensor(
        self,
        sensor: dict[str, Any],
        label: str,
        owner: str,
        connection: str,
        required_pins: int,
        context: ExtraSensorValidationContext,
    ) -> None:
        pins = self._pins_from_config(sensor)
        if len(pins) < required_pins:
            context.errors.append(f"{label}: нужно пинов {required_pins}, задано {len(pins)}")
        allowed = set(context.board.analog_pins if connection == "analog" else context.board.digital_pins)
        for pin in pins:
            self._claim_pin(pin, label, owner, allowed, context)
        if connection == "analog" and len(pins) > 1:
            context.warnings.append(f"{label}: аналоговый датчик использует несколько пинов, проверьте конфигурацию")
    def _claim_pin(
        self,
        pin: str,
        label: str,
        owner: str,
        allowed: set[str],
        context: ExtraSensorValidationContext,
    ) -> None:
        if pin not in allowed:
            context.errors.append(f"{label}: пин {pin} недоступен для {context.board.name}")
            return
        previous = context.pin_owner.get(pin)
        if previous and previous != owner:
            context.errors.append(f"{label}: пин {pin} уже занят ({previous})")
        context.pin_owner[pin] = owner
    def _validate_i2c_sensor(
        self,
        sensor: dict[str, Any],
        label: str,
        owner: str,
        context: ExtraSensorValidationContext,
    ) -> None:
        address = self._normalize_i2c_address(sensor.get("address") or sensor.get("i2c_address") or "")
        if not address:
            context.errors.append(f"{label}: I2C-адрес должен быть в формате 0x76")
            return
        previous = context.i2c_owner.get(address)
        if previous and previous != owner:
            context.errors.append(f"{label}: I2C-адрес {address} уже занят ({previous})")
        context.i2c_owner[address] = owner
    def values_from_payload(self, payload: dict[str, Any]) -> list[ExtraSensorValue]:
        now = datetime.now()
        configs = {str(item.get("id", "")): item for item in self.sensors()}
        values: list[ExtraSensorValue] = []
        emitted: set[str] = set()
        for sensor_id, value in payload.items():
            sensor_key = str(sensor_id)
            if isinstance(value, dict):
                self._append_payload_object_values(sensor_key, value, configs, values, emitted, now)
                continue
            config = configs.get(sensor_key, {})
            values.append(self._extra_value(sensor_key, value, STATUS_WORKING, config, now))
            emitted.add(sensor_key)
        return values
    def _append_payload_object_values(
        self,
        sensor_key: str,
        payload: dict[str, Any],
        configs: dict[str, dict[str, Any]],
        values: list[ExtraSensorValue],
        emitted: set[str],
        timestamp: datetime,
    ) -> None:
        config = configs.get(sensor_key, {})
        if config:
            preferred = str(config.get("profile_output") or "").strip()
            sensor_value, status = self._value_from_payload_object(payload, preferred)
            values.append(self._extra_value(sensor_key, sensor_value, status, config, timestamp))
            emitted.add(sensor_key)
        status = self._payload_object_status(payload)
        for field_name, field_value in payload.items():
            if field_name in {"ok", "error", "unit", "status"}:
                continue
            derived_id = self._safe_id(f"{sensor_key}_{field_name}")
            if derived_id in configs and derived_id not in emitted:
                values.append(self._extra_value(derived_id, field_value, status, configs[derived_id], timestamp))
                emitted.add(derived_id)
    def _payload_object_status(self, payload: dict[str, Any]) -> str:
        if payload.get("ok", True) is not False:
            return STATUS_WORKING
        return str(payload.get("error") or "ошибка чтения")
    def _value_from_payload_object(self, payload: dict[str, Any], preferred_field: str = "") -> tuple[Any, str]:
        ok = payload.get("ok", True) is not False
        status = STATUS_WORKING if ok else str(payload.get("error") or "ошибка чтения")
        if preferred_field and preferred_field in payload:
            return payload.get(preferred_field), status
        for key, value in payload.items():
            if key not in {"ok", "error", "unit", "status"}:
                return value, status
        return str(payload.get("error") or "нет данных"), status
    def _extra_value(
        self,
        sensor_id: str,
        value: Any,
        status: str,
        config: dict[str, Any],
        timestamp: datetime,
    ) -> ExtraSensorValue:
        definition = self.registry.sensor(str(config.get("type", ""))) if config else None
        return ExtraSensorValue(
            sensor_id=sensor_id,
            name=str(config.get("name") or sensor_id),
            value=value if value is not None else str(config.get("error") or status),
            unit=str(config.get("unit") or (definition.unit if definition else "")),
            pin=str(config.get("pin", "")),
            connection=str(config.get("connection", "")),
            status=status,
            timestamp=timestamp,
        )
    def fqbn(self) -> str:
        manual = str(self.settings.get("extra_sensors_manual_fqbn", "")).strip()
        if self.board_id() == "manual" and manual:
            return manual
        return self.board().fqbn
    def _safe_id(self, value: str) -> str:
        result = "".join(ch.lower() if ch.isalnum() else "_" for ch in value)
        while "__" in result:
            result = result.replace("__", "_")
        return result.strip("_") or "extra_sensor"
class ExtraSensorHistory:
    def __init__(self, max_points: int = 2000) -> None:
        self.max_points = max_points
        self._items: dict[str, deque[ExtraSensorValue]] = defaultdict(lambda: deque(maxlen=max_points))
        self._latest: dict[str, ExtraSensorValue] = {}
        self._lock = threading.RLock()
    def add_many(self, values: list[ExtraSensorValue]) -> None:
        with self._lock:
            for value in values:
                self._items[value.sensor_id].append(value)
                self._latest[value.sensor_id] = value
    def latest_values(self) -> list[ExtraSensorValue]:
        with self._lock:
            return sorted(self._latest.values(), key=lambda item: item.name.lower())
    def latest_for(self, sensor_id: str) -> ExtraSensorValue | None:
        with self._lock:
            return self._latest.get(sensor_id)
    def series(self, sensor_id: str, hours: float = 3) -> list[ExtraSensorValue]:
        start = datetime.now() - timedelta(hours=hours)
        with self._lock:
            return [item for item in self._items.get(sensor_id, []) if item.timestamp >= start]
class ExtraSensorLibraryManager:
    def __init__(self, destination_root: Path) -> None:
        self.destination_root = destination_root
        self.destination_root.mkdir(parents=True, exist_ok=True)
    def import_library(self, source: Path, overwrite: bool = False) -> LibraryImportResult:
        if not source.exists():
            return LibraryImportResult("error", "Файл или папка не найдены")
        destination = self.destination_root / self._destination_name(source)
        if destination.exists():
            if not overwrite:
                return LibraryImportResult("exists", "Библиотека уже есть, перезапись не выполнена", destination)
            self._remove_existing(destination)
        try:
            if source.is_dir():
                shutil.copytree(source, destination)
            elif source.suffix.lower() == ".zip":
                destination.mkdir(parents=True, exist_ok=True)
                self._extract_zip_library(source, destination)
            elif source.suffix.lower() in {".h", ".cpp"}:
                destination.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, destination / source.name)
            else:
                return LibraryImportResult("error", "Выберите .zip, папку библиотеки или .h/.cpp файл")
        except Exception as exc:
            return LibraryImportResult("error", f"Ошибка копирования: {exc}")
        warnings, library_name = self._validate(destination)
        status = "warning" if warnings else "ok"
        title = library_name or destination.name
        message = f"Библиотека добавлена: {title}" if not warnings else f"Библиотека добавлена, но структура подозрительная: {title}"
        return LibraryImportResult(status, message, destination, warnings, library_name)
    def list_libraries(self) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        for path in sorted(self.destination_root.iterdir(), key=lambda item: item.name.lower()):
            if not path.is_dir():
                continue
            warnings, library_name = self._validate(path)
            items.append(
                {
                    "name": library_name or path.name,
                    "path": path,
                    "warnings": warnings,
                    "status": "warning" if warnings else "ok",
                }
            )
        return items
    def default_arduino_libraries_dir(self) -> Path:
        return Path.home() / "Documents" / "Arduino" / "libraries"
    def install_to_arduino_libraries(self, library_path: Path, overwrite: bool = False) -> LibraryImportResult:
        if not library_path.exists() or not library_path.is_dir():
            return LibraryImportResult("error", "Локальная библиотека не найдена")
        arduino_root = self.default_arduino_libraries_dir()
        destination = arduino_root / library_path.name
        if destination.exists():
            if not overwrite:
                return LibraryImportResult("exists", "В стандартной папке Arduino такая библиотека уже есть", destination)
            self._remove_existing(destination)
        try:
            arduino_root.mkdir(parents=True, exist_ok=True)
            shutil.copytree(library_path, destination)
        except Exception as exc:
            return LibraryImportResult("error", f"Ошибка копирования в Arduino libraries: {exc}")
        warnings, library_name = self._validate(destination)
        status = "warning" if warnings else "ok"
        return LibraryImportResult(status, "Библиотека скопирована в Arduino libraries", destination, warnings, library_name)
    def _destination_name(self, source: Path) -> str:
        if source.is_dir():
            return self._safe_name(source.name)
        if source.suffix.lower() == ".zip":
            return self._safe_name(source.stem)
        if source.suffix.lower() in {".h", ".cpp"}:
            return self._safe_name(source.stem)
        return self._safe_name(source.name)
    def _validate(self, destination: Path) -> tuple[list[str], str]:
        warnings: list[str] = []
        files = [path for path in destination.rglob("*") if path.is_file()] if destination.is_dir() else []
        if not any(path.suffix.lower() == ".h" for path in files):
            warnings.append("Не найден .h файл")
        properties = next((path for path in files if path.name == "library.properties"), None)
        if not properties:
            warnings.append("Нет library.properties")
            return warnings, ""
        return warnings, self._library_name_from_properties(properties)
    def _extract_zip_library(self, source: Path, destination: Path) -> None:
        with zipfile.ZipFile(source) as archive:
            names = [name for name in archive.namelist() if name and not name.endswith("/")]
            roots = {name.split("/", 1)[0] for name in names if "/" in name}
            strip_root = len(roots) == 1
            root = next(iter(roots)) if strip_root else ""
            for member in names:
                relative = member
                if strip_root and member.startswith(root + "/"):
                    relative = member[len(root) + 1 :]
                if not relative or relative.startswith("../") or "/../" in relative:
                    continue
                target = destination / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                with archive.open(member) as src, target.open("wb") as dst:
                    shutil.copyfileobj(src, dst)
    def _library_name_from_properties(self, path: Path) -> str:
        try:
            for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
                if line.strip().startswith("name="):
                    return line.split("=", 1)[1].strip()
        except Exception:
            return ""
        return ""
    def _remove_existing(self, path: Path) -> None:
        if path.is_dir():
            shutil.rmtree(path)
        else:
            path.unlink()
    def _safe_name(self, name: str) -> str:
        clean = "".join(ch if ch.isalnum() or ch in {"_", "-", "."} else "_" for ch in name).strip("._")
        return clean or "ArduinoLibrary"
