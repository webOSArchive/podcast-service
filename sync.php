<?php
/**
 * sync.php - Pocket Casts playback-sync endpoints (PHP).
 *
 * Front controller replacing the Python Flask sync service. Serves the same
 * URL-keyed JSON contract the drPodder client expects:
 *
 *   GET  /sync/health
 *   POST /sync/login          {email, password}            -> {status, token}
 *   GET  /sync/pull?token=..                                -> {status, episodes:[...]}
 *   GET  /sync/subscriptions?token=..                       -> {status, feeds:[...]}
 *   POST /sync/push?token=..  {episodes:[...]}              -> {status, results:[...]}
 *
 * Deploy: rewrite /sync/<action> to this file (see sync-deploy/*). The action is
 * taken from ?action=, else PATH_INFO, else the trailing path segment of the URI,
 * so it works whether the front end rewrites to ?action= or serves it via PATH_INFO.
 */

require_once __DIR__ . '/pocketcasts.php';
require_once __DIR__ . '/syncfuncs.php';

header('Content-Type: application/json');

/** Error envelope matching the podcast-service convention (HTTP 200, like Flask _err). */
function sync_err($msg) {
    echo json_encode(array('status' => 'error', 'msg' => $msg));
    exit;
}

function sync_ok($extra) {
    echo json_encode(array_merge(array('status' => 'ok'), $extra));
    exit;
}

/** Body params: JSON body first (when Content-Type is JSON), then form/query. */
function sync_json_body() {
    static $body = null;
    if ($body !== null) return $body;
    $body = array();
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    return $body;
}

function sync_param($name) {
    $body = sync_json_body();
    if (isset($body[$name]) && $body[$name] !== '') return $body[$name];
    if (isset($_POST[$name]) && $_POST[$name] !== '') return $_POST[$name];
    if (isset($_GET[$name]) && $_GET[$name] !== '') return $_GET[$name];
    return null;
}

// ---------------------------------------------------------------------------
// Determine the action
// ---------------------------------------------------------------------------
$action = null;
if (isset($_GET['action']) && $_GET['action'] !== '') {
    $action = $_GET['action'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $action = ltrim($_SERVER['PATH_INFO'], '/');
} elseif (!empty($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^.*/sync/#', '', $path);   // strip up to and incl. /sync/
    $action = trim($path, '/');
}
$action = strtolower(preg_replace('/[^a-zA-Z]/', '', (string) $action));

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
switch ($action) {

case 'health':
    sync_ok(array());
    break;

case 'login':
    $email    = sync_param('email');
    $password = sync_param('password');
    if (!$email || !$password) {
        sync_err('email and password are required');
    }
    try {
        $pc = Pocketcasts::login($email, $password);
    } catch (Exception $e) {
        sync_err('login failed: ' . $e->getMessage());
    }
    sync_ok(array('token' => $pc->token()));
    break;

case 'pull':
    $token = pc_extract_token();
    if (!$token) sync_err('missing token');
    try {
        $pc = Pocketcasts::fromToken($token);
        $records = array();
        foreach ($pc->getSubscribedPodcasts() as $pod) {
            $state = pc_episode_state($pc, $pod['uuid']);   // uuid => [status,pos,starred,duration]
            if (!$state) continue;
            list($feed_url, $details) = pc_podcast_detail_index($pc, $pod['uuid']);
            foreach ($state as $uuid => $tuple) {
                if (!isset($details[$uuid])) continue;   // episode too old to be in the list
                list($status, $pos, $starred, $duration) = $tuple;
                list($title, $enclosure, $published) = $details[$uuid];
                $records[] = array(
                    'feedUrl'       => $feed_url,
                    'enclosureUrl'  => $enclosure,
                    'title'         => $title,
                    'published'     => $published,
                    'playingStatus' => $status,
                    'playedUpTo'    => $pos,
                    'duration'      => $duration,
                    'starred'       => $starred,
                );
            }
        }
    } catch (Exception $e) {
        sync_err('pull failed: ' . $e->getMessage());
    }
    sync_ok(array('episodes' => $records));
    break;

case 'subscriptions':
    $token = pc_extract_token();
    if (!$token) sync_err('missing token');
    try {
        $pc = Pocketcasts::fromToken($token);
        $feeds = array();
        foreach ($pc->getSubscribedPodcasts() as $pod) {
            $feed_url = pc_resolve_feed_url($pc, $pod);
            $feeds[] = array('feedUrl' => $feed_url, 'title' => $pod['title'], 'uuid' => $pod['uuid']);
        }
    } catch (Exception $e) {
        sync_err('subscriptions failed: ' . $e->getMessage());
    }
    sync_ok(array('feeds' => $feeds));
    break;

case 'push':
    $token = pc_extract_token();
    if (!$token) sync_err('missing token');
    $body = sync_json_body();
    $episodes = isset($body['episodes']) ? $body['episodes'] : array();
    if (!is_array($episodes) || (count($episodes) && array_keys($episodes) !== range(0, count($episodes) - 1))) {
        // Reject non-list (an object/assoc array is not a JSON list).
        sync_err('episodes must be a list');
    }
    $pc = Pocketcasts::fromToken($token);
    $results = array();
    foreach ($episodes as $item) {
        $enclosure = isset($item['enclosureUrl']) ? $item['enclosureUrl'] : null;
        $feed_url  = isset($item['feedUrl']) ? $item['feedUrl'] : null;
        try {
            $podcast_uuid = pc_resolve_podcast_uuid($pc, $feed_url,
                isset($item['title']) ? $item['title'] : null);
            if (!$podcast_uuid) {
                throw new Exception('could not resolve podcast for feed ' . $feed_url);
            }
            $episode_uuid = pc_resolve_episode_uuid($pc, $podcast_uuid, $enclosure,
                isset($item['episodeTitle']) ? $item['episodeTitle'] : null);
            if (!$episode_uuid) {
                throw new Exception('could not resolve episode for ' . $enclosure);
            }
            $status   = isset($item['playingStatus']) ? (int) $item['playingStatus'] : PC_UNPLAYED;
            $position = isset($item['playedUpTo']) ? (int) $item['playedUpTo'] : 0;
            $resp = $pc->makeReq($pc->api() . '/sync/update_episode', 'JSON', array(
                'uuid' => $episode_uuid, 'podcast' => $podcast_uuid,
                'status' => $status, 'position' => $position,
            ));
            if ($resp['status'] != 200) {
                throw new Exception('pocketcasts returned ' . $resp['status']);
            }
            $results[] = array('enclosureUrl' => $enclosure, 'ok' => true);
        } catch (Exception $e) {
            $results[] = array('enclosureUrl' => $enclosure, 'ok' => false, 'error' => $e->getMessage());
        }
    }
    sync_ok(array('results' => $results));
    break;

default:
    sync_err('unknown action');
}
