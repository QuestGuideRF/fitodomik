from aiogram import Dispatcher, F
from bot.handlers import (
    account,
    analysis,
    control,
    devices,
    diagnostics,
    events,
    fallback,
    photos,
    schedule,
    settings,
    start,
    status,
    thresholds,
)
def register_routers(dispatcher: Dispatcher) -> None:
    routers = (
        start.router,
        account.router,
        devices.router,
        status.router,
        photos.router,
        analysis.router,
        control.router,
        thresholds.router,
        schedule.router,
        events.router,
        diagnostics.router,
        settings.router,
        fallback.router,
    )
    for router in routers:
        router.message.filter(F.chat.type == "private")
        router.callback_query.filter(F.message.chat.type == "private")
    dispatcher.include_routers(*routers)