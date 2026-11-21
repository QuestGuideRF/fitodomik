from __future__ import annotations
import logging
import threading
import time
from collections .abc import Callable
from typing import Any
from .event_bus import GuiEventBus
from .performance import log_perf
IntervalGetter =Callable [[],float ]
TaskCallable =Callable [[],Any ]
class ManagedTask :
    def __init__ (
    self ,
    name :str ,
    callback :TaskCallable ,
    interval_getter :IntervalGetter ,
    event_bus :GuiEventBus ,
    logger :logging .Logger ,
    run_immediately :bool =False ,
    )->None :
        self .name =name
        self .callback =callback
        self .interval_getter =interval_getter
        self .event_bus =event_bus
        self .logger =logger .getChild (f"task.{name }")
        self .run_immediately =run_immediately
        self ._stop_event =threading .Event ()
        self ._wake_event =threading .Event ()
        self ._thread =threading .Thread (target =self ._loop ,name =f"task:{name }",daemon =False )
    def start (self )->None :
        self ._thread .start ()
    def stop (self )->None :
        self ._stop_event .set ()
        self ._wake_event .set ()
    def wake (self )->None :
        self ._wake_event .set ()
    def join (self ,timeout :float =1.0 )->None :
        if self ._thread .is_alive ():
            self ._thread .join (timeout =timeout )
    def _loop (self )->None :
        if not self .run_immediately :
            self ._sleep ()
        while not self ._stop_event .is_set ():
            started =time .perf_counter ()
            try :
                self .callback ()
            except Exception as exc :
                self .logger .exception ("Задача не выполнена")
                self .event_bus .post_error (f"Ошибка фоновой задачи {self .name }: {exc }")
            finally :
                elapsed =time .perf_counter ()-started
                if elapsed >=0.2 :
                    log_perf (f"task {self .name }",elapsed )
            self ._sleep ()
    def _sleep (self )->None :
        try :
            interval =max (1.0 ,float (self .interval_getter ()))
        except Exception :
            interval =30.0
        self ._wake_event .wait (interval )
        self ._wake_event .clear ()
class TaskManager :
    def __init__ (self ,event_bus :GuiEventBus ,logger :logging .Logger )->None :
        self .event_bus =event_bus
        self .logger =logger .getChild ("tasks")
        self ._tasks :dict [str ,ManagedTask ]={}
        self ._once_running :set [str ]=set ()
        self ._once_threads :dict [str ,threading .Thread ]={}
        self ._lock =threading .RLock ()
        self ._stopping =False
    def start_periodic (
    self ,
    name :str ,
    callback :TaskCallable ,
    interval_getter :IntervalGetter ,
    run_immediately :bool =False ,
    )->None :
        with self ._lock :
            if self ._stopping :
                return
            if name in self ._tasks :
                self ._tasks [name ].wake ()
                return
            task =ManagedTask (
            name =name ,
            callback =callback ,
            interval_getter =interval_getter ,
            event_bus =self .event_bus ,
            logger =self .logger ,
            run_immediately =run_immediately ,
            )
            self ._tasks [name ]=task
            task .start ()
    def wake (self ,name :str )->None :
        with self ._lock :
            if name in self ._tasks :
                self ._tasks [name ].wake ()
    def request_stop_all (self )->None :
        with self ._lock :
            self ._stopping =True
            tasks =list (self ._tasks .values ())
        for task in tasks :
            task .stop ()
    def run_once (self ,name :str ,callback :TaskCallable )->None :
        def runner ()->None :
            started =time .perf_counter ()
            try :
                callback ()
            except Exception as exc :
                self .logger .exception ("Не удалось выполнить одноразовую задачу: %s",name )
                self .event_bus .post_error (f"Ошибка задачи {name }: {exc }")
            finally :
                elapsed =time .perf_counter ()-started
                log_perf (f"job {name }",elapsed )
                with self ._lock :
                    self ._once_running .discard (name )
                    self ._once_threads .pop (name ,None )
        with self ._lock :
            if self ._stopping :
                return
            if name in self ._once_running :
                self .logger .info ("Одноразовая задача уже выполняется: %s",name )
                return
            self ._once_running .add (name )
            thread =threading .Thread (target =runner ,name =f"job:{name }",daemon =False )
            self ._once_threads [name ]=thread
            thread .start ()
    def stop_all (self )->None :
        with self ._lock :
            self ._stopping =True
            tasks =list (self ._tasks .values ())
            self ._tasks .clear ()
            workers =list (self ._once_threads .values ())
        for task in tasks :
            task .stop ()
        for task in tasks :
            task ._thread .join ()
        for worker in workers :
            if worker is not threading .current_thread ():
                worker .join ()
