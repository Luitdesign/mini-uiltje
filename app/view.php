<?php
declare(strict_types=1);

function render_header(string $title, ?string $active = null): void {
    $username = $_SESSION['username'] ?? '';
    $nav = is_logged_in() ? [
        ['type' => 'link', 'label' => 'Month', 'href' => '/months.php', 'key' => 'months'],
        ['type' => 'link', 'label' => 'Upload', 'href' => '/upload.php', 'key' => 'upload'],
        [
            'type' => 'dropdown',
            'label' => 'Pots',
            'children' => [
                ['label' => 'Pots', 'href' => '/pots.php', 'key' => 'pots'],
                ['label' => 'Pots Categories', 'href' => '/pots_categories.php', 'key' => 'pots-categories'],
            ],
        ],
        ['type' => 'link', 'label' => 'Categories', 'href' => '/categories.php', 'key' => 'categories'],
        ['type' => 'link', 'label' => 'Rules', 'href' => '/rules.php', 'key' => 'rules'],
        [
            'type' => 'dropdown',
            'label' => 'Settings',
            'children' => [
                ['label' => 'DB Check', 'href' => '/db-check.php', 'key' => 'db-check'],
                ['label' => 'Scheme', 'href' => '/schema.php', 'key' => 'schema'],
                ['label' => 'Reset Database', 'href' => '/reset.php', 'key' => 'reset'],
                ['label' => 'Logout', 'href' => '/logout.php', 'key' => 'logout'],
            ],
        ],
    ] : [];

    echo "<!doctype html>\n";
    echo "<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>" . h($title) . "</title>\n";
    echo "<link rel=\"stylesheet\" href=\"/assets/style.css\">\n";
    echo "</head>\n<body>\n";

    echo "<header class=\"topbar\">";
    echo "<div class=\"brand\"><a href=\"/months.php\">Mini Uiltje</a></div>";
    if ($username !== '') {
        echo "<div class=\"user\">Logged in as <strong>" . h($username) . "</strong></div>";
    }
    echo "</header>";

    if (!empty($nav)) {
        echo "<nav class=\"nav\"><div class=\"nav-inner\">";
        foreach ($nav as $item) {
            $type = $item['type'] ?? 'link';
            if ($type === 'label') {
                echo "<span class=\"navlabel\">" . h($item['label']) . "</span>";
                continue;
            }
            if ($type === 'dropdown') {
                $children = $item['children'] ?? [];
                $childActive = false;
                foreach ($children as $child) {
                    if ($active === ($child['key'] ?? null)) {
                        $childActive = true;
                        break;
                    }
                }
                $toggleCls = $childActive ? 'active' : '';
                echo "<div class=\"nav-dropdown\">";
                echo "<button class=\"navlink navtoggle {$toggleCls}\" type=\"button\">";
                echo h($item['label']) . "<span class=\"navcaret\">▾</span>";
                echo "</button>";
                echo "<div class=\"navmenu\">";
                foreach ($children as $child) {
                    $childCls = ($active === ($child['key'] ?? null)) ? 'active' : '';
                    echo "<a class=\"navlink {$childCls}\" href=\"{$child['href']}\">" . h($child['label']) . "</a>";
                }
                echo "</div></div>";
                continue;
            }
            $cls = ($active === $item['key']) ? 'active' : '';
            echo "<a class=\"navlink {$cls}\" href=\"{$item['href']}\">" . h($item['label']) . "</a>";
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
