<!DOCTYPE html>
<html lang="en">
<?php
include ("common.php");

//App Details
$title = "Retro Podcast Directory";
$subtitle = "";
$description = "Simplified podcast feeds for retro devices.";
$github = "https://github.com/webosarchive/podcast-service";
$museumLink = "http://appcatalog.webosarchive.org/app/podcastdirectory";
$icon = "assets/icon.png";

//Figure out what protocol the client wanted
$isSecure = false;
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
	$PROTOCOL = "https";
} else {
	$PROTOCOL = "http";
}

//Podcast stuff
$action_path = $PROTOCOL . '://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
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
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">

  <meta name="description" content="<?php echo $description; ?>">
  <meta name="keywords" content="webos, firefoxos, pwa, rss">
  <meta name="author" content="webOS Archive">
  <meta property="og:title" content="<?php echo $title; ?>">
  <meta property="og:description" content="<?php echo $description; ?>">
  <meta property="og:image" content="https://<?php echo $_SERVER['SERVER_NAME'] ?>/tracker/hero.png">

  <meta name="twitter:card" content="app">
  <meta name="twitter:site" content="@webOSArchive">
  <meta name="twitter:title" content="<?php echo $title; ?>">
  <meta name="twitter:description" content="<?php echo $description; ?>">
  <meta name="twitter:app:id:googleplay" content="<?php echo $playId?>">

  <title><?php echo $title . $subtitle; ?></title>
  
  <link id="favicon" rel="icon" type="image/png" sizes="64x64" href="<?php echo $icon;?>">
  <link href="<?php echo $PROTOCOL . "://www.webosarchive.org/app-template/"?>web.css" rel="stylesheet" type="text/css" >
  <link href="style.css" rel="stylesheet" type="text/css" >
</head>
<body>
<?php

$docRoot = "./";
echo file_get_contents("https://www.webosarchive.org/menu.php?docRoot=" . $docRoot . "&protocol=" . $PROTOCOL);
?>

  <table width="100%" border=0 style="width:100%;border:0px"><tr><td align="center" style="width:100%;height:100%;border:0px">
  <div id="row">
    <div align="left">
      <h1><img src="<?php echo $icon;?>" width="60" height="60" alt=""/><?php echo $title; ?></h1>
      <p><b><?php echo $description; ?></b></p>
      <p> 
          <form method="get">
            <div class="search-form">
              <div style="margin-bottom:10px; font-size:larger;"><i>Search for podcasts by title</i></div>
              <input type="text" style="font-size:larger;" id="txtSearch" name="search" class="search" placeholder="Just type...">
              <input type="submit" style="font-size:larger;" class="search-button" value="Search">
            </div>
          </form>
      </p>
      
    <?php
if (isset($feed_response) && count($feed_response["feeds"]) > 0)
{
    echo("<table cellpadding='5'>");
    foreach($feed_response["feeds"] as $feed) {
        echo("<tr><td align='center' valign='top' class='result-row'><img style='width:64px; height:64px; border-radius: 2%; -webkit-border-radius:5px;' src='". $image_path . "?img=" . base64url_encode($feed["image"]) . "' border='0' onerror='this.onerror=null; this.src=\"assets/icon-minimal.png\"' >");
        echo("<td width='100%' class='result-row' style='padding-left: 14px;'><b>{$feed["title"]}</b><br/>");
        echo("<div style='padding-top: 16px'><i>" . $feed["description"] . "...</i><br/>");
        if (isset($feed["substitution_reason"])) {
            echo "<small>Note: " . $feed["substitution_reason"] . "</small><br>";
        }
        echo "<br/>";
        if (isset($feed['id']))
          echo("<a href='" . $detail_path . "?id=" . $feed["id"] . "'>More Details</a> | ");
        else
          echo("<a href='" . $detail_path . "?url=" . $feed["url"] . "'>More Details</a> | ");
        echo("<a href='{$feed["url"]}' target='_blank'><img src='assets/rss-16.png'> Full Feed</a> | ");
        echo("<a href='" . $tiny_feed_path . "?url=" . base64url_encode($feed["url"]) . "' target='_blank'><img src='assets/rss-16.png'> Tiny Feed</a>");
        echo("</td></tr>");
    }
    echo("</table>");
}
?>
<p><small>Search Provided by <a href='https://podcastindex.org/'>Podcast Index.org</a>. Host this yourself, view the source, or contribute on <?php echo "<a href='" . $github . "'>GitHub</a>"?>.</small></p>
      <p class="center">
        <?php if (isset($museumLink)) { ?>
        <a class="download-link" href="<?php echo $museumLink; ?>">
          <img src="<?php echo $PROTOCOL . "://www.webosarchive.org/app-template/"?>museum-badge.png" width="200" height="59" alt="Find it in the App Museum" />
        </a>
        <?php } ?>
      </p>
    </div>
  </div>
  <div id="footer">
    &copy;  webOSArchive
    <div id="footer-links">
      <a href="<?php echo $github . "/blob/master/PrivacyPolicy.md" ?>">Privacy Policy</a>
    </div>
  </div>
  </td></tr></table>
</body>
</html>
