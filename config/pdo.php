<?php
$pdo = new PDO("mysql:host={$_ENV['METACRUD_DB_HOST']};dbname={$_ENV['METACRUD_DB_NAME']}", $_ENV['METACRUD_DB_USER'], $_ENV['METACRUD_DB_PASS']);