# Dropbox (first cloud provider)

Dropbox is implemented as `App\Cloud\Dropbox\DropboxProvider` and registered in `CloudRegistry` (alongside Google Drive). Each user connects their own Dropbox account. There is no shared access token in `.env`.

Upload jobs are stored in the generic `cloud_jobs` table with `provider = 'dropbox'`. Home uses Save to → per-provider cloud destinations; Account loops registered providers for connect / disconnect / prefs.

## Entrypoints

| Path | Role |
|------|------|
| `/cloud_send.php` | Send an existing host file to the user’s connected cloud provider |
| `/dropbox_send.php` | Legacy alias → `cloud_send.php` |
| `/api/check_cloud_status.php` | Poll cloud job status (`provider` POST field) |
| `/api/check_dropbox_status.php` | Legacy alias → `check_cloud_status.php` |

CLI script and endpoint names (`scripts/ytdl.sh`, `/api/cli_register_and_dropbox.php`, `/dropbox.php`) remain Dropbox-specific as legacy; they resolve the user’s default connected provider via `CloudRegistry`.

## Create a Dropbox app

1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps).
2. Create an app with **Scoped access** and **Full Dropbox** (or App folder if you prefer).
3. Under **Permissions**, enable at least:
   - `account_info.read` (shows Dropbox name/email on Account after connect)
   - `files.content.write`
   - `files.content.read` (required for conflict checks: rename / overwrite / skip)
   - `files.metadata.read` (path existence checks)
4. Under **OAuth 2**, add the redirect URI:

   `{APP_BASE_URL}/oauth/dropbox/callback.php`

   Example: `https://tools.omid.dev/oauth/dropbox/callback.php`

5. Copy **App key** and **App secret** into **Admin → Settings → Cloud**.

   `APP_BASE_URL` must be set in `.env` so the OAuth redirect URI matches.

## User flow

1. Sign in → **Account** → **Connect Dropbox**.
2. Approve access in Dropbox.
3. Optionally set Dropbox **defaults** on Account: destination folder (blank = root) and conflict policy. Preferred destinations (Local and/or connected clouds) live under Profile.
4. Use **Save to** → Cloud or Disk + Cloud on the home page, **Send to cloud** on My Files, or CLI (`scripts/ytdl.sh`) after the CLI user has connected Dropbox.

## Features after connect

- **Destination folder** — per job (prefilled from Account default; shown when Save to includes cloud).
- **Conflict policy** — rename, overwrite, or skip when the remote path already exists.
- **Disk + Cloud** — keep a host copy and push to Dropbox in one action.
- **Delete after success** — optional checkbox per job (never forced by defaults).
- **Send existing host files** — My Files → Send to cloud (`/cloud_send.php`).
- **History** — URL pull jobs on the History page (expand a row for linked cloud details). Standalone My Files → cloud sends appear there too without duplicating download rows. Active download jobs show on Home while pending/in progress. Users can Remove a finished row to hide it from their History/Home; admins still see those rows under Admin → All history (marked Hidden).

## How uploads work

- Direct HTTP(S) URLs are passed to Dropbox `files/save_url`.
- yt-dlp sources (YouTube, X, Instagram, TikTok, Vimeo, Reddit, SoundCloud, Mixcloud, Bandcamp, generic), Disk + Cloud, My Files sends, and CLI downloads land in `storage/temp`, get a public share URL (`/s/{token}`), then that URL is sent to Dropbox so Dropbox can fetch the file.
- Job rows live in `cloud_jobs` (`provider = dropbox`). Status polling updates them; if the job opted into delete-after, the host file is removed on success.

## Re-authorize after permission changes

If you enable new scopes (e.g. `files.content.read` or `account_info.read`) on an existing Dropbox app, users should use Account → **Reconnect Dropbox** so Dropbox shows consent again and grants the new scopes. Disconnect is only needed to fully unlink.

## Disconnect

Account → **Disconnect Dropbox** clears stored tokens for that user.
