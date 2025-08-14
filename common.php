<?php
function base64url_encode($data)
{
  // First of all you should encode $data to Base64 string
  $b64 = base64_encode($data);

  // Make sure you get a valid result, otherwise, return FALSE, as the base64_encode() function do
  if ($b64 === false) {
    return false;
  }

  // Convert Base64 to Base64URL by replacing "+" with "-" and "/" with "_"
  $url = strtr($b64, '+/', '-_');

  // Remove padding character from the end of line and return the Base64URL result
  return rtrim($url, '=');
}

function base64url_decode($data, $strict = false)
{
  // Convert Base64URL to Base64 by replacing "-" with "+" and "_" with "/"
  $b64 = strtr($data, '-_', '+/');

  // Decode Base64 string and return the original data
  return base64_decode($b64, $strict);
}

function validate_url($url) {
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return false;
  }
  
  $parsed = parse_url($url);
  if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
    return false;
  }
  
  if (!in_array($parsed['scheme'], ['http', 'https'])) {
    return false;
  }
  
  if (filter_var($parsed['host'], FILTER_VALIDATE_IP)) {
    $ip = $parsed['host'];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return false;
    }
  }
  
  return true;
}

function safe_cache_filename($input) {
  $clean = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $input);
  $clean = substr($clean, 0, 200);
  return $clean;
}

function validate_search_query($query) {
  return preg_replace("/[^a-zA-Z0-9\s\-_'\"]/", "", trim($query));
}

function validate_numeric_input($input, $min = 1, $max = 100) {
  $num = intval($input);
  return max($min, min($max, $num));
}
?>