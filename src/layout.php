<?php
declare(strict_types=1);

function flash_set(string $msg, string $type = 'info'): void {
    $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_get(): ?array {
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function render_header(string $title): void {
    $u = current_user();
    $version = app_version();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/app.css">';
    echo '</head><body><div class="container">';

    echo '<div class="nav">';
    echo '<div class="links">';
    echo '<a href="/dashboard.php"><strong>Mini Uiltje</strong></a>';
    if ($u) {
        echo '<a href="/upload.php">Upload</a>';
        echo '<a href="/review.php">Review</a>';
        echo '<a href="/results.php">Results</a>';
        if ($u['role'] === 'admin') {
            echo '<a href="/admin/users.php">Users</a>';
            echo '<a href="/admin/categories.php">Categories</a>';
        }
        echo '<a href="/logout.php">Logout</a>';
    } else {
        echo '<a href="/login.php">Login</a>';
    }
    echo '</div>';
    echo '<div class="links">';
    if ($u) {
        echo '<span class="badge">' . h($u['email']) . '</span>';
    }
    echo '<span class="badge">' . h($version) . '</span>';
    echo '</div>';
    echo '</div>';

    $flash = flash_get();
    if ($flash) {
        $cls = $flash['type'] === 'error' ? 'error' : 'flash';
        echo '<div class="' . $cls . '">' . h($flash['msg']) . '</div>';
    }
}

function render_footer(): void {
    echo '<div class="footer">Mini Uiltje • version ' . h(app_version()) . '</div>';
    echo '</div></body></html>';
}
