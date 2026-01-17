<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (!is_logged_in()) {
    redirect('/login.php');
}
redirect('/months.php');
