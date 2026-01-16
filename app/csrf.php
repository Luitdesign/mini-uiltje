<?php
declare(strict_types=1);

function csrf_token(array $config): string {
    if (empty($_SESSION['csrf_token'])) {
        $key = $config['app_key'] ?? '';
        // We mix in app_key so the token isn't purely random per session.
        $_SESSION['csrf_token'] = hash('sha256', bin2hex(random_bytes(32)) . $key);
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(array $config): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || $token !== csrf_token($config)) {
        http_response_code(400);
        echo 'Bad request (CSRF).';
        exit;
    }
}
