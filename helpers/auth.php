<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function require_login(): void
{
    if (!isset($_SESSION['user'])) {
        header('Location: /inventory/public/login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if ($_SESSION['user']['role'] !== $role) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki hak akses.');
    }
}
