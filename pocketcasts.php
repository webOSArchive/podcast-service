<?php
/**
 * pocketcasts.php - PHP port of the modernized `pocketcasts` Python library.
 *
 * A thin client for the modern Pocket Casts backend, providing just the calls the
 * sync service needs. Mirrors Pocket-Casts/pocketcasts/api.py:
 *   - api.pocketcasts.com  : authed user data + search + sync mutations (bearer)
 *   - cache.pocketcasts.com : public podcast detail + episode lists (no auth)
 *
 * CRITICAL: the cache/static CDN hosts reject requests that carry an Authorization
 * header, so the bearer token is only attached when the URL is on the API host.
 *
 * Podcasts/episodes are returned as plain associative arrays (only the fields the
 * sync service reads are normalized). The API/CACHE bases are overridable via the
 * PC_API_BASE / PC_CACHE_BASE environment variables so tests can point the client
 * at a mock upstream.
 */

class Pocketcasts {
    private $api;
    private $cache;
    private $token;

    private function __construct($token) {
        $this->token = $token;
        $this->api   = getenv('PC_API_BASE')   ?: 'https://api.pocketcasts.com';
        $this->cache = getenv('PC_CACHE_BASE') ?: 'https://cache.pocketcasts.com';
    }

    public function api()   { return $this->api; }
    public function cache() { return $this->cache; }
    public function token() { return $this->token; }

    /**
     * Authenticate against the modern token API and return a ready client.
     * Throws Exception on failure (mirrors Pocketcasts.__init__ -> _login).
     */
    public static function login($email, $password) {
        $self = new self(null);
        $resp = $self->makeReq($self->api . '/user/login', 'JSON', array(
            'email' => $email, 'password' => $password, 'scope' => 'webplayer',
        ));
        if ($resp['status'] != 200) {
            throw new Exception('Login Failed: ' . $resp['body']);
        }
        $body = is_array($resp['json']) ? $resp['json'] : array();
        $token = null;
        if (isset($body['token']) && $body['token'] !== '') {
            $token = $body['token'];
        } elseif (isset($body['accessToken']) && $body['accessToken'] !== '') {
            $token = $body['accessToken'];
        }
        if (!$token) {
            throw new Exception('Login Failed: no token in response ' . $resp['body']);
        }
        $self->token = $token;
        return $self;
    }

    /** Build a client from an existing bearer token, skipping login. */
    public static function fromToken($token) {
        return new self($token);
    }

    /**
     * Make an HTTP request. $method is 'GET', 'POST', or 'JSON' (POST w/ JSON body).
     * Returns array('status' => int, 'body' => string, 'json' => mixed|null).
     * Throws Exception on transport failure (mirrors requests raising).
     */
    public function makeReq($url, $method = 'GET', $data = null) {
        $headers = array('Accept: application/json');
        // Only api.pocketcasts.com uses bearer auth; the CDN hosts reject it.
        if ($this->token && strncmp($url, $this->api, strlen($this->api)) === 0) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // cache/full 302s to podcasts.pocketcasts.com
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_ENCODING, '');           // accept + transparently gunzip
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);

        if ($method === 'JSON') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data === null ? new stdClass() : $data));
            $headers[] = 'Content-Type: application/json';
        } elseif ($method === 'POST' || $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data === null ? array() : $data));
        }
        // else: default GET

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new Exception('HTTP request failed: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Note: no curl_close() — the CurlHandle is freed when $ch goes out of
        // scope (curl_close() is a deprecated no-op as of PHP 8.5).
        return array(
            'status' => (int) $status,
            'body'   => $body,
            'json'   => json_decode($body, true),
        );
    }

    // ------------------------------------------------------------------
    // Normalization (only the fields the sync service consumes)
    // ------------------------------------------------------------------
    private static function normPodcast($raw) {
        return array(
            'uuid'  => isset($raw['uuid'])  ? $raw['uuid']  : '',
            'title' => isset($raw['title']) ? $raw['title'] : '',
            'url'   => isset($raw['url'])   ? $raw['url']   : '',
        );
    }

    private static function normEpisode($raw) {
        return array(
            'uuid'      => isset($raw['uuid'])      ? $raw['uuid']      : '',
            'title'     => isset($raw['title'])     ? $raw['title']     : '',
            'url'       => isset($raw['url'])       ? $raw['url']       : '',
            'published' => isset($raw['published']) ? $raw['published'] : '',
        );
    }

    private function podcastFull($uuid) {
        $url = $this->cache . '/podcast/full/' . $uuid . '/0/3/1000';
        $resp = $this->makeReq($url, 'GET');
        $j = is_array($resp['json']) ? $resp['json'] : array();
        return isset($j['podcast']) && is_array($j['podcast']) ? $j['podcast'] : array();
    }

    // ------------------------------------------------------------------
    // High-level calls used by the sync service
    // ------------------------------------------------------------------

    /** Subscribed podcasts: list of normalized podcast arrays (uuid/title/url). */
    public function getSubscribedPodcasts() {
        $resp = $this->makeReq($this->api . '/user/podcast/list', 'JSON', array('v' => 1));
        $j = is_array($resp['json']) ? $resp['json'] : array();
        $out = array();
        if (isset($j['podcasts']) && is_array($j['podcasts'])) {
            foreach ($j['podcasts'] as $p) {
                $out[] = self::normPodcast($p);
            }
        }
        return $out;
    }

    /** Full podcast (metadata incl. RSS url) from the cache host, minus episodes. */
    public function getPodcast($uuid) {
        $raw = $this->podcastFull($uuid);
        unset($raw['episodes']);
        $raw['uuid'] = $uuid;
        return self::normPodcast($raw);
    }

    /**
     * All episodes of a podcast (normalized uuid/title/url/published), sorted
     * newest-first to mirror get_podcast_episodes (undated episodes sort first,
     * then by published descending). Sort order only affects last-wins when two
     * episodes share a URL/title, but we match the Python behavior exactly.
     */
    public function getPodcastEpisodes($uuid) {
        $raw = $this->podcastFull($uuid);
        $episodes = array();
        if (isset($raw['episodes']) && is_array($raw['episodes'])) {
            foreach ($raw['episodes'] as $e) {
                $episodes[] = self::normEpisode($e);
            }
        }
        usort($episodes, function ($a, $b) {
            $na = ($a['published'] === '' || $a['published'] === null);
            $nb = ($b['published'] === '' || $b['published'] === null);
            if ($na !== $nb) return $na ? -1 : 1;   // undated first
            if ($na && $nb) return 0;
            return strcmp($b['published'], $a['published']);   // newest first (ISO sorts lexically)
        });
        return $episodes;
    }

    /** Search the catalog: list of normalized podcast arrays. */
    public function searchPodcasts($term) {
        $resp = $this->makeReq($this->api . '/discover/search', 'JSON', array('term' => $term));
        $j = is_array($resp['json']) ? $resp['json'] : array();
        $out = array();
        if (isset($j['podcasts']) && is_array($j['podcasts'])) {
            foreach ($j['podcasts'] as $p) {
                $out[] = self::normPodcast($p);
            }
        }
        return $out;
    }
}
