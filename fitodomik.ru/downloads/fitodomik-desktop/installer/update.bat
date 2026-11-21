@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
for %%I in ("%SCRIPT_DIR%..") do set "PROJECT_DIR=%%~fI"
if not exist "%PROJECT_DIR%\main.py" set "PROJECT_DIR=%SCRIPT_DIR%"

cd /d "%PROJECT_DIR%"

if exist ".git" (
  git pull
) else (
  echo Git repository not found, skipping git pull.
)

if not exist ".venv\Scripts\python.exe" (
  python -m venv .venv
)

".venv\Scripts\python.exe" -m pip install --upgrade pip setuptools wheel
if exist requirements.txt (
  powershell -NoProfile -Command "Get-Content requirements.txt | Where-Object { $_ -notmatch '^\s*arduino-cli([<=>~! ].*)?$' } | Set-Content .fitodomik_requirements_install.txt -Encoding UTF8"
  ".venv\Scripts\python.exe" -m pip install -r .fitodomik_requirements_install.txt
) else (
  ".venv\Scripts\python.exe" -m pip install pyserial Pillow matplotlib numpy requests opencv-python urllib3 "qrcode[pil]"
)

where arduino-cli >nul 2>nul
if errorlevel 1 (
  echo WARNING: arduino-cli^>=1.4.1 not found in PATH. Install Arduino CLI separately for firmware compile/upload.
)

".venv\Scripts\python.exe" -m compileall -q main.py fitodomik_app
echo Update completed.
