#!/bin/bash
cd ~
DD_CHROMIUM="/usr/bin/chromium-browser"
DD_KILL_CHROMIUM_GREP="chromium-browser"
if [ "$ID" == "arch" ]; then
	DD_CHROMIUM="/usr/bin/chromium"
	DD_KILL_CHROMIUM_GREP="chromium"
elif [ "$ID" == "debian" ]; then
	DD_CHROMIUM="/usr/bin/chromium"
	DD_KILL_CHROMIUM_GREP="chromium"
fi
# kill any active client local site code
pkill -f "127.0.0.1:8000.*ddojo"
# kill all open chromium browsers
# the -o switch here does oldest which doesnt kill each tab
# hopefully preventing the crash popup
pkill -o -f -- "$DD_KILL_CHROMIUM_GREP"
sleep 2
DD_COUNT_REMAINING_CHROMIUM="`pgrep -c "$DD_KILL_CHROMIUM_GREP"`"
if [[ "$DD_COUNT_REMAINING_CHROMIUM" -gt 4 ]]; then
	DD_LOOP=1
	while true; do
		pkill -o -f -- "$DD_KILL_CHROMIUM_GREP"
		sleep 2
		DD_COUNT_REMAINING_CHROMIUM="`pgrep -c "$DD_KILL_CHROMIUM_GREP"`"
		if [[ "$DD_COUNT_REMAINING_CHROMIUM" -eq 0 ]]; then
			break;
		fi
		DD_LOOP=$((DD_LOOP + 1))
		if [[ "$DD_LOOP" -gt 5 ]]; then
			# we tried to kill oldest ones first gotta kill them all now
			pkill -f -- "$DD_KILL_CHROMIUM_GREP"
			break
		fi
	done
fi
rm -rf ddojo_local/
rm -r .config/ddojo/ .config/ddojochromium/ ddojo_local-*.xz Desktop/ddojo*desktop install.sh 2>/dev/null
# Consider using sed to search replace autostart
rm -r .config/lxsession/ 2>/dev/null
if [ -f "/etc/cron.d/ddojo" ]; then
	rm /etc/cron.d/ddojo
fi
echo "Uninstall complete"
