#!/bin/bash
cd ~
rm -rf ddojo_local/
rm -r .config/ddojo/ ddojo_local-*.xz Desktop/ddojo*desktop install.sh 2>/dev/null
# Consider using sed to search replace autostart
rm -r .config/lxsession/ 2>/dev/null
pkill -f "127.0.0.1:8000.*ddojo"
DD_CHROMIUM="/usr/bin/chromium-browser"
DD_KILL_CHROMIUM_GREP="chromium-browser"
if [ "$ID" == "arch" ]; then
	DD_CHROMIUM="/usr/bin/chromium"
	DD_KILL_CHROMIUM_GREP="chromium"
elif [ "$ID" == "debian" ]; then
	DD_CHROMIUM="/usr/bin/chromium"
	DD_KILL_CHROMIUM_GREP="chromium"
fi
pkill -f "$DD_KILL_CHROMIUM_GREP"
echo "Uinstall complete"
