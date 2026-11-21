#!/usr/bin/env bash
set -euo pipefail
PROJECT_REPO_URL="ВСТАВИТЬ_ССЫЛКУ_НА_GITHUB_РЕПОЗИТОРИЙ"
INSTALL_DIR="${FITODOMIK_INSTALL_DIR:-${HOME}/FitoDomik}"
LOG_FILE="${INSTALL_DIR}/install.log"
NEED_REBOOT=0
ARDUINO_PORT=""
CAMERA_STATUS="unknown"
CV2_STATUS="unknown"
PYTHON_STATUS="unknown"
ARDUINO_CLI_STATUS="unknown"
AUTOSTART_STATUS="unknown"
mkdir -p "$INSTALL_DIR"
touch "$LOG_FILE"
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}
warn() {
  log "WARN: $*"
}
die() {
  log "ERROR: $*"
  exit 1
}
ask_yes_no() {
  prompt="$1"
  default="${2:-N}"
  read -r -p "$prompt " answer
  answer="${answer:-$default}"
  case "$answer" in
    y|Y|yes|YES|д|Д|да|ДА) return 0 ;;
    *) return 1 ;;
  esac
}
is_placeholder_repo_url() {
  [ "$PROJECT_REPO_URL" = "ВСТАВИТЬ_ССЫЛКУ_НА_GITHUB_РЕПОЗИТОРИЙ" ] || [ -z "$PROJECT_REPO_URL" ]
}
is_raspberry_pi() {
  [ -f /proc/device-tree/model ] && tr -d '\0' < /proc/device-tree/model | grep -qi "raspberry pi"
}
is_32bit_arm() {
  case "$(uname -m)" in
    armv6l|armv7l) return 0 ;;
    *) return 1 ;;
  esac
}
is_rpi_32bit() {
  is_raspberry_pi && is_32bit_arm
}
backup_path() {
  path="$1"
  [ -e "$path" ] || return 0
  backup="${path}.backup.$(date '+%Y%m%d_%H%M%S')"
  cp -a "$path" "$backup"
  log "Backup: $path -> $backup"
}
detect_source_dir() {
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  if [ -f "$script_dir/main.py" ] && [ -d "$script_dir/fitodomik_app" ]; then
    echo "$script_dir"
  elif [ -f "$script_dir/../main.py" ] && [ -d "$script_dir/../fitodomik_app" ]; then
    (cd "$script_dir/.." && pwd)
  else
    echo ""
  fi
}
check_system() {
  log "Проверка системы"
  if is_raspberry_pi; then
    model="$(tr -d '\0' < /proc/device-tree/model)"
    log "Raspberry Pi: $model"
  else
    warn "Это не похоже на Raspberry Pi. Установка продолжится, но часть проверок может быть неприменима."
  fi
  log "Архитектура: $(uname -m)"
  if [ -f /etc/os-release ]; then
    . /etc/os-release
    log "ОС: ${PRETTY_NAME:-unknown}"
  fi
}
install_system_dependencies() {
  log "Установка системных зависимостей"
  sudo apt update 2>&1 | tee -a "$LOG_FILE"
  sudo apt install -y \
    git curl wget unzip \
    python3 python3-pip python3-venv python3-tk \
    python3-opencv python3-numpy \
    libatlas-base-dev libopenblas-dev \
    libjpeg-dev zlib1g-dev \
    v4l-utils \
    avrdude \
    build-essential \
    ca-certificates 2>&1 | tee -a "$LOG_FILE"
  if sudo apt install -y arduino-cli 2>&1 | tee -a "$LOG_FILE"; then
    ARDUINO_CLI_STATUS="installed"
  else
    ARDUINO_CLI_STATUS="not available from apt"
    warn "arduino-cli недоступен через apt. Desktop-приложение будет установлено, прошивку Arduino можно выполнить вручную."
  fi
}
prepare_project_dir() {
  log "Подготовка папки установки: $INSTALL_DIR"
  source_dir="$(detect_source_dir)"
  existing_entry="$(find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 ! -name install.log 2>/dev/null | head -n 1 || true)"
  if [ -n "$existing_entry" ] && [ ! -f "$INSTALL_DIR/main.py" ] && [ ! -d "$INSTALL_DIR/.git" ]; then
    echo
    echo "Папка $INSTALL_DIR уже не пустая, но это не похоже на установленный проект ФитоДомик."
    echo "1. Сделать backup папки и установить заново"
    echo "2. Отменить установку"
    read -r -p "Выберите действие [1/2]: " non_project_choice
    case "$non_project_choice" in
      1)
        backup="${INSTALL_DIR}.backup.$(date '+%Y%m%d_%H%M%S')"
        mv "$INSTALL_DIR" "$backup"
        mkdir -p "$INSTALL_DIR"
        touch "$LOG_FILE"
        log "Непроектная папка сохранена: $backup"
        ;;
      *)
        die "Установка отменена пользователем."
        ;;
    esac
  fi
  if [ -f "$INSTALL_DIR/main.py" ] || [ -d "$INSTALL_DIR/.git" ]; then
    echo
    echo "Папка $INSTALL_DIR уже содержит проект."
    echo "1. Обновить через git pull"
    echo "2. Сделать backup папки и установить заново"
    echo "3. Отменить установку"
    read -r -p "Выберите действие [1/2/3]: " choice
    case "$choice" in
      1)
        if [ -d "$INSTALL_DIR/.git" ]; then
          (cd "$INSTALL_DIR" && git pull 2>&1 | tee -a "$LOG_FILE") || warn "git pull завершился с ошибкой."
        else
          warn "В $INSTALL_DIR нет .git, обновление через git pull невозможно."
        fi
        return
        ;;
      2)
        backup="${INSTALL_DIR}.backup.$(date '+%Y%m%d_%H%M%S')"
        mv "$INSTALL_DIR" "$backup"
        mkdir -p "$INSTALL_DIR"
        touch "$LOG_FILE"
        log "Старая папка сохранена: $backup"
        ;;
      *)
        die "Установка отменена пользователем."
        ;;
    esac
  fi
  if ! is_placeholder_repo_url; then
    rm -rf "${INSTALL_DIR}.clone_tmp"
    git clone "$PROJECT_REPO_URL" "${INSTALL_DIR}.clone_tmp" 2>&1 | tee -a "$LOG_FILE"
    shopt -s dotglob
    cp -a "${INSTALL_DIR}.clone_tmp/"* "$INSTALL_DIR/"
    shopt -u dotglob
    rm -rf "${INSTALL_DIR}.clone_tmp"
  elif [ -n "$source_dir" ] && [ "$source_dir" != "$INSTALL_DIR" ]; then
    log "GitHub URL пока не задан, копирую локальную папку проекта: $source_dir"
    shopt -s dotglob
    cp -a "$source_dir/"* "$INSTALL_DIR/"
    shopt -u dotglob
  elif [ -f "$INSTALL_DIR/main.py" ]; then
    log "Используется существующая папка проекта."
  else
    die "PROJECT_REPO_URL не задан. Замените placeholder на GitHub URL или запустите install.sh из локальной папки проекта."
  fi
}
setup_python_environment() {
  log "Настройка Python-окружения"
  cd "$INSTALL_DIR"
  python3 -m venv .venv --system-site-packages 2>&1 | tee -a "$LOG_FILE"
  source .venv/bin/activate
  python -m pip install --upgrade pip setuptools wheel 2>&1 | tee -a "$LOG_FILE"
  if [ -f requirements.txt ]; then
    tmp_req="/tmp/fitodomik_requirements.txt"
    if is_rpi_32bit; then
      log "Raspberry Pi OS 32-bit: исключаю opencv-python и arduino-cli из pip requirements."
      grep -v -i -E '^[[:space:]]*(opencv-python|arduino-cli)([<=>~! ].*)?$' requirements.txt > "$tmp_req"
    else
      grep -v -i -E '^[[:space:]]*(opencv-python|arduino-cli)([<=>~! ].*)?$' requirements.txt > "$tmp_req"
      warn "opencv-python исключен из pip-установки; используется системный python3-opencv. arduino-cli проверяется отдельно."
    fi
    python -m pip install -r "$tmp_req" 2>&1 | tee -a "$LOG_FILE"
  else
    python -m pip install pyserial Pillow matplotlib requests urllib3 "qrcode[pil]" 2>&1 | tee -a "$LOG_FILE"
  fi
  if python -B -c "import tkinter, serial, PIL, matplotlib, requests, qrcode" >/dev/null 2>&1; then
    PYTHON_STATUS="ok"
  else
    PYTHON_STATUS="import errors"
    warn "Не все Python-библиотеки импортируются. Запустите ./diagnose.sh для деталей."
  fi
  if python -B -c "import cv2; print(cv2.__version__)" 2>&1 | tee -a "$LOG_FILE"; then
    CV2_STATUS="ok"
  else
    CV2_STATUS="failed"
    warn "cv2 не импортируется. На Raspberry Pi OS 32-bit проверьте пакет python3-opencv."
  fi
}
setup_serial_access() {
  log "Настройка доступа к Serial"
  if id -nG "$USER" | grep -qw dialout; then
    log "Пользователь уже в группе dialout."
  else
    sudo usermod -aG dialout "$USER"
    NEED_REBOOT=1
    warn "Пользователь добавлен в dialout. Нужно перелогиниться или перезагрузить Raspberry Pi."
  fi
  if id -nG "$USER" | grep -qw tty; then
    log "Пользователь уже в группе tty."
  else
    sudo usermod -aG tty "$USER"
    NEED_REBOOT=1
    warn "Пользователь добавлен в tty. Нужно перелогиниться или перезагрузить Raspberry Pi."
  fi
}
find_serial_ports() {
  ls /dev/ttyUSB* /dev/ttyACM* 2>/dev/null || true
}
check_arduino_port() {
  log "Проверка Arduino-портов"
  ports="$(find_serial_ports)"
  if [ -z "$ports" ]; then
    warn "Arduino Serial-порты не найдены."
    return
  fi
  echo "Найдены порты:"
  echo "$ports"
  ARDUINO_PORT="$(echo "$ports" | head -n 1)"
  cd "$INSTALL_DIR"
  source .venv/bin/activate
  python -B - "$ARDUINO_PORT" <<'PY' 2>&1 | tee -a "$LOG_FILE"
import sys
import serial
port = sys.argv[1]
try:
    ser = serial.Serial(port, 9600, timeout=2)
    ser.close()
    print(f"Serial open OK: {port}")
except Exception as exc:
    print(f"Serial open failed: {port}: {exc}")
PY
}
setup_arduino_cli() {
  if ! command -v arduino-cli >/dev/null 2>&1; then
    warn "arduino-cli не найден, подготовка Arduino CLI пропущена."
    return
  fi
  ARDUINO_CLI_STATUS="ok"
  log "Настройка Arduino CLI"
  arduino-cli config init --overwrite 2>&1 | tee -a "$LOG_FILE" || true
  arduino-cli core update-index 2>&1 | tee -a "$LOG_FILE" || warn "arduino-cli core update-index завершился с ошибкой."
  arduino-cli core install arduino:avr 2>&1 | tee -a "$LOG_FILE" || warn "Не удалось установить arduino:avr."
  if [ -d "$INSTALL_DIR/libraries" ]; then
    mkdir -p "$HOME/Arduino/libraries"
    for lib in "$INSTALL_DIR"/libraries/*; do
      [ -e "$lib" ] || continue
      target="$HOME/Arduino/libraries/$(basename "$lib")"
      if [ -e "$target" ]; then
        backup_path "$target"
      fi
      cp -a "$lib" "$HOME/Arduino/libraries/"
    done
    log "Локальные Arduino-библиотеки подготовлены в ~/Arduino/libraries."
  else
    warn "Папка libraries не найдена."
  fi
  if [ ! -f "$INSTALL_DIR/fitodomik.ino" ]; then
    warn "fitodomik.ino не найден, прошивка невозможна."
    return
  fi
  if ! ask_yes_no "Прошить Arduino сейчас? [y/N]" "N"; then
    log "Прошивка Arduino пропущена пользователем."
    return
  fi
  echo "Выберите плату:"
  echo "1. Arduino Nano"
  echo "2. Arduino Nano old bootloader"
  echo "3. Arduino Uno"
  echo "4. Ввести FQBN вручную"
  read -r -p "Плата [1/2/3/4]: " board_choice
  case "$board_choice" in
    1) fqbn="arduino:avr:nano" ;;
    2) fqbn="arduino:avr:nano:cpu=atmega328old" ;;
    3) fqbn="arduino:avr:uno" ;;
    4) read -r -p "Введите FQBN: " fqbn ;;
    *) warn "Плата не выбрана, прошивка пропущена."; return ;;
  esac
  ports="$(find_serial_ports)"
  if [ -z "$ports" ]; then
    warn "Порт Arduino не найден, прошивка пропущена."
    return
  fi
  echo "Доступные порты:"
  echo "$ports"
  read -r -p "Порт Arduino [$(echo "$ports" | head -n 1)]: " flash_port
  flash_port="${flash_port:-$(echo "$ports" | head -n 1)}"
  arduino-cli compile --fqbn "$fqbn" "$INSTALL_DIR/fitodomik.ino" 2>&1 | tee -a "$LOG_FILE"
  arduino-cli upload -p "$flash_port" --fqbn "$fqbn" "$INSTALL_DIR/fitodomik.ino" 2>&1 | tee -a "$LOG_FILE"
}
check_camera() {
  log "Проверка USB-камеры"
  if command -v v4l2-ctl >/dev/null 2>&1; then
    v4l2-ctl --list-devices 2>&1 | tee -a "$LOG_FILE" || true
  fi
  ls /dev/video* 2>&1 | tee -a "$LOG_FILE" || warn "Устройства /dev/video* не найдены."
  cd "$INSTALL_DIR"
  source .venv/bin/activate
  if python -B - <<'PY' 2>&1 | tee -a "$LOG_FILE"
import cv2
cap = cv2.VideoCapture(0)
ok, frame = cap.read()
cap.release()
print(bool(ok))
raise SystemExit(0 if ok else 1)
PY
  then
    CAMERA_STATUS="ok"
  else
    CAMERA_STATUS="not detected"
    warn "Камера не найдена. Фото и анализ растения заработают после подключения камеры."
  fi
}
ensure_settings() {
  log "Проверка settings.json"
  cd "$INSTALL_DIR"
  if [ -f settings.json ]; then
    backup_path "settings.json"
  fi
  python3 -B - <<'PY'
import json
from pathlib import Path
path = Path("settings.json")
defaults = {
    "theme_mode": "auto",
    "dark_theme_time": "20:00",
    "light_theme_time": "08:00",
    "weather_city": "Moscow",
    "token": "",
    "device_id": "",
    "device_token": "",
    "device_name": "FitoDomik-RPi",
    "port": "",
    "auto_detect_port": True,
    "serial_baudrate": 9600,
    "serial_timeout": 2,
    "camera_index": 0,
    "fullscreen": False,
    "kiosk_mode": False,
    "interface_scale": "auto",
    "command_poll_interval_seconds": 10,
    "failed_upload_retry_interval_seconds": 60,
    "arduino_auto_reconnect": True,
    "analysis_output_dir": "analysis_results",
    "analysis_upload_enabled": True,
}
if path.exists():
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        data = {}
else:
    data = {}
changed = False
for key, value in defaults.items():
    if key not in data:
        data[key] = value
        changed = True
if changed or not path.exists():
    path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    print("settings.json обновлен без удаления существующих полей")
else:
    print("settings.json уже содержит минимальные поля")
PY
}
setup_autostart() {
  log "Настройка автозапуска и ярлыка"
  mkdir -p "$HOME/.config/autostart"
  desktop_file="$HOME/.config/autostart/fitodomik.desktop"
  cat > "$desktop_file" <<EOF
[Desktop Entry]
Type=Application
Name=FitoDomik
Comment=Автозапуск ФитоДомик
Exec=${INSTALL_DIR}/.venv/bin/python ${INSTALL_DIR}/main.py
WorkingDirectory=${INSTALL_DIR}
Icon=applications-python
Terminal=false
Hidden=false
X-GNOME-Autostart-enabled=true
StartupNotify=false
EOF
  chmod +x "$desktop_file"
  AUTOSTART_STATUS="enabled"
  if [ -d "$HOME/Desktop" ]; then
    cp "$desktop_file" "$HOME/Desktop/fitodomik.desktop"
    chmod +x "$HOME/Desktop/fitodomik.desktop"
    log "Ярлык создан: $HOME/Desktop/fitodomik.desktop"
  fi
}
verify_project_start() {
  log "Проверка компиляции проекта"
  cd "$INSTALL_DIR"
  source .venv/bin/activate
  python -m compileall -q main.py fitodomik_app 2>&1 | tee -a "$LOG_FILE"
  python -B -c "import main; import fitodomik_app.ui.app" 2>&1 | tee -a "$LOG_FILE" || warn "Импорт main/ui завершился с ошибкой. Проверьте diagnose.sh."
}
print_report() {
  echo
  echo "================ Итоговый отчет ФитоДомик ================"
  echo "Путь установки: $INSTALL_DIR"
  echo "Команда запуска: cd $INSTALL_DIR && ./run.sh"
  echo "Python: $PYTHON_STATUS"
  echo "OpenCV/cv2: $CV2_STATUS"
  echo "Камера: $CAMERA_STATUS"
  echo "Arduino-порт: ${ARDUINO_PORT:-не найден}"
  echo "Arduino CLI: $ARDUINO_CLI_STATUS"
  echo "Автозапуск: $AUTOSTART_STATUS"
  echo "Нужно перезагрузиться: $([ "$NEED_REBOOT" -eq 1 ] && echo "да" || echo "нет")"
  echo
  echo "Что делать дальше:"
  if [ "$NEED_REBOOT" -eq 1 ]; then
    echo "1. Перезагрузить Raspberry Pi или перелогиниться для групп dialout/tty."
  else
    echo "1. Перезагрузка для Serial-групп не требуется."
  fi
  echo "2. Запустить ФитоДомик: cd $INSTALL_DIR && ./run.sh"
  echo "3. Открыть настройки приложения."
  echo "4. Привязать устройство через QR."
  echo "5. Проверить Arduino и выбранный порт."
  echo "6. Проверить USB-камеру."
  echo "Лог установки: $LOG_FILE"
  echo "==========================================================="
}
main() {
  check_system
  install_system_dependencies
  if [ "${FITODOMIK_SKIP_PROJECT_PREPARE:-0}" = "1" ]; then
    log "Подготовка папки проекта пропущена: проект уже скачан bootstrap-установщиком."
  else
    prepare_project_dir
  fi
  cd "$INSTALL_DIR"
  chmod +x install.sh run.sh update.sh diagnose.sh uninstall.sh installer/*.sh 2>/dev/null || true
  setup_python_environment
  setup_serial_access
  check_arduino_port
  setup_arduino_cli
  check_camera
  ensure_settings
  setup_autostart
  verify_project_start
  print_report
  if ask_yes_no "Запустить ФитоДомик сейчас? [y/N]" "N"; then
    cd "$INSTALL_DIR"
    ./run.sh
  fi
}
main "$@"