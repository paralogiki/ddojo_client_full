#!/bin/bash
export DISPLAY=:0
if [ ! -r "/etc/os-release" ]; then
	echo "ERROR: We cannot read from /etc/os-release, unable to continue"
	exit
fi
source /etc/os-release
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
# start local site code

# Change directory to currently directory
SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")
cd $SCRIPTPATH
# change to man client directory
cd ..
CLIENTDIR=`pwd`

URL="http://localhost:8000/launchafternet"
if [ ! -x bin/console ]; then
	URL="file://$CLIENTDIR/templates/errors/config.html"
else
	bin/console server:start
fi

if [ -x /usr/bin/unclutter ]; then
	/usr/bin/unclutter -idle 1.0 &
fi
# clean both user land and ddojo Prefences file to prevent
# Restore Session popup if crashed
sed -i 's/"exit_type":"Crashed"/"exit_type":"Normal"/' ~/.config/chromium/Default/Preferences
sed -i 's/"exit_type":"Crashed"/"exit_type":"Normal"/' ~/.config/ddojochromium/Default/Preferences
# open display html
# --disable-web-security requires --user-data-dir
# --test-type removes the disabled web-security warning
# --check-for-update-interval=31536000
$DD_CHROMIUM --kiosk --disable-web-security --user-data-dir=/home/pi/.config/ddojochromium --test-type --check-for-update-interval=31536000 --noerrdialogs --start-fullscreen --disable-translate --no-first-run --fast --fast-start --disable-infobars --disable-features=TranslateUI --allow-file-access-from-files --autoplay-policy=no-user-gesture-required $URL > /dev/null 2>&1 &

# Disable screen going black and screen turn off
xset s noblank
xset s off
# Disable Energy Star which would also turn off screen
xset -dpms
