<?php

require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;