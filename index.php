<html>
<head>
<link rel="shortcut icon" sizes="256x256" href="assets/icon-256.png">
<link rel="shortcut icon" sizes="196x196" href="assets/icon-196.png">
<link rel="shortcut icon" sizes="128x128" href="assets/icon-128.png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="icon" type="image/png" href="assets/icon.png" >
<link rel="apple-touch-icon" href="assets/icon.png"/>
<link rel="apple-touch-startup-image" href="assets/icon-256.png">
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="white" />

<!-- Social media -->
<meta name="description" content="webOS Archive's Podcast Directory let's you listen to today's podcasts on your retro devices!" />
<link rel="canonical" href="http://podcasts.webosarchive.org" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:title" content="Podcast Directory from webOS Archive" />
<meta property="og:description" content="webOS Archive's Podcast Directory let's you listen to today's podcasts on your retro devices!" />
<meta property="og:url" content="https://www.webosarchive.org" />
<meta property="og:site_name" content="webOS Archive" />
<meta property="article:published_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="article:modified_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="og:image" content="http://podcasts.webosarchive.org/assets/icon-256.png" />
<meta property="og:image:width" content="256" />
<meta property="og:image:height" content="256" />
<meta property="og:image:type" content="image/png" />
<meta name="author" content="webOS Archive" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="Podcast Directory from webOS Archive" />
<meta name="twitter:description" content="webOS Archive's Podcast Directory let's you listen to today's podcasts on your retro devices!" />
<meta name="twitter:image" content="http://podcasts.webosarchive.org/assets/icon-256.png" />
<!-- /Social media -->

<link rel="stylesheet" href="style.css">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=1" />
<title>webOS Podcast Directory</title>
</head>
<body onload="document.getElementById('txtSearch').focus()">
<?php include ("menu.php"); ?>
<div class="content">
<?php
include ("common.php");

$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
    $isSecure = true;
}
elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
    $isSecure = true;
}
$REQUEST_PROTOCOL = $isSecure ? 'https' : 'http';

$action_path = $REQUEST_PROTOCOL . '://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
$search_path = str_replace("index.php", "search.php", $action_path);
$tiny_feed_path = str_replace("index.php", "tiny.php", $action_path);
$image_path = str_replace("index.php", "image.php", $action_path);
$detail_path = str_replace("index.php", "detail.php", $action_path);

$max=15;
if (isset($_GET['max']))
	$max=$_GET['max'];
if (isset($_GET['search']) && $_GET['search'] != null)
{
    $feed_path = $search_path . "?max=" . $max ."&q=" . urlencode($_GET['search']);
	$feed_file = fopen($feed_path, "rb");
	$feed_content = stream_get_contents($feed_file);
	fclose($feed_file);
	$feed_response = json_decode($feed_content, true);
}
//Check pre-requisites exists
if (!file_exists("secrets.php")) {
	die ("Podcast Service installation error: secrets file not found. Review the readme file.");
}
if (!function_exists('curl_version')) {
    die ("Podcast Service installation error: php-curl not installed on server. Review the readme file.");
}
if (!function_exists('simplexml_load_file')) {
    die ("Podcast Service installation error: php-xml not installed on server. Review the readme file.");
}
if (!extension_loaded('gd')) {
    die ("Podcast Service installation error: php-gd not installed on server. Review the readme file.");
}
?>
    <p align='middle' style='margin-top:50px;'><a href="./"><img src='assets/icon-128.png' style="width:128px; height: 128px;" border="0"></a><br>
    <strong>Retro Podcast Directory</strong><br/>
    <small>A project of <a href="<?php echo $REQUEST_PROTOCOL ?>://www.webosarchive.org">webOS Archive</a></small><br>
    <br/>
    </p>
    <p align='middle' style='margin-bottom:14px;'><i>Search for podcasts by title</i></p>
    <form method="get">
        <div style="margin-left:auto;margin-right:auto;text-align:center;">
        <input type="text" id="txtSearch" name="search" class="search" placeholder="Just type...">
        <input type="submit" class="search-button" value="Search">
        </div>
    </form>
<?php
if (isset($feed_response) && count($feed_response["feeds"]) > 0)
{
    echo("<table cellpadding='5'>");
    foreach($feed_response["feeds"] as $feed) {
        echo("<tr><td align='center' valign='top'><img style='width:64px; height:64px; border-radius: 2%; -webkit-border-radius:5px;' src='". $image_path . "?img=" . base64url_encode($feed["image"]) . "' border='0' onerror='this.onerror=null; this.src=\"assets/icon-minimal.png\"' >");
        echo("<td width='100%' style='padding-left: 14px'><b>{$feed["title"]}</b><br/>");
        echo("<i>" . $feed["description"] . "...</i><br/>");
        if (isset($feed["substitution_reason"])) {
            echo "<small>Note: " . $feed["substitution_reason"] . "</small><br>";
        }
        echo("<a href='{$feed["url"]}' target='_blank'><img src='assets/rss-16.png'> Full Feed</a> | ");
        echo("<a href='" . $tiny_feed_path . "?url=" . base64url_encode($feed["url"]) . "' target='_blank'><img src='assets/rss-16.png'> Tiny Feed</a> | ");
        if (isset($feed['id']))
            echo("<a href='" . $detail_path . "?id=" . $feed["id"] . "'>More Details</a>");
        else
            echo("<a href='" . $detail_path . "?url=" . $feed["url"] . "'>More Details</a>");
        echo("</td></tr>");
    }
    echo("</table>");
}
?>
    <p align='middle' style="margin-top: 28px"><small>Search Provided by <a href='https://podcastindex.org/'>Podcast Index.org</a> | <a href="https://github.com/webosarchive/podcast-service">Host this yourself</a> | <a href='<?php echo $REQUEST_PROTOCOL; ?>://appcatalog.webosarchive.org/app/podcastdirectory'>Download the webOS App</a></small></p>
</div>
</body>
</html>
