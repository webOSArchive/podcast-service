<?php
include ("common.php");

//Handle more specific queries
$img = null;
$imgSize = validate_numeric_input($_GET["size"] ?? 128, 32, 512);

if (isset($_GET['img']) && $_GET['img'] != "") {
    $img = $_GET['img'];
} else if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != "") {
    $img = $_SERVER['QUERY_STRING'];
}

if (!isset($img) || empty($img)) {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    die("Missing image parameter");
}

$cacheID = $img;
$url = base64url_decode($cacheID);

if (!validate_url($url)) {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
    die("Invalid URL");
}

//Prepare the cache
$path = "cache";
if (!file_exists($path)) {
    mkdir($path, 0755, true);
}

//Create safe filename
$cacheID = safe_cache_filename($cacheID);
if (empty($cacheID)) {
    $cacheID = 'img_' . md5($url);
}

//Fetch and cache the file if its not already cached
$path = $path . "/" . $cacheID . ".png";
if (!file_exists($path) || filesize($path) < 1) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'method' => 'GET',
            'header' => 'User-Agent: webOSPodcastDirectory/1.0\r\n'
        ]
    ]);
    
    $imageData = file_get_contents($url, false, $context, 0, 5*1024*1024);
    if ($imageData === false) {
        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        die("Unable to fetch image");
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageData);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
        header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
        die("Invalid image format");
    }
    
    file_put_contents($path, $imageData);
}

//TODO: Determine if the image is empty and return something generic instead
//TODO: Handle invalid images

//Make image smaller so we don't crush tiny old devices
resize_img($imgSize, $path, $path);

// send the right headers
$info = getimagesize($path);
header("Content-Type: " . $info['mime']);
header("Content-Length: " . filesize($path));
// dump the file and stop the script
$fp = fopen($path, 'r');
fpassthru($fp);
exit;

//Function to resize common image formats
//  Found on https://stackoverflow.com/questions/13596794/resize-images-with-php-support-png-jpg
function resize_img($newWidth, $targetFile, $originalFile) {
  if (isset($newWidth) && isset($targetFile) && isset($originalFile)) {
    
    $info = getimagesize($originalFile);
    $mime = $info['mime'];

    switch ($mime) {
            case 'image/jpeg':
                    $image_create_func = 'imagecreatefromjpeg';
                    $image_save_func = 'imagejpeg';
                    $new_image_ext = 'jpg';
                    break;

            case 'image/png':
                    $image_create_func = 'imagecreatefrompng';
                    $image_save_func = 'imagepng';
                    $new_image_ext = 'png';
                    break;

            case 'image/gif':
                    $image_create_func = 'imagecreatefromgif';
                    $image_save_func = 'imagegif';
                    $new_image_ext = 'gif';
                    break;

            default: 
                    throw new Exception('Unknown image type.');
    }

    $img = $image_create_func($originalFile);
    list($width, $height) = getimagesize($originalFile);

    $newHeight = ($height / $width) * $newWidth;
    $tmp = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($tmp, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    if (file_exists($targetFile)) {
            unlink($targetFile);
    }
    $image_save_func($tmp, $targetFile);
  }
}
?>
