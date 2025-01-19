<?php
// Load environmental variables from .env file
$envFile =  __DIR__ . '/.env';
    
foreach (file($envFile) as $line) {
    // if line is comment, skip
    if( strpos($line, "#") === 0 ) continue;
    [ $key, $value ] = explode("=",trim($line));
    $_ENV[ $key ] = $value;
}