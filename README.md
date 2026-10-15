# tools.omid.dev

Personal **Download to Host** toolkit: pull remote URLs to the server (HTTP + curated yt-dlp sites + optional generic yt-dlp), manage them per user, optional public share links, and per-user cloud OAuth (Dropbox is the first provider).

## Features

- Multi-user auth (`admin` / `user`); self-register (inactive until admin approves) or admin-created accounts; optional TOTP 2FA on Account → Security (with remember-this-device)
- Pluggable download sources: HTTP(S); curated yt-dlp (YouTube, X/Twitter, Instagram, TikTok, Vimeo, Reddit; audio: SoundCloud, Mixcloud, Bandcamp); optional generic yt-dlp
- Public share links (`/s/{token}`) without login
- Pluggable cloud providers: Dropbox connect (no shared cloud token)
- CLI helper `scripts/ytdl.sh` (uses `APP_CLI_TOKEN` + CLI user’s cloud)
- Docker image + GitHub Actions → GHCR; TLS via your nginx reverse proxy

## Requirements

- PHP 8.2+ with `curl`, `pdo_sqlite`
- `yt-dlp` (with EJS), `ffmpeg`, and Deno (bundled in the Docker image; YouTube needs the JS runtime)
- Optional but usually required for YouTube: Netscape cookies via **Admin → yt-dlp cookies**

## Quick start (Docker)

```bash
cp .env.example .env
# edit APP_BASE_URL, ADMIN_*, APP_CLI_TOKEN

docker compose up -d --build
```

App is published on **http://127.0.0.1:8080** by default (`APP_PORT` → container `:8080`). If 8080 is already in use, set e.g. `APP_PORT=9080` in `.env` and point nginx at that host port — see [docs/nginx-reverse-proxy.conf.example](docs/nginx-reverse-proxy.conf.example) (includes `limit_req` for login/register). For host fail2ban against `storage/logs/auth.log`, see [docs/fail2ban.md](docs/fail2ban.md).

First boot creates an admin from `ADMIN_USERNAME` / `ADMIN_PASSWORD`. Then use **Admin → Settings** for Dropbox app credentials and yt-dlp cookies/options.

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
| `APP_CLI_TOKEN` / `APP_CLI_USERNAME` | CLI auth → that user’s cloud account |
| `DB_PATH` / `TEMP_DIR` | SQLite + download storage |
| `YTDLP_COOKIES_PATH` | On-disk cookie file materialized for yt-dlp (managed via Admin → yt-dlp) |

### Admin settings

| Section | Path | What |
|---------|------|------|
| yt-dlp | `/admin/settings/ytdlp.php` | Netscape cookies + optional extra yt-dlp args |
| Cloud | `/admin/settings/cloud.php` | Dropbox + Google Drive app credentials (SQLite only) |
| Security | `/admin/settings/security.php` | Login rate limits + unblock IP/username |

See [docs/dropbox.md](docs/dropbox.md) and [docs/google-drive.md](docs/google-drive.md) for OAuth app setup.

## Layout

- `public/` — webroot
- `src/` — application code
- `storage/` — SQLite DB + temp files + auth log + materialized yt-dlp cookies (mounted volume)
- `src/Download/` — download source registry (HTTP, curated yt-dlp sites, generic yt-dlp)
- `src/Cloud/` — cloud provider registry (Dropbox, Google Drive)

## GHCR

On push to `main` / tags `v*`, GitHub Actions builds and pushes:

`ghcr.io/<owner>/<repo>:<tag>`
