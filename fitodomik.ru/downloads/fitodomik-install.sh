#!/usr/bin/env bash
set -euo pipefail
SITE_BASE_URL="${FITODOMIK_SITE_BASE_URL:-https://fitodomik.ru}"
PACKAGE_URL="${FITODOMIK_PACKAGE_URL:-${SITE_BASE_URL}/downloads/fitodomik-desktop.zip}"
INSTALL_DIR="${FITODOMIK_INSTALL_DIR:-${HOME}/FitoDomik}"
TMP_DIR="${TMPDIR:-/tmp}/fitodomik_bootstrap_$$"
LOG_FILE="${TMP_DIR}/bootstrap.log"
PACKAGE_PATH="${TMP_DIR}/fitodomik-desktop.zip"
SOURCE_DIR=""
mkdir -p "$TMP_DIR"
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
ask() {
  prompt="$1"
  default="${2:-}"
  answer=""
  if [ -r /dev/tty ]; then
    read -r -p "$prompt " answer </dev/tty || true
  fi
  echo "${answer:-$default}"
}
ask_yes_no() {
  prompt="$1"
  default="${2:-N}"
  answer="$(ask "$prompt" "$default")"
  case "$answer" in
    y|Y|yes|YES|д|Д|да|ДА) return 0 ;;
    *) return 1 ;;
  esac
}
cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT
need_command() {
  command -v "$1" >/dev/null 2>&1
}
install_download_tools_if_possible() {
  missing=0
  need_command curl || need_command wget || missing=1
  need_command unzip || missing=1
  if [ "$missing" -eq 0 ]; then
    return
  fi
  if need_command apt; then
    log "Устанавливаю минимальные инструменты: curl/wget/unzip"
    sudo apt update
    sudo apt install -y curl wget unzip ca-certificates
  else
    die "Нужны curl или wget и unzip. Установите их и повторите запуск."
  fi
}
download_package() {
  log "Скачиваю пакет: $PACKAGE_URL"
  if need_command curl; then
    curl -fL "$PACKAGE_URL" -o "$PACKAGE_PATH"
  elif need_command wget; then
    wget -O "$PACKAGE_PATH" "$PACKAGE_URL"
  else
    die "curl/wget не найден."
  fi
}
verify_package() {
  need_command sha256sum || {
    die "sha256sum не найден: установка отклонена."
  }
  hash_path="$TMP_DIR/fitodomik-desktop.zip.sha256"
  if need_command curl; then
    curl -fsL "${PACKAGE_URL}.sha256" -o "$hash_path" || {
      die "SHA256-файл недоступен: установка отклонена."
    }
  elif need_command wget; then
    wget -q -O "$hash_path" "${PACKAGE_URL}.sha256" || {
      die "SHA256-файл недоступен: установка отклонена."
    }
  fi
  expected="$(awk '{print $1}' "$hash_path")"
  [[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] || die "Некорректный SHA256: установка отклонена."
  actual="$(sha256sum "$PACKAGE_PATH" | awk '{print $1}')"
  if [ "$expected" != "$actual" ]; then
    die "SHA256 архива не совпадает. Скачивание повреждено или файл на сайте не обновлен."
  fi
  log "SHA256 архива проверен."
}
extract_package() {
  extract_dir="$TMP_DIR/extracted"
  mkdir -p "$extract_dir"
  unzip -q "$PACKAGE_PATH" -d "$extract_dir"
  main_file="$(find "$extract_dir" -mindepth 1 -maxdepth 3 -type f -name main.py -print -quit)"
  [ -n "$main_file" ] || die "В архиве не найден main.py"
  SOURCE_DIR="$(dirname "$main_file")"
}
prepare_install_dir() {
  if [ -d "$INSTALL_DIR" ] && [ -n "$(find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1)" ]; then
    echo
    echo "Папка $INSTALL_DIR уже существует."
    echo "1. Обновить файлы проекта, сохранив settings/logs/analysis_results"
    echo "2. Сделать backup всей папки и установить заново"
    echo "3. Отменить"
    choice="$(ask "Выберите действие [1/2/3]:" "1")"
    case "$choice" in
      1)
        log "Будет выполнено обновление файлов без удаления пользовательских данных."
        ;;
      2)
        backup="${INSTALL_DIR}.backup.$(date '+%Y%m%d_%H%M%S')"
        mv "$INSTALL_DIR" "$backup"
        log "Backup папки: $backup"
        mkdir -p "$INSTALL_DIR"
        ;;
      *)
        die "Установка отменена пользователем."
        ;;
    esac
  else
    mkdir -p "$INSTALL_DIR"
  fi
}
copy_project() {
  source_dir="$1"
  log "Копирую проект в $INSTALL_DIR"
  mkdir -p "$INSTALL_DIR"
  if need_command rsync; then
    rsync -a \
      --exclude '.git' \
      --exclude '.venv' \
      --exclude '__pycache__' \
      --exclude '*.pyc' \
      --exclude 'settings.json' \
      --exclude 'logs' \
      --exclude 'analysis_results' \
      "$source_dir"/ "$INSTALL_DIR"/
  else
    cp -a "$source_dir"/. "$INSTALL_DIR"/
    rm -rf "$INSTALL_DIR/.git" "$INSTALL_DIR/.venv" "$INSTALL_DIR/__pycache__"
    find "$INSTALL_DIR" -type d -name __pycache__ -prune -exec rm -rf {} + 2>/dev/null || true
  fi
  chmod +x "$INSTALL_DIR"/install.sh "$INSTALL_DIR"/run.sh "$INSTALL_DIR"/update.sh "$INSTALL_DIR"/diagnose.sh "$INSTALL_DIR"/uninstall.sh 2>/dev/null || true
  chmod +x "$INSTALL_DIR"/installer/*.sh 2>/dev/null || true
}
run_installer() {
  cd "$INSTALL_DIR"
  if [ -x "installer/install.sh" ]; then
    log "Запускаю основной установщик ФитоДомик."
    if [ -r /dev/tty ]; then
      FITODOMIK_INSTALL_DIR="$INSTALL_DIR" FITODOMIK_SKIP_PROJECT_PREPARE=1 ./installer/install.sh </dev/tty
    else
      FITODOMIK_INSTALL_DIR="$INSTALL_DIR" FITODOMIK_SKIP_PROJECT_PREPARE=1 ./installer/install.sh
    fi
  elif [ -x "install.sh" ]; then
    log "Запускаю основной установщик ФитоДомик."
    if [ -r /dev/tty ]; then
      FITODOMIK_INSTALL_DIR="$INSTALL_DIR" FITODOMIK_SKIP_PROJECT_PREPARE=1 ./install.sh </dev/tty
    else
      FITODOMIK_INSTALL_DIR="$INSTALL_DIR" FITODOMIK_SKIP_PROJECT_PREPARE=1 ./install.sh
    fi
  else
    die "install.sh не найден в скачанном пакете."
  fi
}
main() {
  echo "ФитоДомик: bootstrap-установщик"
  echo "Пакет: $PACKAGE_URL"
  echo "Папка установки: $INSTALL_DIR"
  echo
  case "$(uname -s 2>/dev/null || echo unknown)" in
    Linux*) ;;
    *)
      warn "Этот bootstrap рассчитан на Linux/Raspberry Pi. Для Windows используйте https://fitodomik.ru/install/windows"
      ;;
  esac
  install_download_tools_if_possible
  download_package
  verify_package
  extract_package
  prepare_install_dir
  copy_project "$SOURCE_DIR"
  cp "$LOG_FILE" "$INSTALL_DIR/bootstrap.log" 2>/dev/null || true
  run_installer
}
main "$@"
