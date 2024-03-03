<?php
include ("common.php");

//Figure out what protocol the client wanted
$isSecure = false;
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
	$PROTOCOL = "https";
} else {
	$PROTOCOL = "http";
}

$action_path = $PROTOCOL . '://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
$search_path = str_replace("detail.php", "getdetailby.php", $action_path);
$tiny_feed_path = str_replace("detail.php", "tiny.php", $action_path);
$image_path = str_replace("detail.php", "image.php", $action_path);

if (isset($_SERVER['QUERY_STRING']))
{
    $feed_path = $search_path . "?" . $_SERVER['QUERY_STRING'];
	$feed_file = fopen($feed_path, "rb");
	$feed_content = stream_get_contents($feed_file);
	fclose($feed_file);
	$feed_response = json_decode($feed_content, true);
    if (isset($feed_response["feed"])){
        $feed = $feed_response["feed"];
    }    
}
?>
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
$back_path = "index.php";
if (isset($_SERVER['HTTP_REFERER'])) {
    if (strpos($_SERVER['HTTP_REFERER'], "?search") !== false)
        $back_path = $_SERVER['HTTP_REFERER'];
}

// Prepare Social media meta data
$ogTitle = "Podcast Directory from webOS Archive";
$ogImage = "http://podcasts.webosarchive.org/assets/icon-256.png";
$ogDesc = "webOS Archive's Podcast Directory let's you listen to today's podcasts on your retro devices!";
if (isset($feed)) {
    $feed = $feed_response["feed"];
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
<link href="<?php echo $PROTOCOL . "://www.webosarchive.org/app-template/"?>web.css" rel="stylesheet" type="text/css" >
<link href="style.css" rel="stylesheet" type="text/css" >
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=1" />
<title><?php echo $ogTitle?></title>
</head>
<body>
<?php
$docRoot = "./";
echo file_get_contents("https://www.webosarchive.org/menu.php?docRoot=" . $docRoot . "&protocol=" . $PROTOCOL);
?>
<table width="100%" border=0 style="width:100%;border:0px"><tr><td align="center" style="width:100%;height:100%;border:0px">
  <div id="row">
    <div align="left">
      <img src="<?php echo $feed['image']; ?>" width="128" height="128" alt="" style="float:left;margin-right:18px;"/>
      <h1><?php echo $feed['title'];?></h1>
      <p style="margin-top:-18px;"><b><?php echo $feed['author'];?></b></p>
      <ul class="detail-list">
      <?php
        echo "<li><b>Episodes:</b> " . $feed['episodeCount'] . "</li>";
        if (isset($feed['categories'])) {
            echo "<li><b>Categories:</b> ";
            foreach ($feed['categories'] as $category) {
                echo $category . " ";
            }
        }
        echo "<li><b>Website:</b> <a href='" . $feed['link'] . "'>" . $feed['link'] . "</a></li>";
        echo("<li><b>Subscribe: </b><a href='{$feed["url"]}' target='_blank'><img src='assets/rss-16.png' style='vertical-align: top;'> Full Feed</a> &nbsp;| &nbsp;");
        echo("<a href='$tiny_feed_path?url=" . base64url_encode($feed["url"]) . "' target='_blank'><img src='assets/rss-16.png' style='vertical-align: top;'> Tiny Feed</a></li>");
        if (isset($feed['substitution_reason'])) {
            echo "<li><small><b>Notes:</b> " . $feed['substitution_reason'] . "</small></li>";
        }
      ?>
      </ul>
      <p> <?php echo $feed['description']?></p>
      <p><?php include ("help.html")?></p>
      <p><small>&lt; <a href="<?php echo $back_path ?>">Back to Search</a></small></p>
    </div>
  </div>
  <div id="footer">
    &copy;  webOSArchive
    <div id="footer-links">
      <a href="https://github.com/webosarchive/podcast-service/blob/master/PrivacyPolicy.md">Privacy Policy</a>
    </div>
  </div>
</td></tr></table>

</body>
</html>
