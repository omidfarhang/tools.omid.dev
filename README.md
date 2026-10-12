# tools.omid.dev

Personal **Download to Host** toolkit: pull remote URLs to the server (HTTP + curated yt-dlp sites + optional generic yt-dlp), manage them per user, optional public share links, and per-user cloud OAuth (Dropbox is the first provider).

## Features

- Multi-user auth (`admin` / `user`); self-register (inactive until admin approves) or admin-created accounts
- Pluggable download sources: HTTP(S); curated yt-dlp (YouTube, X/Twitter, Instagram, TikTok); optional generic yt-dlp
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
| `APP_CLI_TOKEN` / `APP_CLI_USERNAME` | CLI auth → that user’s cloud account |
| `DROPBOX_APP_KEY` / `DROPBOX_APP_SECRET` | Dropbox OAuth app credentials (first cloud provider) |
| `DB_PATH` / `TEMP_DIR` | SQLite + download storage |
| `YTDLP_COOKIES_PATH` | On-disk cookie file materialized for yt-dlp (managed via Admin UI) |

### YouTube / yt-dlp cookies

YouTube frequently returns “Sign in to confirm you’re not a bot” from datacenter IPs. As an admin:

1. Export YouTube cookies from a logged-in browser into Netscape format (see [yt-dlp cookie FAQ](https://github.com/yt-dlp/yt-dlp/wiki/FAQ#how-do-i-pass-cookies-to-yt-dlp) and [YouTube cookie tips](https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies)).
2. Open **Admin → yt-dlp cookies**, paste or upload the file, and save.
3. Prefer a throwaway Google account; cookie use can risk the account.

Cookies are stored in SQLite and written to `YTDLP_COOKIES_PATH` for yt-dlp / `scripts/ytdl.sh`. A legacy `.secure/cookies.txt` is imported once if present.

See also [docs/dropbox.md](docs/dropbox.md).

## Layout

- `public/` — webroot
- `src/` — application code
- `storage/` — SQLite DB + temp files + materialized yt-dlp cookies (mounted volume)
- `src/Download/` — download source registry (HTTP, curated yt-dlp sites, generic yt-dlp)
- `src/Cloud/` — cloud provider registry (Dropbox)

## GHCR

On push to `main` / tags `v*`, GitHub Actions builds and pushes:

`ghcr.io/<owner>/<repo>:<tag>`
