#!/bin/sh
# your repository folder
cd ~/ddojo_local

# this version erases any client changes
# which we'd prefer anyway
git fetch origin

newUpdatesAvailable=`git diff HEAD FETCH_HEAD`
if [ "$newUpdatesAvailable" != "" ]; then
	git reset --hard origin/master
	echo "Updates applied"
	~/ddojo_local/scripts/launch.pi.sh
else
	echo "No updates found"
fi

### might need to think about doing it this way in the future
# fetch changes, git stores them in FETCH_HEAD
#git fetch
## check for remote changes in origin repository
#newUpdatesAvailable=`git diff HEAD FETCH_HEAD`
#if [ "$newUpdatesAvailable" != "" ]
#then
#        # create the fallback
#        git branch fallbacks
#        git checkout fallbacks
#        git add .
#        git add -u
#        git commit -m `date "+%Y-%m-%d"`
#        echo "fallback created"
#        git checkout master
#        git merge FETCH_HEAD
#        echo "merged updates"
#else
#        echo "no updates available"
#fi
