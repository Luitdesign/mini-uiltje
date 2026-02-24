<?php
declare(strict_types=1);

function users_list(PDO $db): array {
    $stmt = $db->query('SELECT id, username, created_at FROM users ORDER BY username ASC');
    return $stmt->fetchAll();
}

function users_find_by_id(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT id, username, password_hash, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function users_create(PDO $db, string $username, string $password): void {
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Username is required.');
    }
    if (strlen($username) > 50) {
        throw new RuntimeException('Username must be 50 characters or fewer.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users(username, password_hash) VALUES(:u, :p)');
    try {
        $stmt->execute([':u' => $username, ':p' => $hash]);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            throw new RuntimeException('Username already exists.');
        }
        throw $e;
    }
}

function users_delete(PDO $db, int $id, int $currentUserId): void {
    if ($id === $currentUserId) {
        throw new RuntimeException('You cannot delete your own account while logged in.');
    }

    $totalUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($totalUsers <= 1) {
        throw new RuntimeException('Cannot delete the last remaining user.');
    }

    $stmt = $db->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('User not found.');
    }
}

function users_change_password(PDO $db, int $id, string $currentPassword, string $newPassword): void {
    $user = users_find_by_id($db, $id);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }
    if (!password_verify($currentPassword, (string)$user['password_hash'])) {
        throw new RuntimeException('Current password is incorrect.');
    }
    if (strlen($newPassword) < 8) {
        throw new RuntimeException('New password must be at least 8 characters.');
    }

    $stmt = $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
    $stmt->execute([
        ':p' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $id,
    ]);
}
