#!/usr/bin/env bash
set -euo pipefail

# Download with yt-dlp into TEMP_DIR (must be the same volume the app serves),
# then register the file for APP_CLI_USERNAME and send it to their Dropbox.
#
# Usage:
#   APP_BASE_URL=https://tools.omid.dev \
#   APP_CLI_TOKEN=... \
#   TEMP_DIR=./storage/temp \
#   ./scripts/ytdl.sh "https://www.youtube.com/watch?v=..." [extra yt-dlp args...]

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <url> [extra yt-dlp args...]" >&2
  exit 1
fi

URL="$1"
shift || true

APP_BASE_URL="${APP_BASE_URL:-https://tools.omid.dev}"
APP_CLI_TOKEN="${APP_CLI_TOKEN:?APP_CLI_TOKEN is required}"
TEMP_DIR="${TEMP_DIR:-./storage/temp}"

mkdir -p "$TEMP_DIR"
DLFILE="$(yt-dlp "$URL" "$@" \
  -o "${TEMP_DIR}/%(title).200B [%(id)s].%(ext)s" \
  --no-playlist \
  -q \
  --print after_move:filepath)"

BASE_NAME="$(basename "$DLFILE")"

RESP="$(curl -fsS -X POST \
  -H "X-CLI-Token: ${APP_CLI_TOKEN}" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "filename=${BASE_NAME}" \
  "${APP_BASE_URL}/api/cli_register_and_dropbox.php")"

echo "$RESP"
