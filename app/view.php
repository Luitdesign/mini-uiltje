<?php
declare(strict_types=1);

function render_header(string $title, ?string $active = null): void {
    $username = $_SESSION['username'] ?? '';
    $nav = is_logged_in() ? [
        ['Months', '/months.php', 'months'],
        ['Upload', '/upload.php', 'upload'],
        ['Transactions', '/transactions.php', 'transactions'],
        ['Summary', '/summary.php', 'summary'],
        ['Categories', '/categories.php', 'categories'],
        ['Schema', '/schema.php', 'schema'],
        ['Logout', '/logout.php', 'logout'],
    ] : [];

    echo "<!doctype html>\n";
    echo "<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>" . h($title) . "</title>\n";
    echo "<link rel=\"stylesheet\" href=\"/assets/style.css\">\n";
    echo "</head>\n<body>\n";

    echo "<header class=\"topbar\">";
    echo "<div class=\"brand\">Financial MVP</div>";
    if ($username !== '') {
        echo "<div class=\"user\">Logged in as <strong>" . h($username) . "</strong></div>";
    }
    echo "</header>";

    if (!empty($nav)) {
        echo "<nav class=\"nav\"><div class=\"nav-inner\">";
        foreach ($nav as [$label, $href, $key]) {
            $cls = ($active === $key) ? 'active' : '';
            echo "<a class=\"navlink {$cls}\" href=\"{$href}\">" . h($label) . "</a>";
        }
        echo "</div></nav>";
    }

    echo "<main class=\"container\">";
}

function render_footer(): void {
    echo "</main>\n";
    echo "<footer class=\"footer\">MVP build</footer>";
    echo "</body></html>";
}
