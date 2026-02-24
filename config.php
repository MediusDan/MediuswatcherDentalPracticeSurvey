<?php
/**
 * Dental Survey App Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dental_surveys');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

// App settings
define('APP_NAME', 'Patient Survey');
define('KIOSK_TIMEOUT', 120); // Seconds of inactivity before reset
define('ALLOW_ANONYMOUS', true);

// Get database connection
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Helper functions
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function getPractice($id = 1) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM practices WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Session management
session_start();
