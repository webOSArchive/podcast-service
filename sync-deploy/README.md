# Pocket Casts sync — PHP implementation

`sync.php` (with `pocketcasts.php` + `syncfuncs.php`) is a drop-in PHP replacement
for the Python/Flask sync service in `Pocket-Casts/sync-service/`. Same URL-keyed
JSON contract, same behavior — it just runs inside the existing PHP catalog service
instead of a separate gunicorn process, so there's no second runtime, port, venv,
or systemd unit to keep alive.

## Files

| File | Role |
|------|------|
| `pocketcasts.php` | Port of the `pocketcasts` Python library (HTTP client: login, token auth, subscribed list, podcast/episode fetch, search). |
| `syncfuncs.php`   | Port of the sync helper logic (title/URL normalization, proxied-URL decode, push-side resolution, pull-side state). Include-only, no output. |
| `sync.php`        | Front controller dispatching the endpoints. |

Depends only on stock PHP (`curl`, `json`, `mbstring`) — all already used by the
catalog service. `common.php` supplies `base64url_decode`.

## Endpoints (unchanged contract)

| Method | Path | Body / query | Response |
|--------|------|--------------|----------|
| GET  | `/sync/health` | — | `{status:"ok"}` |
| POST | `/sync/login` | JSON `{email,password}` | `{status:"ok",token}` or `{status:"error",msg}` |
| GET  | `/sync/pull` | `?token=` | `{status:"ok",episodes:[{feedUrl,enclosureUrl,title,published,playingStatus,playedUpTo,duration,starred}]}` |
| GET  | `/sync/subscriptions` | `?token=` | `{status:"ok",feeds:[{feedUrl,title,uuid}]}` |
| POST | `/sync/push` | `?token=` + JSON `{episodes:[{feedUrl,enclosureUrl,title,episodeTitle,playingStatus,playedUpTo}]}` | `{status:"ok",results:[{enclosureUrl,ok,error?}]}` |

The session token IS the Pocket Casts bearer token (service stays stateless).
Token is read from `?token=`, `X-Sync-Token`, or `Authorization: Bearer`.

## Deploy

1. The three PHP files live in the web root next to `search.php` etc.
2. Add the rewrite so `/sync/<action>` maps to `sync.php?action=<action>`:
   - nginx: see `nginx.conf.snippet`
   - Apache: see `apache.conf.snippet`
3. **Decommission the Python service**: remove the old `/sync/` reverse-proxy
   (the `proxy_pass … :8001/8002` / `ProxyPass /sync/`), then
   `systemctl stop pocketcasts-sync && systemctl disable pocketcasts-sync`.

No client change: drPodder keeps calling `http://podcasts.webosarchive.org/sync/…`.

## Parity

Verified byte-for-byte against the Python service by driving both against a mock
Pocket Casts upstream (login/pull/push/subscriptions, incl. error envelopes,
proxied tiny.php/mp3.php URL decoding, episode-number-prefix matching,
stripped-title-never-shadows-exact, HTML-entity titles, search fallback) and
diffing responses **and** the resulting `sync/update_episode` calls. Pure helpers
(title normalization, episode-number stripping, tolerant title match) match across
unicode/entity/em-dash/emoji inputs.

### One intentional improvement

`syncfuncs.php` decodes proxied `tiny.php`/`mp3.php` URLs with `base64url_decode`
(the exact inverse of how those scripts encode). The Python service uses standard
`base64.b64decode`, which silently drops `-`/`_` characters, so it mis-decodes any
proxied URL whose base64 contains them and then falls back to title matching. The
PHP version decodes those correctly, so it resolves **more** episodes by URL. For
blobs without `-`/`_` the two are identical; the PHP path never turns a correct
Python result into a wrong one.
