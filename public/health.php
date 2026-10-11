<?php
// Liveness probe for the container platform. Never exposes data.
header('Content-Type: text/plain');
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) { http_response_code(503); exit('no config'); }
$config = require $configPath;
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8mb4",
        $config['db']['user'], $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->query('SELECT 1');
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'db down';
}
