---
version: alpha
name: Tools Center
description: Operator toolkit UI — cool neutrals, teal accent, system UI fonts, light/dark/auto
colors:
  bg: "#eef2f6"
  surface: "#ffffff"
  surface-muted: "#f8fafc"
  topbar-bg: "rgba(255, 255, 255, 0.82)"
  sidebar: "#0b1220"
  sidebar-muted: "#8b97a8"
  sidebar-hover: "rgba(255, 255, 255, 0.06)"
  sidebar-active: "rgba(15, 118, 110, 0.22)"
  primary: "#0f766e"
  primary-hover: "#0d9488"
  primary-soft: "#ccfbf1"
  border: "#d8e0ea"
  border-strong: "#b8c4d4"
  text: "#0f172a"
  muted: "#64748b"
  danger: "#b91c1c"
  warning-bg: "#fff7ed"
  warning-border: "#fdba74"
  on-sidebar: "#ffffff"
  on-sidebar-nav: "#cbd5e1"
colors-dark:
  bg: "#0f1419"
  surface: "#1a222d"
  surface-muted: "#151c26"
  topbar-bg: "rgba(26, 34, 45, 0.88)"
  sidebar: "#080c14"
  primary: "#2dd4bf"
  primary-hover: "#5eead4"
  primary-soft: "rgba(45, 212, 191, 0.14)"
  border: "#2a3544"
  border-strong: "#3d4d61"
  text: "#e8eef5"
  muted: "#94a3b8"
  danger: "#f87171"
  warning-bg: "rgba(251, 146, 60, 0.12)"
  warning-border: "#c2410c"
typography:
  brand:
    fontFamily: system-ui
    fontSize: 16.8px
    fontWeight: 600
    lineHeight: 1.2
  page-title:
    fontFamily: system-ui
    fontSize: 24px
    fontWeight: 600
    lineHeight: 1.2
  topbar-title:
    fontFamily: system-ui
    fontSize: 18.4px
    fontWeight: 600
    lineHeight: 1.2
  body:
    fontFamily: system-ui
    fontSize: 16px
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: system-ui
    fontSize: 14px
    fontWeight: 500
    lineHeight: 1.3
  nav-section:
    fontFamily: system-ui
    fontSize: 11.2px
    fontWeight: 600
    lineHeight: 1
    letterSpacing: 0.08em
  mono:
    fontFamily: ui-monospace
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.4
  table-head:
    fontFamily: system-ui
    fontSize: 12px
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: 0.04em
rounded:
  sm: 8px
  md: 12px
  lg: 16px
  brand-mark: 10px
  full: 999px
spacing:
  content: 24px
  content-mobile: 18.4px
  panel: 20px
  topbar: 64px
  sidebar-width: 260px
components:
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "{spacing.panel}"
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-sidebar}"
    rounded: "{rounded.sm}"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  sidebar:
    backgroundColor: "{colors.sidebar}"
    textColor: "{colors.on-sidebar}"
    width: "{spacing.sidebar-width}"
  auth-panel:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    width: 420px
  user-chip:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.full}"
  status-callout:
    backgroundColor: "{colors.warning-bg}"
    rounded: "{rounded.sm}"
---

# Tools Center Design

## Overview

Cool, compact operator UI: slate sidebar, light gray workspace, white panels, teal accent. Feels like a private control room — calm, dense enough for tables and forms, never playful or promotional. Brand mark is a small teal gradient square with the product initial; product name sits beside it in the system UI stack.

Canonical CSS variables live in `public/assets/css/app.css` (`:root` + `[data-bs-theme="dark"]`). Tokens above mirror those values; if they diverge, update both.

## Color modes

- **Modes:** `light` | `dark` | `auto` (follows `prefers-color-scheme`). Default preference is **auto**.
- **Mechanism:** Tabler’s `tabler-theme.min.js` (early in `<head>`) + `theme-switch.js`. Preference stored as `localStorage['tabler-theme']`. Resolved theme sets `data-bs-theme="dark"` on `<html>` (light = attribute removed).
- **Control:** Compact three-button switcher in the topbar (and auth corner): Light / System / Dark.
- Do not force dark as the product default; auto respects the OS.

## Colors

- **Primary / accent (light `#0f766e` → dark `#2dd4bf`):** Actions, links, active nav bar, focus rings, progress. Soft mint / translucent teal for subtle fills.
- **Sidebar:** Near-black navy chrome in both modes; nav text slate, active state teal wash.
- **Surfaces (light):** Page `#eef2f6`, panels `#ffffff`, muted `#f8fafc`, borders `#d8e0ea`.
- **Surfaces (dark):** Page `#0f1419`, panels `#1a222d`, muted `#151c26`, borders `#2a3544`.
- **Text:** Light ink `#0f172a` / muted `#64748b`; dark ink `#e8eef5` / muted `#94a3b8`.
- **Semantic:** Danger and warning tokens have light and dark variants in `app.css`.

Auth screens use `--auth-wash` (teal + slate radial washes) that swaps with the color mode.

## Typography

- **UI / brand:** System UI stack (`system-ui`, Segoe UI, Roboto, Helvetica Neue, Arial). Standard dashboard weights only: 400 / 500 / 600 / 700.
- **Technical:** Platform monospace (`ui-monospace`, SF Mono, Menlo, Consolas) for filenames, code, and table links.
- No webfonts — rely on the OS UI face so the app reads as a plain operator dashboard.
- Uppercase micro-labels (nav sections, table heads, stat labels) with modest letter-spacing — sparingly. Avoid tight negative tracking on titles.
- **Tabler bridge:** UI kit is [`@tabler/core`](https://tabler.io) (Bootstrap-compatible). Theme it by mapping `--tblr-font-*`, `--tblr-body-*`, `--tblr-border-*`, and `--tblr-primary*` to app tokens in `app.css` `:root`. Override fonts to system UI (no Tabler webfonts). Keep the custom sidebar/topbar shell — do not adopt Tabler’s full demo chrome.

## Layout

- **App shell:** Fixed 260px sidebar + sticky frosted topbar (64px) + padded content. Below 992px, sidebar becomes offcanvas; hamburger in topbar.
- **Auth shell:** Centered panel (~420px), brand above the form, footer below.
- **Content rhythm:** Panels stack vertically; home uses stat cards / action tiles in grid utilities. Prefer one page header (title + short lede) then panels — not dashboard widget sprawl.
- Spacing follows ~8px rhythm via Tabler/Bootstrap utilities + custom padding (`1.25rem`–`1.5rem` content).

## Elevation & Depth

Light elevation only: thin border + soft dual shadow (`0 1px 2px` / `0 8px 24px` at low opacity). Interactive tiles lift ~2px on hover with a slightly stronger shadow — no neon glow, no multi-layer drama. Topbar uses translucent white + `backdrop-filter` blur.

## Shapes

- Panels / tiles: **12px**
- Controls / nav items: **8px**
- Brand mark: **10px**
- Pills (user chip, progress): **full**
- Auth panel: slightly larger than panel radius (~16px)

Keep corners consistent within a view; do not mix sharp and pill shapes on the same control type.

## Components

- **Sidebar nav:** Icon + label; active item teal wash + left accent bar animation.
- **Panel / panel-flush:** Primary content container for forms and tables.
- **Stat-card / action-tile:** Home overview only; hover lift; tiles border to accent on hover.
- **Tables:** Muted uppercase heads, mono filenames, striped odd rows near-white.
- **Files table / toolbar:** Checkbox + truncated mono name + size/date + Public/Private badge + single Actions dropdown. Compact filter + count sit in the panel head; a fixed-height selection strip stays reserved with bulk actions disabled until rows are checked. Sort via column header links.
- **Confirm / rename / cloud modals:** Tabler/Bootstrap modals (`tabler.Modal` in JS) with panel radius and border tokens; delete confirms with mono filename; no native `confirm()`.
- **Forms:** Form controls restyled — border, 8px radius, teal focus ring. Multi-step tool forms (home download) use `.form-section` + uppercase `.form-section-title` dividers, optional `.source-options-block` for nested extractor options, and `.choice-tile` grids for exclusive destinations.
- **Buttons:** Primary = accent teal (via `--tblr-primary`); outline secondary for Logout and secondary actions; keep font-weight 500.
- **Badges:** Dashboard weight 600, `0.75rem`, small radius — status chips and share Public/Private.
- **Alerts / status-callout:** Alerts for flashes; warning callout for Dropbox/setup hints.
- **Empty state:** Centered muted copy with a strong first line.
- **Theme switch:** `.theme-switch` segment control; do not replace with a floating FAB or marketing-style toggle.

Reuse these before inventing new card/tile variants.

## Do's and Don'ts

- Do keep the teal accent reserved for primary actions, links, and active indicators
- Do use the system UI + platform mono stacks as defined in `app.css` (no Bunny/Google font loads)
- Do theme Tabler via `--tblr-*` bridges in `app.css`; do not load a separate Bootstrap package alongside Tabler
- Do preserve the custom sidebar + topbar shell for authenticated pages and the centered auth panel for guests (avoid Tabler demo layouts)
- Don’t introduce purple/indigo gradients or cream-and-terracotta themes; don’t force dark as the default mode (prefer auto)
- Don’t add glassmorphism stacks, emoji ornamentation, or floating badge clutter
- Don’t put marketing heroes, stat strips, or multi-CTA landings on tool screens
- Don’t invent new CSS variable names when an existing `:root` token already fits — extend `app.css` and this file together
- Don’t use non-standard font weights (550, 650) or expressive display faces
