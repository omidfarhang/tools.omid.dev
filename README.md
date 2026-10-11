# tools.omid.dev

Personal **Download to Host** toolkit: pull HTTP(S)/YouTube files to the server, manage them per user, optional public share links, and per-user Dropbox OAuth.

## Features

- Multi-user auth (`admin` / `user`); self-register (inactive until admin approves) or admin-created accounts
- Download URL or YouTube (`yt-dlp`) to disk
- Public share links (`/s/{token}`) without login
- Per-user Dropbox connect (no shared Dropbox token)
- CLI helper `scripts/ytdl.sh` (uses `APP_CLI_TOKEN` + CLI user’s Dropbox)
- Docker image + GitHub Actions → GHCR; TLS via your nginx reverse proxy

## Requirements

- PHP 8.2+ with `curl`, `pdo_sqlite`
- `yt-dlp` and `ffmpeg` (bundled in the Docker image)
- Optional: YouTube cookies at `YTDLP_COOKIES_PATH`

## Quick start (Docker)

```bash
cp .env.example .env
# edit APP_BASE_URL, ADMIN_*, APP_CLI_TOKEN, DROPBOX_APP_* 

docker compose up -d --build
```

App is published on **http://127.0.0.1:8080** by default (`APP_PORT` → container `:8080`). If 8080 is already in use, set e.g. `APP_PORT=9080` in `.env` and point nginx at that host port — see [docs/nginx-reverse-proxy.conf.example](docs/nginx-reverse-proxy.conf.example).

First boot creates an admin from `ADMIN_USERNAME` / `ADMIN_PASSWORD`.

## Local (non-Docker)

```bash
composer install
npm ci && npm run build:assets
cp .env.example .env
# point a vhost document root at public/
```

## Environment

| Variable | Purpose |
|----------|---------|
| `APP_BASE_URL` | Public site URL (OAuth redirect + share links) |
| `APP_PORT` | Host port for Docker publish (default `8080`) |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | Seed admin when DB is empty |
| `APP_CLI_TOKEN` / `APP_CLI_USERNAME` | CLI auth → that user’s Dropbox |
| `DROPBOX_APP_KEY` / `DROPBOX_APP_SECRET` | Dropbox OAuth app credentials |
| `DB_PATH` / `TEMP_DIR` | SQLite + download storage |
| `YTDLP_COOKIES_PATH` | Optional cookies for yt-dlp |

See also [docs/dropbox.md](docs/dropbox.md).

## Layout

- `public/` — webroot
- `src/` — application code
- `storage/` — SQLite DB + temp files (mounted volume)
- `.secure/` — optional yt-dlp cookies (not Dropbox tokens)

## GHCR

On push to `main` / tags `v*`, GitHub Actions builds and pushes:

`ghcr.io/<owner>/<repo>:<tag>`
