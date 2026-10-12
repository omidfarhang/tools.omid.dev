#!/usr/bin/env python3
"""Regenerate src/Download/data/ytdlp-known-hosts.json from the installed yt-dlp.

Usage:
  python3 -m venv /tmp/ytdlp-venv && /tmp/ytdlp-venv/bin/pip install -U yt-dlp
  /tmp/ytdlp-venv/bin/python scripts/generate-ytdlp-known-hosts.py
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

try:
    from yt_dlp.extractor import gen_extractor_classes
    from yt_dlp.version import __version__ as YTDLP_VERSION
except ImportError:
    print("yt-dlp is required: pip install yt-dlp", file=sys.stderr)
    sys.exit(1)

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "src" / "Download" / "data" / "ytdlp-known-hosts.json"

# Already auto-matched by curated DownloadSource classes — omit from this list.
CURATED_SUFFIXES = (
    "youtube.com",
    "youtu.be",
    "youtube-nocookie.com",
    "twitter.com",
    "x.com",
    "instagram.com",
    "instagr.am",
    "tiktok.com",
)

# Short / alias hosts that regex scraping sometimes misses.
EXTRA_HOSTS = (
    "fb.watch",
    "on.soundcloud.com",
    "m.soundcloud.com",
    "clips.twitch.tv",
    "player.vimeo.com",
    "v.redd.it",
    "i.imgur.com",
    "streamable.com",
    "b23.tv",
    "youtu.be",  # filtered as curated
)

BLOCK_SUFFIXES = (
    "cloudfront.net",
    "akamaihd.net",
    "akamaized.net",
    "edgecastcdn.net",
    "fastly.net",
    "amazonaws.com",
    "googleusercontent.com",
    "ggpht.com",
    "gvt1.com",
    "mzstatic.com",
    "twimg.com",
    "fbcdn.net",
    "cdninstagram.com",
    "pinimg.com",
    "redditmedia.com",
)

HOST_LITERAL_RE = re.compile(r"((?:[A-Za-z0-9-]+\\.)+[A-Za-z]{2,})")
HASHY_RE = re.compile(r"(^|\.)\d{2,}[a-f0-9]{8,}")


def add_host(hosts: set[str], value: str | None) -> None:
    if not value:
        return
    h = value.lower().strip(".")
    if h.startswith("www."):
        h = h[4:]
    if any(ch in h for ch in "[](){}|*+?^$\\"):
        return
    if h.count(".") < 1 or not (4 <= len(h) <= 64):
        return
    if HASHY_RE.search(h) or re.match(r"^[0-9.]+$", h):
        return
    if h.endswith(".local") or h.endswith(".lan"):
        return
    hosts.add(h)


def is_curated(h: str) -> bool:
    return any(h == c or h.endswith("." + c) for c in CURATED_SUFFIXES)


def is_blocked(h: str) -> bool:
    return any(h == b or h.endswith("." + b) for b in BLOCK_SUFFIXES)


def collect() -> list[str]:
    hosts: set[str] = set()
    for ie in gen_extractor_classes():
        name = str(getattr(ie, "IE_NAME", "") or "")
        if name.lower() == "generic":
            continue
        valid = getattr(ie, "_VALID_URL", None)
        if isinstance(valid, str):
            for match in HOST_LITERAL_RE.finditer(valid):
                add_host(hosts, match.group(1).replace(r"\.", "."))
        tests = getattr(ie, "_TESTS", None) or ()
        if isinstance(tests, dict):
            tests = [tests]
        for test in tests:
            if not isinstance(test, dict):
                continue
            url = test.get("url")
            if not url:
                continue
            try:
                add_host(hosts, urlparse(url).hostname)
            except Exception:
                pass

    for extra in EXTRA_HOSTS:
        add_host(hosts, extra)

    hosts = {h for h in hosts if not is_curated(h) and not is_blocked(h)}

    # Prefer shorter suffixes (vimeo.com covers player.vimeo.com).
    ordered = sorted(hosts, key=lambda s: (s.count("."), len(s), s))
    minimal: list[str] = []
    for h in ordered:
        if any(h == s or h.endswith("." + s) for s in minimal):
            continue
        minimal.append(h)
    minimal.sort()
    return minimal


def main() -> None:
    hosts = collect()
    payload = {
        "generated_from": "yt-dlp",
        "yt_dlp_version": YTDLP_VERSION,
        "hosts": hosts,
    }
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(payload, indent=0) + "\n", encoding="utf-8")
    print(f"wrote {len(hosts)} hosts -> {OUT.relative_to(ROOT)} (yt-dlp {YTDLP_VERSION})")


if __name__ == "__main__":
    main()
