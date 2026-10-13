<?php

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/escape.php';

require_login();

$user = $_SESSION['user'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Inventory</title>
</head>
<body>
    <h1>Dashboard Inventory</h1>

    <p>
        Selamat datang,
        <strong><?= e($user['full_name']) ?></strong>!
    </p>

    <p>Username: <?= e($user['username']) ?></p>
    <p>Role: <?= e($user['role']) ?></p>

    <h3>Inventory Management System</h3>
    <p>Login berhasil. Dashboard siap dikembangkan.</p>

    <form action="logout.php" method="POST">
        <button type="submit">Logout</button>
    </form>
</body>
</html>