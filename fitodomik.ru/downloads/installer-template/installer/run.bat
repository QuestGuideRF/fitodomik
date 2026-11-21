@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
for %%I in ("%SCRIPT_DIR%..") do set "PROJECT_DIR=%%~fI"
if not exist "%PROJECT_DIR%\main.py" set "PROJECT_DIR=%SCRIPT_DIR%"

cd /d "%PROJECT_DIR%"

if not exist ".venv\Scripts\python.exe" (
  echo Virtual environment .venv not found.
  echo Run: python installer\installer.py
  exit /b 1
)

".venv\Scripts\python.exe" main.py
