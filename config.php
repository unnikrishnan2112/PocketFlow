<?php
/** Update these credentials for your local MySQL / XAMPP installation. */
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'pocketflow';
const DB_USER = 'root';
const DB_PASS = '';

date_default_timezone_set('Asia/Kolkata');

function db(): PDO {
    static $db = null;
    if ($db === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }
    return $db;
}
