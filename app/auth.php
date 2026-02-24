<?php
declare(strict_types=1);

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_username(): string {
    return (string)($_SESSION['username'] ?? '');
}

function is_admin_user(): bool {
    return strtolower(current_username()) === 'admin';
}

function auth_attempt_login(PDO $db, string $username, string $password): bool {
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $username;
    return true;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']
        );
    }
    session_destroy();
}

function current_data_user_id(PDO $db): int {
    if (!is_logged_in()) {
        return 0;
    }

    $stmt = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $sharedId = (int)($stmt->fetchColumn() ?: 0);
    return $sharedId > 0 ? $sharedId : current_user_id();
}
