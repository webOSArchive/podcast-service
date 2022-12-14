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

<?php
include ("common.php");

$action_path = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
$search_path = str_replace("detail.php", "getdetailby.php", $action_path);
$feed_path = str_replace("detail.php", "tiny.php", $action_path);
$image_path = str_replace("detail.php", "image.php", $action_path);

if (isset($_SERVER['QUERY_STRING']))
{
    $app_path = $search_path . "?" . $_SERVER['QUERY_STRING'];
	$app_file = fopen($app_path, "rb");
	$app_content = stream_get_contents($app_file);
	fclose($app_file);
	$app_response = json_decode($app_content, true);
}

$back_path = $_SERVER['HTTP_REFERER'];
if (strpos($back_path, "?search=") === false) {
    $back_path = "index.php";
}

// Prepare Social media meta data
$ogTitle = "Podcast Directory from webOS Archive";
$ogImage = "http://podcasts.webosarchive.org/assets/icon-256.png";
$ogDesc = "webOS Archive's Podcast Directory let's you listen to today's podcasts on your retro devices!";
if (isset($app_response["feed"])) {
    $feed = $app_response["feed"];
    $ogImage = $feed['image'];
    $ogTitle = $feed['title'] . " on " . $ogTitle;
    $ogDesc = $feed['description'];
}
?>

<!-- Social media -->
<meta name="description" content="<?php echo $ogDesc; ?>" />
<link rel="canonical" href="http://podcasts.webosarchive.org" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:title" content="<?php echo $ogTitle; ?>" />
<meta property="og:description" content="<?php echo $ogDesc; ?>" />
<meta property="og:url" content="https://www.webosarchive.org" />
<meta property="og:site_name" content="webOS Archive" />
<meta property="article:published_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="article:modified_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="og:image" content="<?php echo $ogImage; ?>" />
<meta property="og:image:width" content="256" />
<meta property="og:image:height" content="256" />
<meta property="og:image:type" content="image/png" />
<meta name="author" content="webOS Archive" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="<?php echo $ogTitle; ?>" />
<meta name="twitter:description" content="<?php echo $ogDesc; ?>" />
<meta name="twitter:image" content="<?php echo $ogImage; ?>" />
<!-- /Social media -->

<link rel="stylesheet" href="style.css">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=1" />
<title>webOS Podcast Directory - Podcast Detail</title>
</head>
<body onload="document.getElementById('txtSearch').focus()">
<?php include ("menu.php"); ?>
<div class="content">
<small>&lt; <a href="<?php echo $back_path ?>">Back to Search</a></small>
<?php
if (isset($app_response["feed"]))
{
    $feed = $app_response["feed"];
    //echo $feedimage;
    echo "<p align='middle' style='margin-top:30px;'><img src='" . $feed['image'] . "' style='width:128px; height: 128px;border-radius: 3%; -webkit-border-radius:6px;' border='0' onerror='this.onerror=null; this.src=\"assets/icon-minimal.png\"' ></p>";
    echo "<p align='middle' style='margin-top:12px;margin-bottom:32px;'><b>" . $feed['title'] . "</b></p>";
    echo $feed['description'];

    echo "<ul>";
    echo "<li><b>Author:</b> " . $feed['author'] . "</li>";
    echo "<li><b>Episodes:</b> " . $feed['episodeCount'] . "</li>";
    if (isset($feed['categories'])) {
        echo "<li><b>Categories:</b> ";
        foreach ($feed['categories'] as $category) {
            echo $category . " ";
        }
    }
    echo "<li><b>Website:</b> <a href='" . $feed['link'] . "'>" . $feed['link'] . "</a></li>";
    echo("<li><b>Subscribe: </b><a href='{$feed["url"]}' target='_blank'><img src='assets/rss-16.png' style='vertical-align: top;'> Full Feed</a> | ");
    echo("<a href='$feed_path?url=" . base64url_encode($feed["url"]) . "' target='_blank'><img src='assets/rss-16.png' style='vertical-align: top;'> Tiny Feed</a></li>");
    if (isset($feed['substitution_reason'])) {
        echo "<li><small><b>Notes:</b> " . $feed['substitution_reason'] . "</small></li>";
    }
    echo "</ul>";
    echo "<!--" . json_encode($feed) . "-->";
}
?>
<?php include ("help.html")?>

<p align='middle' style="margin-top: 38px"><small>Search Provided by <a href='https://podcastindex.org/'>Podcast Index.org</a> | <a href="https://github.com/webosarchive/podcast-service">Host this yourself</a> | <a href='http://appcatalog.webosarchive.org/showMuseum.php?search=podcast+directory'>Download the webOS App</a></small></p>
</div>
</body>
</html>
