#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
LOG_FILE="${PWD}/update.log"
TMP_REQ="/tmp/fitodomik_requirements.txt"
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}
log "Обновление ФитоДомик"
if [ -d ".git" ]; then
  git pull 2>&1 | tee -a "$LOG_FILE"
else
  log "Папка не является git-репозиторием, git pull пропущен."
fi
if [ ! -d ".venv" ]; then
  log "Создаю venv с доступом к системным пакетам."
  python3 -m venv .venv --system-site-packages
fi
source .venv/bin/activate
python -m pip install --upgrade pip setuptools wheel 2>&1 | tee -a "$LOG_FILE"
if [ -f requirements.txt ]; then
  grep -v -i -E '^[[:space:]]*(opencv-python|arduino-cli)([<=>~! ].*)?$' requirements.txt > "$TMP_REQ"
  python -m pip install -r "$TMP_REQ" 2>&1 | tee -a "$LOG_FILE"
fi
if ! command -v arduino-cli >/dev/null 2>&1; then
  log "WARNING: arduino-cli>=1.4.1 не найден. Для сборки/загрузки прошивки установите Arduino CLI отдельно."
fi
python -m compileall -q main.py fitodomik_app
log "Обновление завершено."