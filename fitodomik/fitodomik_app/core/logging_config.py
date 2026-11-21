from __future__ import annotations
import logging
from logging .handlers import RotatingFileHandler
from .paths import LOGS_DIR ,ensure_runtime_directories
def configure_logging ()->logging .Logger :
    ensure_runtime_directories ()
    logger =logging .getLogger ("fitodomik")
    logger .setLevel (logging .INFO )
    logger .propagate =False
    if logger .handlers :
        return logger
    formatter =logging .Formatter (
    "%(asctime)s | %(levelname)s | %(name)s | %(threadName)s | %(message)s"
    )
    file_handler =RotatingFileHandler (
    LOGS_DIR /"fitodomik.log",
    maxBytes =1_000_000 ,
    backupCount =5 ,
    encoding ="utf-8",
    )
    file_handler .setFormatter (formatter )
    file_handler .setLevel (logging .INFO )
    console_handler =logging .StreamHandler ()
    console_handler .setFormatter (formatter )
    console_handler .setLevel (logging .WARNING )
    logger .addHandler (file_handler )
    logger .addHandler (console_handler )
    return logger