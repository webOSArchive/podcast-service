<?php
include ("common.php");

//Determine setting for returning file path
$hideFilepath = true;
include ("secrets.php");

//Handle more specific queries
$mp3_info = null;
if (isset($_GET['url']) && $_GET['url'] != "") {
    $mp3_info = $_GET['url'];
} elseif (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != "") {
    $mp3_info = $_SERVER['QUERY_STRING'];
} else {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    die("Missing URL parameter");
}

$cacheID = $mp3_info;
$url = base64url_decode($cacheID);
if (!validate_url($url)) {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    die("Invalid URL: " . $url);
}
$cacheID = md5($url);

//Prepare the cache
$path = "cache";
if (!file_exists($path)) {
    mkdir($path, 0755, true);
}
//Create safe filename
$cacheID = safe_cache_filename($cacheID);

//Fetch and cache the file if its not already cached  
$path = $path . "/" . $cacheID . ".mp3";
if (!file_exists($path) || filesize($path) < 1) {
    $fh = fopen($path, "w");
    if (!$fh) {
        header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
        die("Unable to create cache file");
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'webOSPodcastDirectory/1.0');
    curl_setopt($ch, CURLOPT_MAXFILESIZE, 100*1024*1024);
    curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    if (curl_errno($ch)) {
        fclose($fh);
        unlink($path);
        curl_close($ch);
        header($_SERVER["SERVER_PROTOCOL"] . " 502 Bad Gateway");
        die("Fetch error: " . curl_error($ch));
    }
    
    if ($httpCode !== 200) {
        fclose($fh);
        unlink($path);
        curl_close($ch);
        header($_SERVER["SERVER_PROTOCOL"] . " 502 Bad Gateway");
        die("HTTP error: " . $httpCode);
    }
    
    if ($contentType && strpos($contentType, 'audio/') !== 0) {
        fclose($fh);
        unlink($path);
        curl_close($ch);
        header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
        die("Invalid content type");
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
