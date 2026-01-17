<?php
declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals((string)($_SESSION['_csrf'] ?? ''), (string)$token)) {
        http_response_code(403);
        echo 'CSRF validation failed.';
        exit;
    }
}
