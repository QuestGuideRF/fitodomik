from __future__ import annotations
from datetime import date
from typing import Any
from bot.database import Database
DEFAULT_THRESHOLDS = (
    ("temperature", 15.0, 30.0, 25.0, 1.0),
    ("humidity_air", 40.0, 70.0, 60.0, 5.0),
    ("humidity_soil", 30.0, 70.0, 50.0, 5.0),
    ("co2", 600.0, 1500.0, 1000.0, 100.0),
)
class SensorDataRepository:
    def __init__(self, database: Database) -> None:
        self.database = database
    async def latest(self, user_id: int, device_id: str) -> dict[str, Any] | None:
        return await self.database.fetch_one(
            "SELECT * FROM sensor_data WHERE user_id=%s AND device_id=%s "
            "ORDER BY created_at DESC LIMIT 1",
            (user_id, device_id),
        )
    async def dynamic(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT d.sensor_uid, d.name, d.sensor_type, d.unit,
                   v.value, v.raw_value, v.measured_at
            FROM device_sensor_definitions d
            LEFT JOIN device_sensor_values v ON v.id=(
                SELECT v2.id FROM device_sensor_values v2
                WHERE v2.user_id=d.user_id AND v2.device_id=d.device_id
                  AND v2.sensor_uid=d.sensor_uid
                ORDER BY v2.measured_at DESC, v2.id DESC LIMIT 1
            )
            WHERE d.user_id=%s AND d.device_id=%s AND d.enabled=1
              AND d.show_in_monitoring=1
            ORDER BY d.id ASC LIMIT 128
            """,
            (user_id, device_id),
        )
    async def relays(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        return await self.database.fetch_all(
            """
            SELECT relay_uid,name,config_json
            FROM device_relay_definitions
            WHERE user_id=%s AND device_id=%s AND enabled=1 AND show_in_control=1
            ORDER BY id ASC LIMIT 32
            """,
            (user_id, device_id),
        )
    async def ensure_thresholds(self, user_id: int, device_id: str) -> None:
        await self.database.executemany(
            """
            INSERT INTO alarm_thresholds
                (user_id,device_id,parameter_type,min_limit,max_limit,target_value,tolerance)
            VALUES (%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)
            """,
            ((user_id, device_id, *values) for values in DEFAULT_THRESHOLDS),
        )
    async def thresholds(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        await self.ensure_thresholds(user_id, device_id)
        return await self.database.fetch_all(
            """
            SELECT parameter_type,min_limit,max_limit,target_value,tolerance
            FROM alarm_thresholds WHERE user_id=%s AND device_id=%s
            ORDER BY FIELD(parameter_type,'temperature','humidity_air','humidity_soil','co2')
            """,
            (user_id, device_id),
        )
    async def set_threshold(
        self, user_id: int, device_id: str, parameter: str, minimum: float, maximum: float
    ) -> None:
        target = (minimum + maximum) / 2
        await self.database.execute(
            """
            INSERT INTO alarm_thresholds
                (user_id,device_id,parameter_type,min_limit,max_limit,target_value)
            VALUES (%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE min_limit=VALUES(min_limit),
                max_limit=VALUES(max_limit),target_value=VALUES(target_value)
            """,
            (user_id, device_id, parameter, minimum, maximum, target),
        )
    async def ensure_schedule(self, user_id: int, device_id: str) -> None:
        count = int(
            await self.database.fetch_value(
                "SELECT COUNT(*) FROM schedule WHERE user_id=%s AND device_id=%s", (user_id, device_id)
            )
            or 0
        )
        if count:
            return
        await self.database.executemany(
            "INSERT INTO schedule (user_id,device_id,curtains_schedule,lighting_schedule,time) "
            "VALUES (%s,%s,0,0,%s) ON DUPLICATE KEY UPDATE id=id",
            ((user_id, device_id, f"{hour:02d}:00") for hour in range(24)),
        )
    async def schedule(self, user_id: int, device_id: str) -> list[dict[str, Any]]:
        await self.ensure_schedule(user_id, device_id)
        return await self.database.fetch_all(
            "SELECT time,curtains_schedule,lighting_schedule FROM schedule "
            "WHERE user_id=%s AND device_id=%s ORDER BY time ASC",
            (user_id, device_id),
        )
    async def set_schedule(
        self,
        user_id: int,
        device_id: str,
        schedule_type: str,
        start_hour: int,
        end_hour: int,
        enabled: bool,
    ) -> None:
        await self.ensure_schedule(user_id, device_id)
        for hour in range(start_hour, end_hour):
            params = (1 if enabled else 0, user_id, device_id, f"{hour:02d}:00")
            if schedule_type == "lighting":
                await self.database.execute(
                    "UPDATE schedule SET lighting_schedule=%s "
                    "WHERE user_id=%s AND device_id=%s AND time=%s",
                    params,
                )
            else:
                await self.database.execute(
                    "UPDATE schedule SET curtains_schedule=%s "
                    "WHERE user_id=%s AND device_id=%s AND time=%s",
                    params,
                )
    async def daily_summary(
        self, user_id: int, device_id: str, day: date
    ) -> dict[str, Any]:
        row = await self.database.fetch_one(
            """
            SELECT COUNT(*) records_count,
                   AVG(temperature) avg_temp, MIN(temperature) min_temp, MAX(temperature) max_temp,
                   AVG(humidity) avg_humidity, AVG(soil_moisture) avg_soil,
                   AVG(co2) avg_co2, SUM(CASE WHEN lamp_state=1 THEN 1 ELSE 0 END) lamp_on,
                   SUM(CASE WHEN curtains_state=1 THEN 1 ELSE 0 END) curtains_on
            FROM sensor_data WHERE user_id=%s AND device_id=%s AND DATE(created_at)=%s
            """,
            (user_id, device_id, day.isoformat()),
        )
        return row or {}
