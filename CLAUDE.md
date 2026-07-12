# podcast-service — Claude context

PHP backend for the webOS **drPodder** podcast app (github.com/codepoet80/webos-drpodder).
Two responsibilities in one PHP app, deployed at `podcasts.webosarchive.org`:

1. **Catalog / proxy** (original, PodcastIndex-backed): `search.php`, `getdetailby.php`,
   `detail.php`, plus the tiny-feed/asset proxies `tiny.php` (RSS), `mp3.php` (audio),
   `image.php`. These let retro webOS devices (no modern TLS) fetch feeds/media via the
   server. `common.php` = shared helpers (`base64url_encode/decode`, URL/query validation).
   Secrets in `secrets.php` (gitignored; copy from `secrets-example.php`).
2. **Pocket Casts playback sync** (added 2026-07): `pocketcasts.php` + `syncfuncs.php` +
   `sync.php`. OPTIONAL — inert until the user logs into Pocket Casts in drPodder.

> History: sync began as a Python/Flask service wrapping a Python `pocketcasts` library
> (in a now-deleted sibling `Pocket-Casts/` folder). It was ported to PHP and the Python
> side was **sunset/deleted** — the PHP files below are the sole implementation. Don't look
> for `sync_service.py`/gunicorn/systemd/port-8002; they're gone.

## Sync architecture

| File | Role |
|------|------|
| `pocketcasts.php` | HTTP client for Pocket Casts (`class Pocketcasts`). curl-based; `login`, `fromToken`, `makeReq`, `getSubscribedPodcasts`, `getPodcast`, `getPodcastEpisodes`, `searchPodcasts`. |
| `syncfuncs.php`   | Pure helpers + resolution/state logic. Include-only, no output. |
| `sync.php`        | Front controller. Dispatches on action from `?action=` / `PATH_INFO` / `/sync/<action>`. |

**Endpoints** (URL-keyed JSON; the session token IS the Pocket Casts bearer token, so the
service is stateless). Token read from `?token=`, `X-Sync-Token`, or `Authorization: Bearer`.

| Method | Path | In | Out |
|--------|------|----|-----|
| GET  | `/sync/health` | — | `{status:"ok"}` |
| POST | `/sync/login` | `{email,password}` | `{status:"ok",token}` / `{status:"error",msg}` |
| GET  | `/sync/pull` | `?token=` | `{status:"ok",episodes:[{feedUrl,enclosureUrl,title,published,playingStatus,playedUpTo,duration,starred}]}` |
| GET  | `/sync/subscriptions` | `?token=` | `{status:"ok",feeds:[{feedUrl,title,uuid}]}` |
| POST | `/sync/push` | `?token=` + `{episodes:[{feedUrl,enclosureUrl,title,episodeTitle,playingStatus,playedUpTo}]}` | `{status:"ok",results:[{enclosureUrl,ok,error?}]}` |

Errors use `{status:"error",msg}` at HTTP 200 (matches the client's expectations).
Playing status vocab: `0`=unplayed, `2`=in-progress, `3`=played.

## Pocket Casts backend facts (the API this talks to)

- Auth: `POST https://api.pocketcasts.com/user/login` JSON `{email,password,scope:"webplayer"}`
  → JWT in `token` (or `accessToken`). Send as `Authorization: Bearer <token>`.
- Three hosts: `api.pocketcasts.com` (authed user data + search + sync mutations),
  `cache.pocketcasts.com` (public podcast detail + episodes, GET, gzipped, 302s to
  podcasts.pocketcasts.com), `static.pocketcasts.com` (public discover JSON).
- **CRITICAL:** the CDN hosts REJECT an `Authorization` header — `makeReq` attaches the
  bearer only when the URL is on the api host (`strncmp($url, api_base)`).
- JSON casing varies: api host is camelCase (`playingStatus`,`playedUpTo`,`episodesSortOrder`),
  cache host is snake_case (`file_type`,`published`). Pull reads what it needs directly.
- Endpoint map: subscribed=`/user/podcast/list` `{v:1}`; per-podcast state (played +
  in-progress, uuid+status+pos+duration, NO title/url)=`/user/podcast/episodes` `{uuid}`;
  search=`/discover/search` `{term}`; full podcast (title/url/all episodes)=`cache…/podcast/full/{uuid}/0/3/1000`;
  push=`/sync/update_episode` `{uuid,podcast,status,position}` (sets status + position at once).
- **Fully-played episodes are ONLY exposed via `/user/podcast/episodes` per podcast** — they
  are absent from history/in_progress/starred. So `pull` iterates each subscribed podcast:
  cross-references `/user/podcast/episodes` (state) against `cache/full` (title/url/published/duration).

## The join problem (why matching is subtle)

drPodder subscribes to **tiny feeds**: `tiny.php` rewrites the RSS URL to
`tiny.php?url=<base64url>&…` and every enclosure to `mp3.php?<base64url>`. Those proxy URLs
never match what Pocket Casts stores. Resolution therefore tries, in order:

- **Podcast** (`pc_resolve_podcast_uuid`): decode the proxied feed URL back to the real RSS
  URL (`pc_decode_proxied_url` via `base64url_decode`) → match against the user's
  subscriptions' real URLs → tolerant title match → catalog search fallback.
- **Episode** (`pc_resolve_episode_uuid`): exact enclosure URL → query-stripped URL →
  normalized episode **title** → title with a leading episode-number prefix stripped
  (`pc_strip_episode_no`, e.g. libsyn "Episode 512: The Fifth Gate" ↔ PC "The Fifth Gate").
  A stripped key never shadows an exact title, and ambiguous stripped keys are dropped.
- `pc_norm_title` = HTML-unescape + lowercase + collapse whitespace (mirrors the client's
  `normTitle`). The client mirror lives in `webos-drpodder/app/models/syncservice-model.js`.

**Intentional divergence from the old Python code:** proxied URLs are decoded with
`base64url_decode` (the correct inverse of `base64url_encode`). The Python service used
standard base64, which silently dropped `-`/`_` and mis-decoded some URLs. PHP resolves
MORE episodes by URL, never fewer.

## Deploy (wosa box, nginx)

Web root is the podcast-service checkout. To ship: `git pull`, ensure the rewrite is present
(see `sync-deploy/nginx.conf.snippet`), reload nginx:

```nginx
location /sync/ { rewrite ^/sync/([a-zA-Z]+)/?$ /sync.php?action=$1 last; return 404; }
```

Smoke test: `curl -A "Mozilla/5.0" https://podcasts.webosarchive.org/sync/health`.
Note: `podcasts.webosarchive.org` is behind Cloudflare and 403s a default WebFetch UA —
use `curl -A "Mozilla/5.0"`.

## Debugging tips

- Push failing with "could not resolve podcast/episode" → a matching problem. Compare the
  real feed/episode titles: PC cache is public at `cache.pocketcasts.com/podcast/full/{uuid}/0/3/1000`
  (302s to a JSON doc, no auth). Check title normalization + episode-number stripping.
- Caveat that is NOT a bug: tiny feeds truncate and some source feeds self-truncate, so older
  played episodes may have no local counterpart to sync — expected.
- Parity harness (from the porting work) lived in a scratchpad, references the now-deleted
  Python service, and can't be re-run as-is. The PHP is the source of truth now; write new
  PHP-only tests (e.g. hit `sync.php?action=…` against a mock upstream via `PC_API_BASE`/
  `PC_CACHE_BASE` env overrides, which `pocketcasts.php` honors) if you need regression checks.

## Test account

Throwaway Pocket Casts creds exist (`curator@webosarchive.org`); password is NOT in the repo —
supply via env when testing. Never hardcode creds.
