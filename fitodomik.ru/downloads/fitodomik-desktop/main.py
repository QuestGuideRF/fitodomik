from __future__ import annotations
import time
import tkinter as tk
from fitodomik_app .core .logging_config import configure_logging
from fitodomik_app .core .paths import ensure_runtime_directories
from fitodomik_app .core .performance import log_perf ,measure_perf
from fitodomik_app .core .version import APP_VERSION
from fitodomik_app .ui .app import FitoDomikApplication
_MAIN_IMPORT_STARTED =time .perf_counter ()
def main ()->None :
    ensure_runtime_directories ()
    log_perf ("main imported",time .perf_counter ()-_MAIN_IMPORT_STARTED )
    with measure_perf ("logging configured"):
        logger =configure_logging ()
    logger .info ("FitoDomik desktop version %s",APP_VERSION )
    with measure_perf ("tk root created"):
        root =tk .Tk ()
    with measure_perf ("ui object created"):
        FitoDomikApplication (root ,logger )
    root .mainloop ()
if __name__ =="__main__":
    main ()