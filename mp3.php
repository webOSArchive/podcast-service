<?php
include ("common.php");

//Determine setting for returning file path
$hideFilepath = true;
include ("secrets.php");

//Handle more specific queries
$mp3_info = null;
if (isset($_GET['url']) && $_GET['url'] != "") {
    $mp3_info = $_GET['url'];
} elseif (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != "") { //Accept a blanket query
    $mp3_info = $_SERVER['QUERY_STRING'];
} else {    //Deal with no usable request
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    die;
}
$cacheID = $mp3_info;
$url = base64url_decode($cacheID);

//Prepare the cache
$path = "cache";
if (!file_exists($path)) {
    mkdir($path, 0755, true);
}
//Make sure our filename isn't too long
$fullWritePath = getcwd() . "/" . $path . "/";
$availLength = 250 - strlen($fullWritePath);
$startPos = strlen($cacheID) - $availLength;
if ($startPos < 0)
    $startPos = 0;
$cacheID = substr($cacheID, $startPos);

//Fetch and cache the file if its not already cached
$path = $path . "/" . $cacheID . ".mp3";
if (!file_exists($path) || filesize($path) < 1) {
	$fh = fopen($path, "w") or die("ERROR openining " . $url);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_FILE, $fh);
	curl_setopt($ch, CURLOPT_TIMEOUT, 720);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_exec($ch);
	if (curl_errno($ch)) {
		echo "Fetch error: " . curl_error($ch);
	}
	curl_close($ch);
	fclose($fh);
}
if ($hideFilepath) {
	$useXSendFile = false;
	if (function_exists('apache_get_modules')) {
		try {
			// try to find apache xsendfile
			if (in_array('mod_xsendfile', apache_get_modules())) {
				$useXSendFile = true;
			}
		} catch (Exception $ex) {
			//Assuming nginx, per readme
		}
	}

	// send the right headers
	header("Content-Type: audio/mpeg3");
	header("Content-Length: " . filesize($path));
	if ($useXSendFile) {
		header('X-Sendfile: ' . $path);
		exit;
	} else {
		header("X-Accel-Redirect: /" . $path);
		exit;
	}
	/* This strategy could potentially work on other web servers, but most retro devices don't like it...
	// dump the file and stop the script
	$fp = fopen($path, 'r');
	fpassthru($fp);
	exit;
	*/
} else {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        $link = "https";
    else 
	$link = "http";
    $link .= "://";
    $link .= $_SERVER['HTTP_HOST'];
    $link .= $_SERVER['REQUEST_URI'];
	$link = str_replace("mp3.php?", "cache/", $link);
	$link .= $cacheID . ".mp3";
	header("Location: " . $link);
}

?>
