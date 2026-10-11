# Dropbox (first cloud provider)

Dropbox is the first implemented cloud provider. Each user connects their own Dropbox account. There is no shared access token in `.env`.

Upload jobs are stored in the generic `cloud_jobs` table with `provider = 'dropbox'`. Home/My Files actions say “Save To Cloud”; Account still uses **Connect Dropbox**.

## Create a Dropbox app

1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps).
2. Create an app with **Scoped access** and **Full Dropbox** (or App folder if you prefer).
3. Under **Permissions**, enable at least:
   - `files.content.write`
   - `files.content.read` (required for conflict checks: rename / overwrite / skip)
4. Under **OAuth 2**, add the redirect URI:

   `{APP_BASE_URL}/oauth/dropbox/callback.php`

   Example: `https://tools.omid.dev/oauth/dropbox/callback.php`

5. Copy **App key** and **App secret** into `.env`:

```env
DROPBOX_APP_KEY=...
DROPBOX_APP_SECRET=...
APP_BASE_URL=https://tools.omid.dev
```

## User flow

1. Sign in → **Account** → **Connect Dropbox**.
2. Approve access in Dropbox.
3. Optionally set **defaults** on Account: preferred action, destination folder, conflict policy.
4. Use **Save To Cloud** / **Save To Disk + Cloud** on the home page, **Send to cloud** on My Files, or CLI (`scripts/ytdl.sh`) after the CLI user has connected Dropbox.

## Features after connect

- **Destination folder** — per job (prefilled from Account default).
- **Conflict policy** — rename, overwrite, or skip when the remote path already exists.
- **Disk + Cloud** — keep a host copy and push to Dropbox in one action.
- **Delete after success** — optional checkbox per job (never forced by defaults).
- **Send existing host files** — My Files → Send to cloud.
- **Recent cloud uploads** — history with live status on Home and My Files.

## How uploads work

- Direct HTTP(S) URLs are passed to Dropbox `files/save_url`.
- YouTube, Disk + Cloud, My Files sends, and CLI downloads land in `storage/temp`, get a public share URL (`/s/{token}`), then that URL is sent to Dropbox so Dropbox can fetch the file.
- Job rows live in `cloud_jobs` (`provider = dropbox`). Status polling updates them; if the job opted into delete-after, the host file is removed on success.

## Re-authorize after permission changes

If you enable `files.content.read` on an existing Dropbox app, users should **Disconnect** and **Connect** again so the new scope is granted.

## Disconnect

Account → **Disconnect Dropbox** clears stored tokens for that user.
