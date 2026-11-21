from aiogram.fsm.state import State, StatesGroup
class RenameForm(StatesGroup):
    name = State()
class ThresholdForm(StatesGroup):
    limits = State()
class ScheduleForm(StatesGroup):
    interval = State()