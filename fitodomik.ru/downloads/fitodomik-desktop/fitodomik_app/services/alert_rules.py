from __future__ import annotations
import operator
from dataclasses import dataclass
from datetime import datetime, time
from typing import Callable, Any
from fitodomik_app.core.models import SensorSnapshot
from fitodomik_app.core.settings import SettingsManager
from fitodomik_app.services.notification_center import NotificationCenter
OPERATORS: dict[str, Callable[[float, float], bool]] = {
    "<": operator.lt,
    "<=": operator.le,
    ">": operator.gt,
    ">=": operator.ge,
    "==": operator.eq,
    "!=": operator.ne,
}
@dataclass
class AlertState:
    started_at: datetime | None = None
    last_sent_at: datetime | None = None
    active: bool = False
class AlertRulesEngine:
    def __init__(
        self,
        settings: SettingsManager,
        notifications: NotificationCenter,
        relay_callback: Callable[[str], None],
    ) -> None:
        self.settings = settings
        self.notifications = notifications
        self.relay_callback = relay_callback
        self.states: dict[str, AlertState] = {}
    def check(self, snapshot: SensorSnapshot) -> None:
        if not snapshot.has_sensor_values():
            return
        for rule in self._enabled_rules():
            self._check_rule(rule, snapshot)
    def _enabled_rules(self) -> list[dict[str, Any]]:
        rules = self.settings.get("alert_rules", [])
        if not isinstance(rules, list):
            return []
        return [rule for rule in rules if isinstance(rule, dict) and rule.get("enabled", True)]
    def _check_rule(self, rule: dict[str, Any], snapshot: SensorSnapshot) -> None:
        rule_id = str(rule.get("id") or rule.get("name") or "rule")
        state = self.states.setdefault(rule_id, AlertState())
        if not self._in_time_window(rule):
            self._reset_if_active(rule, state, "вышло из временного окна")
            return
        value = self._sensor_value(snapshot, str(rule.get("sensor", "")))
        if value is None:
            return
        if self._violated(value, rule):
            self._handle_violation(rule, value, state)
            return
        self._reset_if_active(rule, state, f"значение вернулось в норму: {value:g}")
    def _handle_violation(self, rule: dict[str, Any], value: float, state: AlertState) -> None:
        now = datetime.now()
        if state.started_at is None:
            state.started_at = now
        duration_minutes = max(0, int(rule.get("duration_minutes", 0) or 0))
        elapsed = (now - state.started_at).total_seconds() / 60
        if elapsed >= duration_minutes and self._cooldown_passed(state, rule):
            self._fire(rule, value, elapsed, state)
    def _fire(self, rule: dict[str, Any], value: float, elapsed_minutes: float, state: AlertState) -> None:
        state.active = True
        state.last_sent_at = datetime.now()
        title = str(rule.get("name", "Тревога"))
        sensor = str(rule.get("sensor", ""))
        op = str(rule.get("operator", ">"))
        target = float(rule.get("value", 0))
        message = (
            f"{title}: {sensor} {value:g}, условие {op} {target:g}, "
            f"длительность {int(elapsed_minutes)} мин."
        )
        actions = list(rule.get("actions", []))
        for action in actions:
            if action.startswith("relay_"):
                self.relay_callback(action)
        self.notifications.add(
            title,
            message,
            event_type="alert",
            level="warning",
        )
    def _reset_if_active(self, rule: dict[str, Any], state: AlertState, reason: str) -> None:
        if state.active or state.started_at is not None:
            title = str(rule.get("name", "Тревога"))
            self.notifications.add(
                f"{title}: норма восстановлена",
                reason,
                event_type="recovery",
                level="info",
            )
        state.started_at = None
        state.active = False
    def _sensor_value(self, snapshot: SensorSnapshot, sensor: str) -> float | None:
        aliases = {
            "light": "light_level",
            "air_humidity": "humidity",
        }
        attr = aliases.get(sensor, sensor)
        value = getattr(snapshot, attr, None)
        return float(value) if value is not None else None
    def _violated(self, value: float, rule: dict[str, Any]) -> bool:
        op = OPERATORS.get(str(rule.get("operator", ">")))
        if not op:
            return False
        return bool(op(value, float(rule.get("value", 0))))
    def _cooldown_passed(self, state: AlertState, rule: dict[str, Any]) -> bool:
        if state.last_sent_at is None:
            return True
        cooldown = int(rule.get("cooldown_minutes") or self.settings.get("alert_repeat_cooldown_minutes", 180))
        return (datetime.now() - state.last_sent_at).total_seconds() >= cooldown * 60
    def _in_time_window(self, rule: dict[str, Any]) -> bool:
        start = rule.get("time_from")
        end = rule.get("time_to")
        if not start or not end:
            return True
        now = datetime.now().time()
        start_time = self._parse_time(str(start), time(0, 0))
        end_time = self._parse_time(str(end), time(23, 59))
        if start_time <= end_time:
            return start_time <= now <= end_time
        return now >= start_time or now <= end_time
    def _parse_time(self, value: str, default: time) -> time:
        try:
            hour, minute = value.split(":")[:2]
            return time(int(hour), int(minute))
        except Exception:
            return default