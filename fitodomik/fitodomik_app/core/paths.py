from __future__ import annotations
from pathlib import Path
BASE_DIR =Path (__file__ ).resolve ().parents [2 ]
SETTINGS_PATH =BASE_DIR /"settings.json"
ASSETS_DIR =BASE_DIR /"assets"
ICONS_DIR =ASSETS_DIR /"icons"
LOGS_DIR =BASE_DIR /"logs"
ANALYSIS_DIR =BASE_DIR /"analysis_results"
DATA_DIR =BASE_DIR /"data"
PHOTOS_DIR =BASE_DIR /"photos"
def ensure_runtime_directories ()->None :
    for path in (ASSETS_DIR ,ICONS_DIR ,LOGS_DIR ,ANALYSIS_DIR ,DATA_DIR ,PHOTOS_DIR ):
        path .mkdir (parents =True ,exist_ok =True )