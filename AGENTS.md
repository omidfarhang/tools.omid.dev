# AGENTS.md

Instructions for coding agents working on **Tools Center** (`tools.omid.dev`).

Before UI or product-facing copy changes, read [PRODUCT.md](PRODUCT.md) and [DESIGN.md](DESIGN.md). Prefer existing patterns over inventing new ones.

## Project overview

PHP app: multi-user download-to-host toolkit. Users pull remote URLs via pluggable download sources (HTTP + curated yt-dlp sites + optional generic yt-dlp), manage files, optional public share links (`/s/{token}`), and per-user cloud OAuth (Dropbox is the first provider). Docker + Apache; SQLite; Bootstrap 5 + custom CSS.

## Layout

| Path | Role |
|------|------|
| `public/` | Webroot (PHP entrypoints, static assets) |
| `src/` | `App\` PSR-4 classes + `helpers.php` (`Download/`, `Cloud/` registries) |
| `templates/layout.php` | Shared HTML shell (sidebar / auth) |
| `storage/` | SQLite DB + temp downloads + materialized yt-dlp cookies (volume) |
| `assets/` + `scripts/copy-assets.js` | Vendor CSS/JS source → `public/assets` |
| `config/` | Example PHP config |
| `docs/` | Operator docs (cloud providers, nginx) |

There is no front-end framework or SPA. Pages are server-rendered PHP that call `View::render()`.

## Setup commands

```bash
# Docker (preferred)
cp .env.example .env   # set APP_BASE_URL, ADMIN_*, APP_CLI_TOKEN, DROPBOX_APP_*
docker compose up -d --build
# App: http://127.0.0.1:${APP_PORT:-8080}

# Local (non-Docker)
composer install
npm ci && npm run build:assets
cp .env.example .env
# Point vhost document root at public/
```

Asset pipeline: edit Bootstrap/jQuery via npm, then `npm run build:assets`. App styles live in `public/assets/css/app.css` (and CSS variables at the top of that file). Keep `DESIGN.md` tokens in sync when you change those variables.

## Code conventions

- PHP 8.2+, `declare(strict_types=1);` on entrypoints and classes
- Namespaced classes under `App\` in `src/`; keep them focused (`final class` is the norm)
- Escape output with `e()`; use `flash()` + `redirect()` for PRG flows
- Mutating POSTs must call `Csrf::requireValid()` (or equivalent) before side effects
- Auth: `Auth::requireLogin()`, `Auth::requireAdmin()`, or `Auth::requireLoginOrCli()` for CLI/token routes
- Prefer extending existing repositories/services over new parallel abstractions
- Do not add frameworks, ORMs, or build tools unless explicitly requested
- Do not commit secrets (`.env`, cookies, tokens). Cloud tokens are per-user in SQLite; site-wide yt-dlp cookies are admin-managed in SQLite (`Admin → yt-dlp cookies`)
- New download sites implement `App\Download\DownloadSource` and register in `DownloadRegistry`
- New cloud backends implement `App\Cloud\CloudProvider` and register in `CloudRegistry`

## Product constraints agents must respect

- Per-user cloud OAuth only — never introduce a shared cloud access token in env (Dropbox is the current provider)
- Self-register creates inactive users until admin approval
- Share links are opt-in via `ShareService`; do not expose arbitrary filesystem paths
- CLI auth is `APP_CLI_TOKEN` → `APP_CLI_USERNAME`’s cloud account; see `scripts/ytdl.sh` and `Auth::attemptCliToken()`

## UI work

1. Read `DESIGN.md` (tokens are normative)
2. Reuse classes in `app.css` / Bootstrap overrides (`.panel`, `.stat-card`, `.action-tile`, sidebar, auth-panel)
3. Ask before inventing new radii, accent colors, font families, or layout shells
4. After approved visual changes, update CSS variables in `app.css` and the front matter in `DESIGN.md`

## Security notes

- Public share download is intentionally unauthenticated for valid tokens — treat token entropy and revoke paths carefully
- Validate/sanitize filenames and paths in download/delete flows; never take raw paths from the client as filesystem locations
- CSRF on state-changing form posts; keep CLI token checks strict and constant-time where applicable

## Deployment notes

- Image builds via `.github/workflows/docker.yml` → `ghcr.io/<owner>/<repo>`
- TLS terminates at the operator’s nginx reverse proxy (example in `docs/`)
- First boot seeds admin from `ADMIN_USERNAME` / `ADMIN_PASSWORD` when the DB is empty
