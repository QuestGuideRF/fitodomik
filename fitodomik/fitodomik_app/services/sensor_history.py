from __future__ import annotations
import threading
from collections import deque
from datetime import datetime ,timedelta
from fitodomik_app .core .models import SensorSnapshot
class SensorHistory :
    def __init__ (self ,max_points :int =2000 )->None :
        self .max_points =max_points
        self ._items :deque [SensorSnapshot ]=deque (maxlen =max_points )
        self ._lock =threading .RLock ()
    def add (self ,snapshot :SensorSnapshot )->bool :
        if not snapshot .has_sensor_values ():
            return False
        with self ._lock :
            if self ._items and self ._same_sample (self ._items [-1 ],snapshot ):
                return False
            self ._items .append (snapshot )
            return True
    def _same_sample (self ,left :SensorSnapshot ,right :SensorSnapshot )->bool :
        return left .timestamp ==right .timestamp and left .source ==right .source and left .raw ==right .raw
    def latest (self )->SensorSnapshot |None :
        with self ._lock :
            return self ._items [-1 ]if self ._items else None
    def since (self ,hours :float )->list [SensorSnapshot ]:
        start =datetime .now ()-timedelta (hours =hours )
        with self ._lock :
            return [item for item in self ._items if item .timestamp >=start ]
    def all (self )->list [SensorSnapshot ]:
        with self ._lock :
            return list (self ._items )
    def clear (self )->None :
        with self ._lock :
            self ._items .clear ()
