<?php
// ============================================
// config.php — edite apenas as 4 linhas abaixo
// ============================================

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'monitor_pg');
define('DB_USER', 'roott');
define('DB_PASS', 'jade132456');

// ── Não edite abaixo ─────────────────────────

function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        die(json_encode(['ok' => false, 'error' => 'Erro de conexão: ' . $e->getMessage()]));
    }
    return $pdo;
}

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        session_name('mp_sess');
        session_set_cookie_params(60 * 60 * 24 * 7);
        session_start();
    }
}

function authUser() {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Não autorizado.']));
    }
    return (int) $_SESSION['user_id'];
}