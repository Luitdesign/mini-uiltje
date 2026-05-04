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

function current_user_role(): string {
    $role = strtolower(trim((string)($_SESSION['role'] ?? 'user')));
    return $role === 'admin' ? 'admin' : 'user';
}

function is_admin_user(): bool {
    return current_user_role() === 'admin';
}

function auth_refresh_session_user(PDO $db): void {
    $userId = current_user_id();
    if ($userId <= 0) {
        return;
    }

    $stmt = $db->prepare('SELECT username, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        auth_logout();
        return;
    }

    $_SESSION['username'] = (string)($user['username'] ?? '');
    $_SESSION['role'] = strtolower(trim((string)($user['role'] ?? 'user'))) === 'admin' ? 'admin' : 'user';
}

function auth_attempt_login(PDO $db, string $username, string $password): bool {
    $stmt = $db->prepare('SELECT id, password_hash, role FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $username;
    $_SESSION['role'] = strtolower(trim((string)($user['role'] ?? 'user'))) === 'admin' ? 'admin' : 'user';
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
