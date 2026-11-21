#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -d ".venv" ]; then
  echo "Виртуальное окружение .venv не найдено."
  echo "Сначала выполните ./install.sh или создайте окружение вручную."
  exit 1
fi
source .venv/bin/activate
python main.py