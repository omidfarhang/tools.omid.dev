# Google Drive (cloud provider)

Google Drive is implemented as `App\Cloud\GoogleDrive\GoogleDriveProvider` and registered in `CloudRegistry` alongside Dropbox. Each user connects their own Google account. There is no shared access token in `.env`.

Upload jobs are stored in the generic `cloud_jobs` table with `provider = 'google_drive'`.

## Create a Google Cloud OAuth client

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create or select a project.
3. Enable the **Google Drive API** for that project (**APIs & Services → Library**).
4. Under **APIs & Services → Credentials**, configure the **OAuth consent screen** (External or Internal).
5. Create an **OAuth 2.0 Client ID** of type **Web application**.
6. Under **Authorized redirect URIs**, add:

   `{APP_BASE_URL}/oauth/google_drive/callback.php`

   Example: `https://tools.omid.dev/oauth/google_drive/callback.php`

7. Copy **Client ID** and **Client secret** into **Admin → Settings → Cloud**.

   `APP_BASE_URL` must be set in `.env` so the OAuth redirect URI matches.

### Scopes requested

- `https://www.googleapis.com/auth/drive.file` — create/manage files and folders opened or created by this app
- `userinfo.email` / `userinfo.profile` — show account name/email on Account → Cloud after connect

If the app is in testing mode on an External consent screen, add each user’s Google account as a test user.

## User flow

1. Sign in → **Account** → **Cloud** → **Connect Google Drive**.
2. Approve access in Google.
3. Optionally set cloud **defaults** on Account → Cloud: destination folder (blank = My Drive root) and conflict policy.
4. Use **Save to** → Google Drive (or Disk + Google Drive) on the home page, **Send to cloud** on My Files (choose provider when more than one is connected), or CLI after the CLI user has connected Google Drive.

## How uploads work

Unlike Dropbox `files/save_url`, Google Drive has no remote URL fetch. The host uploads bytes with a resumable upload: staged host files are read from disk; direct HTTP(S) cloud-only jobs are fetched by the host then uploaded. Folder paths are created under My Drive as needed (app-managed folders via `drive.file`).

Job rows live in `cloud_jobs` (`provider = google_drive`). Status is usually `complete` when `startJob` returns; polling still works for the monitor UI.

## Disconnect

Account → Cloud → **Disconnect Google Drive** clears stored tokens for that user.
