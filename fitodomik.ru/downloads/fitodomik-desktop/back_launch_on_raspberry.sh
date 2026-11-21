#!/usr/bin/env bash

sudo rm -f /etc/lightdm/lightdm.conf.d/99-fitodomik-kiosk.conf
sudo rm -f /usr/share/xsessions/fitodomik-kiosk.desktop
sudo rm -f /usr/local/bin/fitodomik-kiosk-session
rm -f /home/slava/.dmrc
sudo systemctl set-default graphical.target
sudo systemctl enable lightdm
sudo reboot
