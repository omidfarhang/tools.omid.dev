# Fail2ban (auth log)

The app appends fail2ban-parseable lines to **`storage/logs/auth.log`** (bind-mounted with the rest of `storage/` in Docker). Fail2ban must run on the **host** and read that file — publishing the container port alone is not enough.

## Log format

Stable prefix `tools-auth` (do not change casually):

```text
2026-10-15T15:04:05+00:00 tools-auth FAILURE ip=203.0.113.10 user=alice action=login
2026-10-15T15:04:06+00:00 tools-auth LOCKOUT ip=203.0.113.10 user=alice action=login
2026-10-15T15:04:07+00:00 tools-auth FAILURE ip=203.0.113.10 user=- action=register
```

Events: `FAILURE` (bad login / register abuse) and `LOCKOUT` (in-app rate limit already tripped).

The app rotates the file at **5 MiB**: `auth.log` is renamed to `auth.log.1` (previous `.1` deleted) and a new `auth.log` starts. Worst case on disk is about **10 MiB**. Point fail2ban `logpath` at `auth.log` only (not `.1`).

SQLite `login_attempts` rows are pruned to the configured rate-limit window (not kept forever).

## Filter

`/etc/fail2ban/filter.d/tools-omid-dev.conf`:

```ini
[Definition]
failregex = ^\s*\S+ tools-auth (?:FAILURE|LOCKOUT) ip=<HOST>
ignoreregex =
```

Or copy from [docs/fail2ban/filter.d/tools-omid-dev.conf](fail2ban/filter.d/tools-omid-dev.conf).

## Jail

`/etc/fail2ban/jail.d/tools-omid-dev.conf` (adjust `logpath` to your host mount):

```ini
[tools-omid-dev]
enabled  = true
filter   = tools-omid-dev
logpath  = /path/to/tools.omid.dev/storage/logs/auth.log
backend  = auto
maxretry = 10
findtime = 900
bantime  = 3600
```

Example drop-in: [docs/fail2ban/jail.d/tools-omid-dev.conf](fail2ban/jail.d/tools-omid-dev.conf).

Then:

```bash
sudo fail2ban-client reload
sudo fail2ban-client status tools-omid-dev
```

## Related

- In-app throttle: defaults 10 failures / 15 minutes per IP or username; configurable under **Admin → Settings → Security** (unblock there too)
- Edge throttle: nginx `limit_req` on `/login.php` and `/register.php` — [nginx-reverse-proxy.conf.example](nginx-reverse-proxy.conf.example)
