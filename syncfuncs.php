<?php
/**
 * syncfuncs.php - PHP port of the sync-service helper logic.
 *
 * Pure helpers (title normalization, episode-number stripping, proxied-URL
 * decoding, tolerant title matching) plus the per-podcast state/detail readers
 * and the push-side resolution functions. Mirrors Pocket-Casts/sync-service/
 * sync_service.py. No output/side effects on include, so it is unit-testable
 * from the CLI and reusable by sync.php.
 *
 * Caches are per-request (function statics). The Python service keeps a per-worker
 * TTL cache across requests; here each request is independent, which yields
 * identical response content and is simply fresher (never serves stale-by-TTL data).
 */

require_once __DIR__ . '/common.php';   // base64url_decode, etc. (defs only)

// Playing-status vocabulary (matches Pocket Casts / Episode.PlayingStatus)
if (!defined('PC_UNPLAYED'))    define('PC_UNPLAYED', 0);
if (!defined('PC_IN_PROGRESS')) define('PC_IN_PROGRESS', 2);
if (!defined('PC_PLAYED'))      define('PC_PLAYED', 3);

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

/** Everything before the first '?' (or the whole string). Mirrors _strip_query. */
function pc_strip_query($url) {
    if ($url === null || $url === '') return $url;
    $i = strpos($url, '?');
    return $i === false ? $url : substr($url, 0, $i);
}

/**
 * Normalize a title: HTML-unescape, lowercase, collapse whitespace.
 * Mirrors the Python server's _norm_title (html.unescape + lower + split/join).
 */
function pc_norm_title($t) {
    if ($t === null) return '';
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = mb_strtolower($t, 'UTF-8');
    $parts = preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY);
    return $parts ? implode(' ', $parts) : '';
}

/**
 * Drop a leading episode-number prefix ("episode 512: the fifth gate" ->
 * "the fifth gate"). Input is an already-normalized title. Returns "" when no
 * letters remain. Mirrors _strip_episode_no / the client's stripEpisodeNo.
 */
function pc_strip_episode_no($nt) {
    $s = preg_replace(
        '/^(episode|ep|part|pt|chapter|ch|no|number)?\s*#?\s*\d+\s*[:.)\-\x{2013}\x{2014}]\s+/u',
        '', $nt === null ? '' : $nt);
    if ($s === null) $s = '';
    return preg_match('/\p{L}/u', $s) ? $s : '';
}

/**
 * Tolerant podcast/feed title match: exact after normalization, else a prefix
 * match (either direction) so catalog suffixes differ gracefully. Requires a
 * non-trivial (>= 4 char) title for the prefix case. Mirrors _title_matches.
 */
function pc_title_matches($want, $other) {
    if (!$want || !$other) return false;
    $w = pc_norm_title($want);
    $o = pc_norm_title($other);
    if ($w === $o) return true;
    if (strlen($w) >= 4 && (strncmp($o, $w, strlen($w)) === 0 || strncmp($w, $o, strlen($o)) === 0)) {
        return true;
    }
    return false;
}

/**
 * Recover the original URL from a podcast-service proxy URL (tiny.php?url=<b64>,
 * mp3.php?<b64>, image.php?img=<b64>). The blob is base64url-encoded by
 * common.php's base64url_encode, so base64url_decode is its exact inverse
 * (correctly handling '-'/'_' and missing padding). Mirrors _decode_proxied_url;
 * returns the decoded URL or null when $url is not a decodable proxy URL.
 */
function pc_decode_proxied_url($url) {
    if (!$url || strpos($url, 'webosarchive.org') === false) return null;
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === false) return null;
    $isProxy = false;
    foreach (array('tiny.php', 'mp3.php', 'image.php') as $p) {
        if (substr($path, -strlen($p)) === $p) { $isProxy = true; break; }
    }
    if (!$isProxy) return null;

    $query = parse_url($url, PHP_URL_QUERY);
    if ($query === null || $query === false || $query === '') return null;
    // tiny.php uses ?url=<b64>; mp3.php uses the bare query as <b64>. (image.php
    // uses ?img=, but images are never a sync join key, so — like the Python
    // service — we don't special-case it: its bare-query blob simply won't decode.)
    parse_str($query, $qs);
    if (!empty($qs['url'])) {
        $blob = $qs['url'];
    } else {
        $parts = explode('&', $query, 2);
        $blob = $parts[0];
    }
    if (!$blob) return null;
    $blob = str_replace(' ', '+', $blob);          // a '+' can arrive space-decoded
    $decoded = base64url_decode($blob);
    if ($decoded === false || $decoded === '') return null;
    return strncmp($decoded, 'http', 4) === 0 ? $decoded : null;
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

/** Session token from ?token=, X-Sync-Token, or a Bearer Authorization header. */
function pc_extract_token() {
    if (!empty($_GET['token'])) return $_GET['token'];
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) {
        if (strcasecmp($k, 'X-Sync-Token') === 0 && $v !== '') return $v;
    }
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($auth === '') {
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
        }
    }
    if (strncmp($auth, 'Bearer ', 7) === 0) {
        $t = substr($auth, 7);
        if ($t !== '') return $t;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Per-request memo for getPodcast (harmless; output identical to uncached)
// ---------------------------------------------------------------------------
function pc_get_podcast($pc, $uuid) {
    static $memo = array();
    if (!array_key_exists($uuid, $memo)) {
        $memo[$uuid] = $pc->getPodcast($uuid);
    }
    return $memo[$uuid];
}

// ---------------------------------------------------------------------------
// Pull-side readers
// ---------------------------------------------------------------------------

/**
 * Per-podcast episode state: uuid => array(status, pos, starred, duration).
 * Only episodes the user has interacted with appear. Mirrors _episode_state.
 */
function pc_episode_state($pc, $podcast_uuid) {
    $resp = $pc->makeReq($pc->api() . '/user/podcast/episodes', 'JSON',
                         array('uuid' => $podcast_uuid));
    $j = is_array($resp['json']) ? $resp['json'] : array();
    $out = array();
    if (isset($j['episodes']) && is_array($j['episodes'])) {
        foreach ($j['episodes'] as $e) {
            $pos      = isset($e['playedUpTo'])    ? (int) $e['playedUpTo']    : 0;
            $status   = isset($e['playingStatus']) ? (int) $e['playingStatus'] : 0;
            $duration = isset($e['duration'])      ? (int) (float) $e['duration'] : 0;
            $starred  = !empty($e['starred']);
            $out[$e['uuid']] = array($status, $pos, $starred, $duration);
        }
    }
    return $out;
}

/**
 * (feed_url, details) for a podcast, where details is
 * uuid => array(title, enclosureUrl, publishedDate[:10]). Cached per request.
 * Mirrors _podcast_detail_index.
 */
function pc_podcast_detail_index($pc, $podcast_uuid) {
    static $cache = array();
    if (array_key_exists($podcast_uuid, $cache)) return $cache[$podcast_uuid];

    $url = $pc->cache() . '/podcast/full/' . $podcast_uuid . '/0/3/1000';
    $resp = $pc->makeReq($url, 'GET');
    $j = is_array($resp['json']) ? $resp['json'] : array();
    $raw = isset($j['podcast']) && is_array($j['podcast']) ? $j['podcast'] : array();

    $feed_url = isset($raw['url']) ? $raw['url'] : '';
    $details = array();
    if (isset($raw['episodes']) && is_array($raw['episodes'])) {
        foreach ($raw['episodes'] as $e) {
            $published = isset($e['published']) && $e['published'] !== null ? $e['published'] : '';
            $details[$e['uuid']] = array(
                isset($e['title']) ? $e['title'] : '',
                isset($e['url']) ? $e['url'] : '',
                substr($published, 0, 10),
            );
        }
    }
    $cache[$podcast_uuid] = array($feed_url, $details);
    return $cache[$podcast_uuid];
}

// ---------------------------------------------------------------------------
// Push-side resolution
// ---------------------------------------------------------------------------

/** Best-effort RSS feed URL for a subscribed podcast (cached). Mirrors _resolve_feed_url. */
function pc_resolve_feed_url($pc, $pod) {
    static $cache = array();
    $key = 'url:' . $pod['uuid'];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $full = pc_get_podcast($pc, $pod['uuid']);
        $url = isset($full['url']) ? $full['url'] : '';
    } catch (Exception $e) {
        $url = '';
    }
    $cache[$key] = $url;
    return $url;
}

/**
 * Resolve an RSS feed URL to a Pocket Casts podcast UUID.
 * Order: cache -> user's subscriptions (exact real-url, else tolerant title) ->
 * catalog search. Mirrors _resolve_podcast_uuid.
 */
function pc_resolve_podcast_uuid($pc, $feed_url, $title = null) {
    static $cache = array();
    if (!$feed_url) return null;
    if (array_key_exists($feed_url, $cache)) return $cache[$feed_url];

    $real_feed = pc_decode_proxied_url($feed_url);
    if ($real_feed === null) $real_feed = $feed_url;

    // The user's own subscriptions first.
    try {
        $title_fallback = null;
        foreach ($pc->getSubscribedPodcasts() as $pod) {
            $full = pc_get_podcast($pc, $pod['uuid']);
            if (!empty($full['url']) && ($full['url'] === $feed_url || $full['url'] === $real_feed)) {
                $cache[$feed_url] = $pod['uuid'];
                return $pod['uuid'];
            }
            if ($title && $title_fallback === null && pc_title_matches($title, $pod['title'])) {
                $title_fallback = $pod['uuid'];
            }
        }
        if ($title_fallback !== null) {
            $cache[$feed_url] = $title_fallback;
            return $title_fallback;
        }
    } catch (Exception $e) {
        // fall through to search
    }

    // Catalog search fallback.
    if ($title) {
        try {
            $title_hit = null;
            $hits = $pc->searchPodcasts($title);
            $hits = array_slice($hits, 0, 8);
            foreach ($hits as $hit) {
                if ($title_hit === null && pc_title_matches($title, $hit['title'])) {
                    $title_hit = $hit['uuid'];
                }
                $full = pc_get_podcast($pc, $hit['uuid']);
                if (!empty($full['url']) && ($full['url'] === $feed_url || $full['url'] === $real_feed)) {
                    $cache[$feed_url] = $hit['uuid'];
                    return $hit['uuid'];
                }
            }
            if ($title_hit !== null) {
                $cache[$feed_url] = $title_hit;
                return $title_hit;
            }
        } catch (Exception $e) {
            // give up
        }
    }
    return null;
}

/**
 * Resolve a device episode to a Pocket Casts episode UUID within a podcast.
 * Order: exact enclosure URL, query-stripped enclosure URL, normalized title,
 * episode-number-stripped title. Mirrors _resolve_episode_uuid.
 */
function pc_resolve_episode_uuid($pc, $podcast_uuid, $enclosure_url, $episode_title = null) {
    static $cache = array();
    if (!array_key_exists($podcast_uuid, $cache)) {
        $by_url = array();
        $by_stripped = array();
        $by_title = array();
        $title_strip = array();   // stripped-title -> uuid; null marks ambiguous
        foreach ($pc->getPodcastEpisodes($podcast_uuid) as $e) {
            if (!empty($e['url'])) {
                $by_url[$e['url']] = $e['uuid'];
                $by_stripped[pc_strip_query($e['url'])] = $e['uuid'];
            }
            if (!empty($e['title'])) {
                $nt = pc_norm_title($e['title']);
                $by_title[$nt] = $e['uuid'];
                $st = pc_strip_episode_no($nt);
                if ($st !== '' && $st !== $nt) {
                    $title_strip[$st] = array_key_exists($st, $title_strip) ? null : $e['uuid'];
                }
            }
        }
        // Fold unambiguous stripped keys in as title fallbacks, never shadowing
        // an exact title.
        foreach ($title_strip as $k => $v) {
            if ($v !== null && !array_key_exists($k, $by_title)) {
                $by_title[$k] = $v;
            }
        }
        $cache[$podcast_uuid] = array('url' => $by_url, 'stripped' => $by_stripped, 'title' => $by_title);
    }
    $index = $cache[$podcast_uuid];

    $real_enc = pc_decode_proxied_url($enclosure_url);
    if ($real_enc === null) $real_enc = $enclosure_url;

    foreach (array($enclosure_url, $real_enc) as $candidate) {
        if ($candidate && isset($index['url'][$candidate])) return $index['url'][$candidate];
    }
    foreach (array($enclosure_url, $real_enc) as $candidate) {
        if ($candidate) {
            $sq = pc_strip_query($candidate);
            if (isset($index['stripped'][$sq])) return $index['stripped'][$sq];
        }
    }
    if ($episode_title) {
        $nt = pc_norm_title($episode_title);
        if (isset($index['title'][$nt])) return $index['title'][$nt];
        $st = pc_strip_episode_no($nt);
        if ($st !== '' && isset($index['title'][$st])) return $index['title'][$st];
    }
    return null;
}
