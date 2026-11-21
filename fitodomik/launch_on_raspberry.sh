#!/usr/bin/env bash

cat > /tmp/setup_fitodomik_kiosk_full.sh <<'EOF'
set -e
APP_DIR="/home/slava/Downloads/fitodomik"
SPLASH="$APP_DIR/assets/boot/splash.png"
USER_NAME="slava"
HOME_DIR="/home/slava"
if [ ! -d "$APP_DIR" ]; then
  echo "Нет папки $APP_DIR"
  exit 1
fi
if [ ! -f "$APP_DIR/main.py" ]; then
  echo "Нет файла $APP_DIR/main.py"
  exit 1
fi
if [ ! -f "$SPLASH" ]; then
  echo "Нет файла $SPLASH"
  exit 1
fi
sudo dpkg --configure -a || true
sudo apt --fix-broken install -y || true
sudo apt update
sudo apt install -y \
  plymouth \
  plymouth-themes \
  lightdm \
  xserver-xorg \
  x11-xserver-utils \
  x11-utils \
  matchbox-window-manager \
  unclutter \
  wmctrl \
  xdotool \
  python3-tk
sudo systemctl disable fitodomik-kiosk.service 2>/dev/null || true
sudo systemctl stop fitodomik-kiosk.service 2>/dev/null || true
sudo rm -f /etc/systemd/system/fitodomik-kiosk.service
sudo systemctl daemon-reload
rm -f "$HOME_DIR/.config/autostart/fitodomik.desktop"
if [ -f "$HOME_DIR/.xinitrc" ]; then
  mv "$HOME_DIR/.xinitrc" "$HOME_DIR/.xinitrc.off.$(date +%Y%m%d_%H%M%S)"
fi
python3 - <<'PY'
from pathlib import Path
for name in ["/home/slava/.bash_profile", "/home/slava/.profile"]:
    path = Path(name)
    if not path.exists():
        continue
    text = path.read_text(errors="ignore")
    start = "# FITODOMIK KIOSK START"
    end = "# FITODOMIK KIOSK END"
    while start in text and end in text:
        a = text.index(start)
        b = text.index(end) + len(end)
        text = text[:a] + text[b:]
    path.write_text(text)
PY
sudo rm -rf /etc/systemd/system/getty@tty1.service.d
sudo mkdir -p /usr/share/plymouth/themes/fitodomik
sudo cp "$SPLASH" /usr/share/plymouth/themes/fitodomik/splash.png
sudo tee /usr/share/plymouth/themes/fitodomik/fitodomik.plymouth > /dev/null <<'PLY'
[Plymouth Theme]
Name=FitoDomik
Description=FitoDomik boot splash
ModuleName=script
[script]
ImageDir=/usr/share/plymouth/themes/fitodomik
ScriptFile=/usr/share/plymouth/themes/fitodomik/fitodomik.script
PLY
sudo tee /usr/share/plymouth/themes/fitodomik/fitodomik.script > /dev/null <<'PLY'
Window.SetBackgroundTopColor(0.0, 0.0, 0.0);
Window.SetBackgroundBottomColor(0.0, 0.0, 0.0);
screen_width = Window.GetWidth();
screen_height = Window.GetHeight();
image = Image("splash.png");
image = image.Scale(screen_width, screen_height);
sprite = Sprite(image);
sprite.SetX(0);
sprite.SetY(0);
PLY
sudo plymouth-set-default-theme fitodomik
sudo update-initramfs -u || true
sudo cp /boot/firmware/cmdline.txt /boot/firmware/cmdline.txt.backup.$(date +%Y%m%d_%H%M%S)
sudo python3 - <<'PY'
from pathlib import Path
path = Path("/boot/firmware/cmdline.txt")
text = path.read_text().strip()
parts = text.split()
remove_exact = {
    "quiet",
    "splash",
    "logo.nologo",
    "plymouth.ignore-serial-consoles",
}
remove_prefixes = (
    "loglevel=",
    "systemd.show_status=",
    "vt.global_cursor_default=",
)
parts = [
    p for p in parts
    if p not in remove_exact and not p.startswith(remove_prefixes)
]
parts += [
    "quiet",
    "splash",
    "plymouth.ignore-serial-consoles",
    "loglevel=0",
    "systemd.show_status=false",
    "vt.global_cursor_default=0",
    "logo.nologo",
]
path.write_text(" ".join(parts) + "\n")
PY
mkdir -p "$APP_DIR/logs"
cat > "$APP_DIR/fitodomik_kiosk_launcher.py" <<'PY'
import os
import runpy
import tkinter as tk
app_dir = "/home/slava/Downloads/fitodomik"
OriginalTk = tk.Tk
class KioskTk(OriginalTk):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        def apply():
            try:
                w = self.winfo_screenwidth()
                h = self.winfo_screenheight()
                self.geometry(f"{w}x{h}+0+0")
                self.minsize(w, h)
                self.resizable(True, True)
                self.attributes("-fullscreen", True)
                self.overrideredirect(True)
                self.update_idletasks()
            except Exception:
                pass
            try:
                self.after(1000, apply)
            except Exception:
                pass
        self.after(100, apply)
        self.after(1000, apply)
        self.after(3000, apply)
tk.Tk = KioskTk
os.chdir(app_dir)
runpy.run_path(os.path.join(app_dir, "main.py"), run_name="__main__")
PY
sudo tee /usr/local/bin/fitodomik-kiosk-session > /dev/null <<'BASH'
APP_DIR="/home/slava/Downloads/fitodomik"
export HOME="/home/slava"
export USER="slava"
export FITODOMIK_KIOSK=1
export XDG_RUNTIME_DIR="/run/user/$(id -u)"
cd "$APP_DIR" || exit 1
mkdir -p logs
xsetroot -solid black 2>/dev/null || true
OUTPUT="$(xrandr 2>/dev/null | awk '/ connected/{print $1; exit}')"
if [ -n "$OUTPUT" ]; then
  xrandr --output "$OUTPUT" --auto >> "$APP_DIR/logs/kiosk-screen.log" 2>&1 || true
fi
xrandr >> "$APP_DIR/logs/kiosk-screen.log" 2>&1 || true
xsetroot -solid black 2>/dev/null || true
xset -dpms 2>/dev/null || true
xset s off 2>/dev/null || true
xset s noblank 2>/dev/null || true
unclutter -idle 0.1 -root 2>/dev/null &
matchbox-window-manager -use_titlebar no >> "$APP_DIR/logs/kiosk-window.log" 2>&1 &
sleep 1
if [ -x "$APP_DIR/.venv/bin/python" ]; then
  PYTHON="$APP_DIR/.venv/bin/python"
else
  PYTHON="$(command -v python3)"
fi
force_fullscreen_loop() {
  APP_PID="$1"
  while kill -0 "$APP_PID" 2>/dev/null; do
    GEOM="$(xdotool getdisplaygeometry 2>/dev/null || echo '800 480')"
    WIDTH="$(echo "$GEOM" | awk '{print $1}')"
    HEIGHT="$(echo "$GEOM" | awk '{print $2}')"
    WINDOWS="$(xdotool search --onlyvisible --pid "$APP_PID" 2>/dev/null || true)"
    if [ -z "$WINDOWS" ]; then
      WINDOWS="$(xdotool search --onlyvisible --name 'FitoDomik' 2>/dev/null || true)"
    fi
    if [ -z "$WINDOWS" ]; then
      WINDOWS="$(xdotool search --onlyvisible --name 'ФитоДомик' 2>/dev/null || true)"
    fi
    if [ -z "$WINDOWS" ]; then
      WINDOWS="$(xdotool getactivewindow 2>/dev/null || true)"
    fi
    for WIN in $WINDOWS; do
      xdotool windowactivate "$WIN" 2>/dev/null || true
      xdotool windowmove "$WIN" 0 0 2>/dev/null || true
      xdotool windowsize "$WIN" "$WIDTH" "$HEIGHT" 2>/dev/null || true
      wmctrl -i -r "$WIN" -b remove,maximized_vert,maximized_horz,fullscreen 2>/dev/null || true
      wmctrl -i -r "$WIN" -b add,fullscreen 2>/dev/null || true
      wmctrl -i -r "$WIN" -b add,maximized_vert,maximized_horz 2>/dev/null || true
      xdotool windowraise "$WIN" 2>/dev/null || true
    done
    sleep 1
  done
}
while true; do
  xsetroot -solid black 2>/dev/null || true
  echo "START $(date)" >> "$APP_DIR/logs/kiosk-app.log"
  echo "PYTHON=$PYTHON" >> "$APP_DIR/logs/kiosk-app.log"
  "$PYTHON" "$APP_DIR/fitodomik_kiosk_launcher.py" >> "$APP_DIR/logs/kiosk-app.log" 2>&1 &
  APP_PID="$!"
  force_fullscreen_loop "$APP_PID" &
  wait "$APP_PID"
  echo "CLOSED $(date)" >> "$APP_DIR/logs/kiosk-app.log"
  sleep 2
done
BASH
sudo chmod +x /usr/local/bin/fitodomik-kiosk-session
sudo tee /usr/share/xsessions/fitodomik-kiosk.desktop > /dev/null <<'DESKTOP'
[Desktop Entry]
Name=FitoDomik Kiosk
Comment=FitoDomik without desktop
Exec=/bin/bash /usr/local/bin/fitodomik-kiosk-session
Type=Application
DesktopNames=FitoDomik
DESKTOP
sudo groupadd -f autologin
sudo usermod -aG autologin,video,input,render,tty "$USER_NAME"
sudo mkdir -p /etc/lightdm/lightdm.conf.d
sudo tee /etc/lightdm/lightdm.conf.d/99-fitodomik-kiosk.conf > /dev/null <<'LIGHTDM'
[Seat:*]
autologin-user=slava
autologin-user-timeout=0
autologin-session=fitodomik-kiosk
user-session=fitodomik-kiosk
greeter-hide-users=true
greeter-show-manual-login=false
LIGHTDM
cat > "$HOME_DIR/.dmrc" <<'DMRC'
[Desktop]
Session=fitodomik-kiosk
DMRC
sudo systemctl set-default graphical.target
sudo systemctl enable lightdm
sudo chown -R "$USER_NAME:$USER_NAME" "$APP_DIR"
sudo chown "$USER_NAME:$USER_NAME" "$HOME_DIR/.dmrc" 2>/dev/null || true
sudo reboot
EOF
bash /tmp/setup_fitodomik_kiosk_full.sh
