<?php

require_once __DIR__ . '/config.php';

function get_db_connection(): PDO
{
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
        $pdo->exec("SET time_zone='+07:00'");
    }

    return $pdo;
}
