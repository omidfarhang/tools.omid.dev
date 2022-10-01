#!/bin/bash
dlfile=`yt-dlp "$1" -q --exec echo`
base_name=$(basename "$dlfile")
baseurl="https://tools.omidfarhang.com/temp/""$base_name"
wget -O- --no-check-certificate --quiet \
  --method POST \
  --timeout=0 \
  --header '' \
  --body-data 'url='"$baseurl"'&filename='"$base_name"'' \
   'https://tools.omidfarhang.com/dropbox.php'

