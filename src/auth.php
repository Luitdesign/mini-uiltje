<?php
declare(strict_types=1);

function current_user(): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return null;
    $stmt = db()->prepare('SELECT id, email, role FROM users WHERE id = ?');
    $stmt->execute([(int)$id]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function require_admin(): void {
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function login(string $email, string $password): bool {
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;
    $_SESSION['user_id'] = (int)$user['id'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
