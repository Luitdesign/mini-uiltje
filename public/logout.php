<?php
require_once __DIR__ . '/../app/bootstrap.php';

auth_logout();
redirect('/login.php');
