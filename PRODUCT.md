# Tools Center

## Register

**Tools Center** (`tools.omid.dev`) — a personal Download-to-Host toolkit.

## Users

- Solo operators and small private groups who need a host-side downloader with accounts
- Admins who approve registrations, manage users, and oversee stored files
- Power users who also drive downloads via CLI (`scripts/ytdl.sh`) into their own cloud (Dropbox today)

## Problem

Browsers and phones are awkward places to pull large remote assets (HTTP(S), curated sites via yt-dlp such as YouTube / X / Instagram / TikTok, plus optional generic yt-dlp). Shared cloud tokens and single-user scripts do not scale to multiple people on one host. Without this product, files land in ad-hoc shell sessions, share links are improvisational, and cloud credentials get shared or hard-coded.

## Product Purpose

Pull remote files onto the server via pluggable download sources, keep them per user, optionally publish share links, and let each user connect their own cloud provider (Dropbox is the first).

Success looks like:

- A logged-in user pastes a URL, chooses save-to-disk or save-to-cloud, and gets a reliable result
- Files are listed, downloadable, and shareable under that user’s ownership
- New accounts stay inactive until an admin approves them
- CLI downloads authenticate with `APP_CLI_TOKEN` and use the mapped user’s cloud OAuth — never a shared cloud secret

## Brand Personality / Tone

Quiet, precise, operator-facing. Copy is short and literal (“My Files”, “Connect Dropbox”, “Save To Cloud”), not marketing-heavy. Prefer calm status messages over celebration. Technical filenames and sizes use monospace; UI chrome stays out of the way.

## Anti-references

- Not a public file-sharing marketplace or CDN product
- Not a multi-tenant SaaS with billing, teams org charts, or social features
- Not a generic media library / Plex / gallery app
- Not a Dropbox clone; cloud providers are optional egress, not the primary store
- Must not feel like a purple-gradient startup dashboard or a dense admin theme park

## Design Principles

1. **One job per screen** — download, browse files, account/cloud, admin — keep secondary chrome minimal.
2. **Ownership is obvious** — users see their files; admins see overview + all files; share links are explicit opt-in.
3. **Credentials stay personal** — per-user cloud OAuth (Dropbox today); no shared cloud token in `.env`.
4. **Honest feedback** — flash messages and inline results state success or failure plainly.
5. **Host-first** — the server disk (and optional share URL) is the source of truth; cloud sync is a convenience path.

## Accessibility & Inclusion

- Semantic structure via Tabler/Bootstrap-compatible patterns; focus rings and form labels stay intact
- Color contrast for body text on surfaces meets WCAG AA; accent teal is for actions and highlights, not long body copy
- Auth and app shells remain usable on narrow viewports (offcanvas nav under ~992px)
- Do not convey status by color alone when an alert or label can carry the message

## Offer

Private / personal deployment. No public pricing. Operators self-host via Docker (GHCR image) or bare PHP 8.2+ with `yt-dlp` / `ffmpeg`.

## Boundaries

- Not a general-purpose CMS, blog, or link shortener
- Not responsible for site ToS / DRM circumvention policy (YouTube and other yt-dlp extractors) — admins supply cookies via Admin → yt-dlp and accept host risk
- Dropbox app key/secret are site settings (Admin → Cloud); does not store a global cloud *access* token — CLI maps to one configured username’s OAuth
- Public share routes (`/s/{token}`) are intentional capability, not accidental open directory listing

## Stack

- [AGENTS.md](AGENTS.md) — build, layout, and coding conventions for agents
- [DESIGN.md](DESIGN.md) — visual tokens and UI rules
- [README.md](README.md) — human quick start
- [docs/dropbox.md](docs/dropbox.md) — Dropbox (first cloud provider) OAuth setup
- [docs/nginx-reverse-proxy.conf.example](docs/nginx-reverse-proxy.conf.example) — TLS reverse proxy example
