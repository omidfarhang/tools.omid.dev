#!/bin/bash
dlfile=`yt-dlp "$1" "$2" -q --exec echo`
base_name=$(basename "$dlfile")
baseurl="https://tools.omid.dev/temp/""$base_name"
wget -O- --no-check-certificate --quiet \
  --method POST \
  --timeout=0 \
  --header '' \
  --body-data 'url='"$baseurl"'&filename='"$base_name"'' \
   'https://tools.omid.dev/dropbox.php'

