<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: PUT, GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    exit();
}
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
