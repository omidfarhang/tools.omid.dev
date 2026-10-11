# Dropbox integration (per-user OAuth)

Each user connects their own Dropbox account. There is no shared access token in `.env`.

## Create a Dropbox app

1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps).
2. Create an app with **Scoped access** and **Full Dropbox** (or App folder if you prefer).
3. Under **Permissions**, enable at least:
   - `files.content.write`
   - `files.content.read` (optional)
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
3. Use **Save To Dropbox** on the home page, or CLI (`scripts/ytdl.sh`) after the CLI user has connected Dropbox.

## How uploads work

- Direct HTTP(S) URLs are passed to Dropbox `files/save_url`.
- YouTube (and CLI) downloads land in `storage/temp`, get a public share URL (`/s/{token}`), then that URL is sent to Dropbox so Dropbox can fetch the file.

## Disconnect

Account → **Disconnect Dropbox** clears stored tokens for that user.
