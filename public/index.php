<?php
require_once __DIR__ . '/../src/bootstrap.php';

if (install_required()) {
    redirect('/install.php');
}

if (!is_logged_in()) {
    redirect('/login.php');
}
redirect('/dashboard.php');
