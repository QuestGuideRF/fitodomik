#!/usr/bin/env bash
set +e
cd "$(dirname "$0")"
if [ -x ".venv/bin/python" ]; then
  PY=".venv/bin/python"
  PIP=".venv/bin/python -m pip"
else
  PY="python3"
  PIP="python3 -m pip"
fi
section() {
  echo
  echo "== $* =="
}
ok() {
  echo "[OK] $*"
}
warn() {
  echo "[WARN] $*"
}
fail() {
  echo "[FAIL] $*"
}
check_import() {
  module="$1"
  label="$2"
  "$PY" -B -c "import ${module}; print('import ${module}: OK')" >/tmp/fitodomik_import_check.txt 2>&1
  if [ $? -eq 0 ]; then
    ok "$label"
  else
    fail "$label"
    sed 's/^/  /' /tmp/fitodomik_import_check.txt
  fi
}
section "Система"
if [ -f /proc/device-tree/model ]; then
  tr -d '\0' < /proc/device-tree/model
  echo
else
  warn "Модель Raspberry Pi не найдена в /proc/device-tree/model"
fi
echo "Архитектура: $(uname -m)"
if [ -f /etc/os-release ]; then
  . /etc/os-release
  echo "ОС: ${PRETTY_NAME:-unknown}"
else
  warn "/etc/os-release не найден"
fi
section "Python"
if command -v python3 >/dev/null 2>&1; then
  python3 --version
else
  fail "python3 не найден"
fi
if $PIP --version >/tmp/fitodomik_pip_check.txt 2>&1; then
  cat /tmp/fitodomik_pip_check.txt
else
  fail "pip не найден"
fi
if [ -d ".venv" ]; then
  ok "venv найден: $(pwd)/.venv"
else
  warn "venv не найден"
fi
check_import "tkinter" "tkinter"
check_import "serial" "pyserial / serial"
check_import "PIL" "Pillow / PIL"
check_import "matplotlib" "matplotlib"
check_import "requests" "requests"
check_import "cv2" "OpenCV / cv2"
check_import "qrcode" "qrcode"
section "Serial и Arduino"
PORTS="$(ls /dev/ttyUSB* /dev/ttyACM* 2>/dev/null)"
if [ -n "$PORTS" ]; then
  echo "$PORTS"
else
  warn "Serial-порты /dev/ttyUSB* и /dev/ttyACM* не найдены"
fi
GROUPS="$(id -nG "$USER" 2>/dev/null)"
echo "Группы пользователя $USER: $GROUPS"
echo "$GROUPS" | grep -qw dialout && ok "Пользователь в группе dialout" || warn "Пользователь не в группе dialout"
echo "$GROUPS" | grep -qw tty && ok "Пользователь в группе tty" || warn "Пользователь не в группе tty"
if [ -f "fitodomik.ino" ]; then
  ok "fitodomik.ino найден"
else
  fail "fitodomik.ino не найден"
fi
if [ -d "libraries" ]; then
  ok "Папка libraries найдена"
  find libraries -maxdepth 2 -type d | sed 's/^/  /'
else
  warn "Папка libraries не найдена"
fi
if command -v arduino-cli >/dev/null 2>&1; then
  ok "arduino-cli: $(arduino-cli version 2>/dev/null | head -n 1)"
else
  warn "arduino-cli не установлен"
fi
section "Камера"
if command -v v4l2-ctl >/dev/null 2>&1; then
  v4l2-ctl --list-devices
else
  warn "v4l2-ctl не найден"
fi
VIDEO_DEVICES="$(ls /dev/video* 2>/dev/null)"
if [ -n "$VIDEO_DEVICES" ]; then
  echo "$VIDEO_DEVICES"
else
  warn "Устройства /dev/video* не найдены"
fi
"$PY" -B - <<'PY'
try:
    import cv2
    cap = cv2.VideoCapture(0)
    ok, frame = cap.read()
    cap.release()
    print("Python cv2 VideoCapture(0):", bool(ok))
except Exception as exc:
    print("Python cv2 camera test failed:", exc)
PY
section "Сайт"
if command -v curl >/dev/null 2>&1; then
  curl -I -L --max-time 10 https://fitodomik.ru >/tmp/fitodomik_site_check.txt 2>&1
  if [ $? -eq 0 ]; then
    ok "https://fitodomik.ru доступен"
  else
    warn "https://fitodomik.ru недоступен"
    sed 's/^/  /' /tmp/fitodomik_site_check.txt
  fi
else
  warn "curl не найден"
fi
section "settings.json"
if [ -f settings.json ]; then
  ok "settings.json найден"
  "$PY" -B - <<'PY'
import json
from pathlib import Path
path = Path("settings.json")
try:
    data = json.loads(path.read_text(encoding="utf-8"))
    for key in ("device_id", "device_token"):
        value = data.get(key, "")
        print(f"{key}: {'есть' if value else 'пусто'}")
except Exception as exc:
    print("settings.json не удалось прочитать:", exc)
PY
else
  warn "settings.json не найден"
fi
section "Логи"
if [ -f logs/fitodomik.log ]; then
  tail -n 40 logs/fitodomik.log
else
  warn "logs/fitodomik.log не найден"
fi