# Google Calendar — one-time setup

This page is for whoever installs and runs Lavoro. Users never see any of this;
they only click "Connect Google Calendar".

You create one Google project for the whole installation. The credentials go in
`.env` and are shared by all companies using this installation. The connection
itself is personal: each user connects their own calendar, within their own
company.

## 1. Create a Google Cloud project

1. Go to https://console.cloud.google.com.
2. Create a new project (or use an existing one for "Lavoro").

## 2. Enable Google Calendar API

1. APIs & Services → Library.
2. Search "Google Calendar API".
3. Click Enable.

## 3. Configure the OAuth consent screen

1. APIs & Services → OAuth consent screen.
2. User type: **External**.
3. Fill in:
   - App name: `Lavoro`
   - Support email: your email
   - App logo: optional
   - Developer contact: your email
4. Scopes — add these:
   - `https://www.googleapis.com/auth/calendar`
   - `openid`
   - `email`
   - `profile`
5. Test users — add the Google accounts you want to use during development.

While the screen is in **Testing** mode, only test users (max ~100) can connect.

## 4. Create OAuth credentials

1. APIs & Services → Credentials → Create credentials → OAuth client ID.
2. Application type: **Web application**.
3. Authorized redirect URIs:
   - `http://127.0.0.1:8199/google/oauth/callback` (local development, the port `dev.sh` uses)
   - `https://<your-prod-domain>/google/oauth/callback` (production, once known)
4. Save. Note the Client ID and Client Secret.

## 5. Configure Lavoro

In `.env`:

```
GOOGLE_CLIENT_ID=<client id from step 4>
GOOGLE_CLIENT_SECRET=<client secret from step 4>
GOOGLE_OAUTH_REDIRECT_URI=http://127.0.0.1:8199/google/oauth/callback
GOOGLE_WEBHOOK_ENABLED=false
GOOGLE_WEBHOOK_URL=
GOOGLE_SYNC_LOOKBACK_DAYS=365
GOOGLE_DEFAULT_EVENT_TYPE_ID=
```

Run `php artisan config:clear`.

## 6. Run the supporting processes

The sync is queued work and scheduled work, so both have to be running.

In development, `./scripts/tenancy/dev.sh` starts the app, both workers and Vite
together — see [getting started](../development/getting-started.md).

In production they are systemd units and a cron line, installed by
`sudo scripts/tenancy/setup-workers.sh`: an ordinary worker, a second one for
provisioning, and `* * * * * php artisan schedule:run`. What they are and how to
check them is in [the runbook](../operations/runbook.md#what-has-to-be-running).

Changes are fetched per company. Every five minutes the scheduler starts one
background job per company; it never runs a single query across all companies at
once.

## 7. Enable webhooks (optional, for near-real-time sync)

Without webhooks, the job that runs every five minutes picks up all incoming
changes. That is slower, but it works.

To enable webhooks:

1. Run a public HTTPS endpoint pointing at your Lavoro app (production URL or `ngrok http 8000` in dev).
2. Verify the domain in Google Search Console.
3. Add it under APIs & Services → Domain verification.
4. In `.env`:

```
GOOGLE_WEBHOOK_ENABLED=true
GOOGLE_WEBHOOK_URL=https://<your-domain>/google/webhook
```

5. Run `php artisan config:clear`. Existing integrations register watches on next reconnect; new integrations register them immediately on connect.

## 8. Going live to all customers

The `auth/calendar` scope is "sensitive" by Google's classification. While the OAuth consent screen is in **Testing** mode, only pre-listed test users can connect.

To allow any user to connect:

1. OAuth consent screen → **Publish app**.
2. Submit for **verification**:
   - Provide a homepage URL.
   - Provide a privacy policy URL.
   - Record a short demo video showing how Lavoro uses the calendar scope.
3. Wait for Google's review (typically 2–6 weeks).

Plan this as a separate milestone — don't block the feature rollout on it. Internal users can connect immediately as test users.

## 9. Disconnecting

When a user disconnects their calendar, `TeardownIntegrationJob` runs. It stops
all watch channels, deletes the Google calendars Lavoro created, removes
Lavoro's own database rows for the connection, and revokes the refresh token.

The appointments in Lavoro itself are not touched.

If any of that fails, for example because Google is unreachable, the user can
simply connect again. The old connection details are overwritten.

To force-disconnect a user as admin:

```
php artisan tinker
>>> App\Jobs\Google\TeardownIntegrationJob::dispatch(App\Models\User::find($id)->googleCalendarIntegration->id);
```

## Next

- [The runbook](../operations/runbook.md) — the cron job and the workers this
  synchronisation depends on
- [Troubleshooting](../operations/troubleshooting.md) — when background work is
  not happening
