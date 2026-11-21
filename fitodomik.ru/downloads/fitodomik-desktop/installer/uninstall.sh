#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "${SCRIPT_DIR}/../main.py" ]; then
  PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
else
  PROJECT_DIR="$SCRIPT_DIR"
fi
AUTOSTART_FILE="${HOME}/.config/autostart/fitodomik.desktop"
DESKTOP_FILE="${HOME}/Desktop/fitodomik.desktop"
BACKUP_DIR="${HOME}/FitoDomik_backup_$(date '+%Y%m%d_%H%M%S')"
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
echo "Удаление ФитоДомик"
if [ -f "$AUTOSTART_FILE" ]; then
  rm -f "$AUTOSTART_FILE"
  echo "Автозапуск отключен: $AUTOSTART_FILE"
else
  echo "Автозапуск не найден."
fi
if [ -f "$DESKTOP_FILE" ]; then
  rm -f "$DESKTOP_FILE"
  echo "Ярлык удален: $DESKTOP_FILE"
fi
if ask_yes_no "Сохранить backup settings.json, logs и analysis_results? [Y/n]" "Y"; then
  mkdir -p "$BACKUP_DIR"
  [ -f "$PROJECT_DIR/settings.json" ] && cp -a "$PROJECT_DIR/settings.json" "$BACKUP_DIR/"
  [ -d "$PROJECT_DIR/logs" ] && cp -a "$PROJECT_DIR/logs" "$BACKUP_DIR/"
  [ -d "$PROJECT_DIR/analysis_results" ] && cp -a "$PROJECT_DIR/analysis_results" "$BACKUP_DIR/"
  echo "Backup сохранен: $BACKUP_DIR"
fi
if ask_yes_no "Удалить папку проекта ${PROJECT_DIR}? Это действие необратимо. [y/N]" "N"; then
  cd "$HOME"
  rm -rf "$PROJECT_DIR"
  echo "Папка проекта удалена."
else
  echo "Папка проекта сохранена: $PROJECT_DIR"
fi