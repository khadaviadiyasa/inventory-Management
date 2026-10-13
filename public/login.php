<?php

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/escape.php';
require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, role
         FROM users
         WHERE username = :username
         LIMIT 1'
    );

    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify(
        $password,
        $user['password_hash']
    )) {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role']
        ];

        header('Location: dashboard.php');
        exit;
    }

    $error = 'Username atau password salah.';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Inventory</title>
</head>
<body>
    <h2>Inventory Management System</h2>
    <h3>Login</h3>

    <?php if ($error !== ''): ?>
        <p style="color:red;">
            <?= e($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        <label>Username</label><br>
        <input
            type="text"
            name="username"
            required
            autocomplete="username"
        ><br><br>

        <label>Password</label><br>
        <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        ><br><br>

        <button type="submit">Login</button>
    </form>
</body>
</html>