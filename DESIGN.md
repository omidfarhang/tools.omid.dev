---
version: alpha
name: Tools Center
description: Operator toolkit UI — Tabler-first, teal accent, light/dark/auto
colors:
  primary: "#0f766e"
  primary-hover: "#0d9488"
  primary-dark: "#2dd4bf"
  sidebar: "#0b1220"
typography:
  body:
    fontFamily: system-ui
    fontWeight: 400
  mono:
    fontFamily: ui-monospace
---

# Tools Center Design

## Overview

Quiet operator UI built on **[Tabler](https://tabler.io) (`@tabler/core`)** with thin app theming. Prefer Tabler components (`page`, `navbar-vertical`, `card`, `empty`, `alert`, `table`, `btn`, `badge`) over custom chrome. Feels like a private control room — calm and dense enough for tables and forms.

Canonical theme tokens live in `public/assets/css/app.css`. Keep this file in sync when accents change.

## Color modes

- **Modes:** `light` | `dark` | `auto` (OS). Default preference is **auto**.
- **Mechanism:** `tabler-theme.min.js` (early in `<head>`) + `theme-switch.js`. Preference: `localStorage['tabler-theme']`. Resolved dark sets `data-bs-theme="dark"` on `<html>`.
- **Control:** Compact Light / System / Dark switcher alone in the page header (desktop) and the sidebar top bar (mobile). Auth pages keep it above the brand. Do not put the three-way switch inside the user menu.
- Do not force dark as the product default.

## Brand / accent

- Light accent `#0f766e` → hover `#0d9488`; dark accent `#2dd4bf`.
- Mapped to `--tblr-primary*` / `--tblr-link-*` in `app.css`.
- Vertical sidebar stays dark (`navbar-vertical` + `data-bs-theme="dark"`) in both page modes.
- Sidebar / auth brand: wrap avatar + app name in one flex child inside `.navbar-brand` (Tabler vertical brands use `justify-content: space-between` for an optional trailing control — do not leave mark and title as sibling flex items).

## Typography

- System UI stack via `--tblr-font-sans-serif` (no webfonts).
- Platform mono for filenames and technical values (`.mono`, `.filename`).

## Layout (Tabler)

- **App:** `.page` → `.navbar.navbar-vertical.navbar-expand-lg` + `.page-wrapper` → `.page-header` / `.page-body` / `.footer` inside `.container-xl`.
- **Sidebar zones:** brand at top → scrollable `.navbar-collapse` nav → pinned `.navbar-footer` user dropup (Account / Logout). Do not put username badge + Logout in the page header.
- **Auth:** `.page.page-center` → `.container.container-tight` → brand (`.navbar-brand.navbar-brand-autodark`) → page-owned `.card.card-md` → secondary text link below the card (Sign up / Sign in). Forms use `.form-footer` + `.btn.btn-primary.w-100`.
- **Page titles:** layout owns `.page-header` / `.page-title` (and optional Tabler `.breadcrumb`); page content uses a plain `.text-secondary` lede (do not nest another `.page-header`). When breadcrumbs are present, use a short page title that does not repeat the trail parent (e.g. breadcrumbs `Admin / Settings` → title `Settings`).

## Components (prefer Tabler)

| Need | Use |
|------|-----|
| Panels | `.card` / `.card-body` / `.card-header` / `.card-title` |
| Linked tiles | `.card.card-link` |
| Stats | `.card` + `.subheader` + `.h1` |
| Tables | `.table` / `.table-responsive` / `.card-table` when flush |
| Empty | `.empty` + `.empty-title` + `.empty-subtitle` |
| Warnings | `.alert.alert-warning` |
| Status chips | `.badge` + Tabler `bg-*-lt` helpers |
| Forms | `.form-label`, `.form-control`, `.form-select`, `.subheader` section labels |
| Hierarchy | Tabler `.breadcrumb` in `.page-header` via `View::render(..., $breadcrumbs)` — not ad-hoc “← Back” links |
| Signed-in user | Sidebar `.navbar-footer` dropup (Account, Logout) — not header chips |

App-specific leftovers in `app.css` (keep minimal): theme switcher, `.page-header .breadcrumb` margin (Tabler zeros breadcrumb margin), files table toolbar/selection bar, files preview modal (`.files-preview-*`), download destination `.choice-tile` grid, history name/source ellipsis (`.history-col-name` / `.history-col-source`), deep-linked file/history row highlight (`.files-row-highlight` / `.history-row-highlight`: accent pulse then persistent soft tint on `td`, no inset bar or shadow).

**Files preview:** allowlisted types (images, PDF, common video/audio, small text) open in a Tabler modal via `/preview.php` (`Content-Disposition: inline`). Non-previewable names still link to download. Public share URLs stay attachment-only.

## Do's and Don'ts

- Do prefer Tabler classes before adding rules to `app.css`
- Do theme via `--tblr-*` / `--accent` bridges; do not load Bootstrap separately
- Do keep the vertical navbar + page-wrapper shell
- Don’t invent parallel panel/sidebar/auth shells
- Don’t introduce purple/indigo marketing themes or force dark by default
- Don’t grow `app.css` for one-off page polish — use Tabler utilities
