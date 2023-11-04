<?php
/* See https://github.com/Podcastindex-org/example-code for more examples */

//Figure the query
$maxResults = 15;
if (isset($_GET['q'])) {
	$the_query = $_GET['q'];
	if (isset($_GET['max'])) {
		$the_query = $the_query . "&max=" . $_GET['max'];
	}

} else {
	$the_query = $_SERVER['QUERY_STRING'];
}
$original_query = strtolower($the_query);
$the_query = str_replace(" ", "+", $the_query);
$the_query = "https://api.podcastindex.org/api/1.0/search/byterm?q=" . $the_query;

//API Credentials
include ("secrets.php");
$apiHeaderTime = time();
//Hash them to get the Authorization token
$hash = sha1($apiKey.$apiSecret.$apiHeaderTime);

//Set the required headers
$headers = [
    "User-Agent: webOSPodcastDirectory/1.0",
    "X-Auth-Key: $apiKey",
    "X-Auth-Date: $apiHeaderTime",
    "Authorization: $hash"
];

//Make the request to an API endpoint
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $the_query);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

//Collect and show the results
header('Content-Type: application/json');
$response = curl_exec ($ch);
curl_close ($ch);

$response_obj = json_decode($response);

//Inject any restored feeds
include ("restorations.php");
if (isset($restorations)) {
	foreach (array_keys($restorations) as $restoration) {
		$thisTitle = strtolower($restoration);
		if (strpos($thisTitle, $original_query) || strpos($original_query, $thisTitle)) {
			array_push($response_obj->feeds, $restorations[$restoration]);
		}
	}
} 	

//Inject any modified feeds
include ("substitutions.php");
if (isset($substitutions)) {
	foreach ($response_obj->feeds AS $feed){
		if (isset($substitutions[$feed->url])) {
			$new_feed = $substitutions[$feed->url];
			foreach ($new_feed as $key => $value) {
				$feed->$key = $value;
			}
		}
	}
	$response = json_encode($response_obj);
} 	
print_r($response);

?>
