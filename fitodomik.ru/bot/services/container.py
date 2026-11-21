from __future__ import annotations
from dataclasses import dataclass
from bot.config import Settings
from bot.database import Database
from bot.repositories import (
    AlertsRepository,
    CommandsRepository,
    DevicesRepository,
    PhotosRepository,
    SensorDataRepository,
    TelegramRepository,
    UsersRepository,
)
from bot.services.alert_service import AlertService
from bot.services.command_service import CommandService
from bot.services.device_service import DeviceService
from bot.services.photo_service import PhotoService
from bot.services.report_service import ReportService
from bot.services.telegram_links import TelegramLinksService
@dataclass
class Services:
    settings: Settings
    database: Database
    users: UsersRepository
    devices: DevicesRepository
    telegram: TelegramRepository
    sensors: SensorDataRepository
    photos: PhotosRepository
    commands: CommandsRepository
    alerts: AlertsRepository
    links: TelegramLinksService
    device: DeviceService
    command: CommandService
    photo: PhotoService
    report: ReportService
    alert: AlertService
def build_services(settings: Settings, database: Database) -> Services:
    users = UsersRepository(database)
    devices = DevicesRepository(database)
    telegram = TelegramRepository(database)
    sensors = SensorDataRepository(database)
    photos = PhotosRepository(database)
    commands = CommandsRepository(database)
    alerts = AlertsRepository(database)
    photo_service = PhotoService(settings, photos)
    return Services(
        settings=settings,
        database=database,
        users=users,
        devices=devices,
        telegram=telegram,
        sensors=sensors,
        photos=photos,
        commands=commands,
        alerts=alerts,
        links=TelegramLinksService(database, telegram),
        device=DeviceService(users, devices, telegram),
        command=CommandService(database, commands, telegram),
        photo=photo_service,
        report=ReportService(sensors, photos, alerts),
        alert=AlertService(users, devices, telegram, sensors, alerts, photo_service),
    )