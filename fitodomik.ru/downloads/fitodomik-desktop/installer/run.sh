#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "${SCRIPT_DIR}/../main.py" ]; then
  cd "${SCRIPT_DIR}/.."
else
  cd "$SCRIPT_DIR"
fi
if [ ! -d ".venv" ]; then
  echo "Виртуальное окружение .venv не найдено."
  echo "Сначала выполните ./install.sh или создайте окружение вручную."
  exit 1
fi
source .venv/bin/activate
python main.py