<?php
include_once(__DIR__ . '/headers.php');
include_once(__DIR__ . '/env.php');

// Environment Variables
if(file_exists(__DIR__ . '/../' . $_ENV['ENV_FILE'])){
  include_once(__DIR__ . '/../' . $_ENV['ENV_FILE']);
}


include_once(__DIR__ . '/pdo.php');

// Session
if(file_exists(__DIR__ . '/../' . $_ENV['SESSION_FILE'])){
  include_once(__DIR__ . '/../' . $_ENV['SESSION_FILE']);
}


include_once(__DIR__ . '/../functions.php');