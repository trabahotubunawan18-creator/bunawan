<?php
require_once __DIR__ . '/config.php';

// Connects to PostgreSQL (Supabase). The settings come from config.php / config.local.php / environment variables.
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=' . DB_SSLMODE,
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            // Dates and times are kept in Philippine time, the same as the site always showed them.
            $pdo->exec("SET TIME ZONE 'Asia/Manila'");
        } catch (PDOException $e) {
            error_log($e->getMessage());
            http_response_code(500);
            exit('We could not connect to the database. Please check the database settings (DB_HOST, DB_USER, DB_PASS) and that the Supabase project is running.');
        }
    }
    return $pdo;
}
