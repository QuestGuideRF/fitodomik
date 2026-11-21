#!/usr/bin/env bash
set -eu

umask 027

SCRIPT_DIR="$(dirname -- "$0")"
cd -- "$SCRIPT_DIR"
PROJECT_DIR="$(pwd -P)"
BOT_DIR="$PROJECT_DIR/bot"
VENV_DIR="$BOT_DIR/venv"
RUNTIME_DIR="$BOT_DIR/runtime"
LOG_DIR="$BOT_DIR/logs"
REQUIREMENTS="$BOT_DIR/requirements.txt"
ENV_FILE="$PROJECT_DIR/.env"
VENV_PYTHON="$VENV_DIR/bin/python"
INSTALL_STAMP="$VENV_DIR/.fitodomik-requirements-installed"
LAUNCHER_LOG="$LOG_DIR/launcher.log"
CONSOLE_LOG="$LOG_DIR/telegram_bot.console.log"
PID_FILE="$RUNTIME_DIR/telegram_bot.pid"
MODE="${1:-start}"

mkdir -p "$RUNTIME_DIR" "$LOG_DIR"

log() {
    MESSAGE="$(date '+%Y-%m-%d %H:%M:%S') $*"
    printf '%s\n' "$MESSAGE"
    printf '%s\n' "$MESSAGE" >> "$LAUNCHER_LOG"
}

usage() {
    cat <<HELP
FitoDomik Telegram bot launcher

  ./start.sh              start the bot in the background if it is not running
  ./start.sh --foreground prepare the environment and run in the foreground
  ./start.sh --check      prepare the environment and verify Python imports
  ./start.sh --stop       safely stop the process recorded in the runtime PID file
HELP
}

read_bot_pid() {
    [ -f "$PID_FILE" ] || return 1
    PID="$(tr -d '[:space:]' < "$PID_FILE")"
    [ -n "$PID" ] || return 1
    printf '%s' "$PID" | grep -Eq '^[0-9]+$' || return 1
    printf '%s' "$PID"
}

is_bot_process() {
    PID="$1"
    kill -0 "$PID" 2>/dev/null || return 1
    [ -r "/proc/$PID/cmdline" ] || return 1
    CMDLINE="$(tr '\000' ' ' < "/proc/$PID/cmdline")"
    printf '%s' "$CMDLINE" | grep -Fq -- "-m bot.main"
}

bot_is_running() {
    PID=""
    if PID="$(read_bot_pid 2>/dev/null)"; then
        :
    fi
    [ -n "$PID" ] && is_bot_process "$PID"
}

stop_bot() {
    PID=""
    if PID="$(read_bot_pid 2>/dev/null)"; then
        :
    fi
    if [ -z "$PID" ]; then
        log "Telegram bot PID is absent; nothing to stop."
        return 0
    fi
    if ! is_bot_process "$PID"; then
        log "Refusing to stop PID $PID: it is not the FitoDomik bot process."
        return 1
    fi
    kill -TERM "$PID"
    log "Sent SIGTERM to Telegram bot PID $PID."
}

find_bootstrap_python() {
    if [ -n "${PYTHON_BIN:-}" ] && [ -x "$PYTHON_BIN" ]; then
        printf '%s' "$PYTHON_BIN"
        return 0
    fi
    command -v python3 2>/dev/null || return 1
}

prepare_environment() {
    if [ ! -f "$ENV_FILE" ]; then
        log "Environment file not found: $ENV_FILE"
        return 1
    fi
    if [ ! -f "$REQUIREMENTS" ]; then
        log "Requirements file not found: $REQUIREMENTS"
        return 1
    fi
    if [ ! -x "$VENV_PYTHON" ]; then
        BOOTSTRAP_PYTHON="$(find_bootstrap_python)" || {
            log "Python 3 was not found. Set PYTHON_BIN to its absolute path."
            return 1
        }
        if ! "$BOOTSTRAP_PYTHON" -c \
            "import sys; raise SystemExit(0 if sys.version_info >= (3, 8) else 1)"; then
            log "Python 3.8 or newer is required."
            return 1
        fi
        log "Creating Python virtual environment in $VENV_DIR"
        "$BOOTSTRAP_PYTHON" -m venv "$VENV_DIR" >> "$LAUNCHER_LOG" 2>&1
    fi

    if ! "$VENV_PYTHON" -c \
        "import sys; raise SystemExit(0 if sys.version_info >= (3, 8) else 1)"; then
        log "The existing virtual environment uses Python older than 3.8."
        return 1
    fi

    NEED_INSTALL=0
    if [ ! -f "$INSTALL_STAMP" ] || [ "$REQUIREMENTS" -nt "$INSTALL_STAMP" ]; then
        NEED_INSTALL=1
    elif ! "$VENV_PYTHON" -c "import aiogram, aiomysql, aiohttp, dotenv" >/dev/null 2>&1; then
        NEED_INSTALL=1
    fi
    if [ "$NEED_INSTALL" -eq 1 ]; then
        log "Installing Telegram bot dependencies"
        "$VENV_PYTHON" -m pip install --disable-pip-version-check \
            -r "$REQUIREMENTS" >> "$LAUNCHER_LOG" 2>&1
        "$VENV_PYTHON" -m pip check >> "$LAUNCHER_LOG" 2>&1
        touch "$INSTALL_STAMP"
    fi
}

if [ "$MODE" = "--help" ] || [ "$MODE" = "-h" ]; then
    usage
    exit 0
fi
if [ "$MODE" = "--stop" ]; then
    stop_bot
    exit $?
fi
if [ "$MODE" != "start" ] && [ "$MODE" != "--foreground" ] && [ "$MODE" != "--check" ]; then
    usage >&2
    exit 2
fi

if [ "$MODE" != "--check" ] && bot_is_running; then
    log "Telegram bot is already running (PID $(read_bot_pid))."
    exit 0
fi

prepare_environment
export PYTHONPATH="$PROJECT_DIR${PYTHONPATH:+:$PYTHONPATH}"
export PYTHONUNBUFFERED=1
cd "$PROJECT_DIR"

if [ "$MODE" = "--check" ]; then
    "$VENV_PYTHON" -c \
        "import bot.main; from aiogram import Dispatcher; from bot.config import load_settings; from bot.handlers import register_routers; load_settings(); dispatcher=Dispatcher(); register_routers(dispatcher)"
    log "Python imports and aiogram routers are valid."
    exit 0
fi

if [ "$MODE" = "--foreground" ]; then
    exec "$VENV_PYTHON" -m bot.main
fi

nohup "$VENV_PYTHON" -m bot.main >> "$CONSOLE_LOG" 2>&1 &
STARTED_PID=$!
sleep 2
if kill -0 "$STARTED_PID" 2>/dev/null; then
    log "Telegram bot started in the background (PID $STARTED_PID)."
    exit 0
fi

wait "$STARTED_PID" || STATUS=$?
STATUS="${STATUS:-1}"
log "Telegram bot failed during startup with status $STATUS. See $CONSOLE_LOG."
exit "$STATUS"
