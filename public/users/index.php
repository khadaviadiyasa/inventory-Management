
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

require_login();
require_role('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

/*
|--------------------------------------------------------------------------
| PROSES FORM
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = 'Permintaan tidak valid.';
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';

            if (
                !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
            ) {
                throw new RuntimeException(
                    'Username harus 3–50 karakter, hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.'
                );
            }

            if (
                $fullName === '' ||
                strlen($fullName) > 100
            ) {
                throw new RuntimeException(
                    'Nama wajib diisi, maksimal 100 karakter.'
                );
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new RuntimeException('Role tidak valid.');
            }

            if ($action === 'create') {
                if (
                    strlen($password) < 8 ||
                    strlen($password) > 72
                ) {
                    throw new RuntimeException(
                        'Password harus terdiri dari 8–72 byte.'
                    );
                }

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                        (username, password_hash, full_name, role)
                     VALUES
                        (:username, :password_hash, :full_name, :role)'
                );

                $stmt->execute([
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'full_name' => $fullName,
                    'role' => $role
                ]);

                $_SESSION['flash'] = 'Pengguna berhasil ditambahkan.';

            } else {
                if ($id <= 0) {
                    throw new RuntimeException(
                        'ID pengguna tidak valid.'
                    );
                }

                // Jangan biarkan admin mengubah role akun sendiri
                // melalui halaman ini.
                if ($id === (int) $_SESSION['user']['id']) {
                    $role = 'admin';
                }

                if ($password !== '') {
                    if (
                        strlen($password) < 8 ||
                        strlen($password) > 72
                    ) {
                        throw new RuntimeException(
                            'Password baru harus terdiri dari 8–72 byte.'
                        );
                    }

                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             full_name = :full_name,
                             role = :role,
                             password_hash = :password_hash
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        'username' => $username,
                        'full_name' => $fullName,
                        'role' => $role,
                        'password_hash' => $passwordHash,
                        'id' => $id
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET username = :username,
                             full_name = :full_name,
                             role = :role
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        'username' => $username,
                        'full_name' => $fullName,
                        'role' => $role,
                        'id' => $id
                    ]);
                }

                $_SESSION['flash'] = 'Data pengguna berhasil diperbarui.';
            }

        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException(
                    'ID pengguna tidak valid.'
                );
            }

            if ($id === (int) $_SESSION['user']['id']) {
                throw new RuntimeException(
                    'Kamu tidak dapat menghapus akun yang sedang digunakan.'
                );
            }

            // Histori transaksi tidak boleh kehilangan pengguna
            // yang melakukannya.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM stock_movements
                 WHERE user_id = ?'
            );
            $stmt->execute([$id]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Pengguna memiliki histori transaksi sehingga tidak dapat dihapus.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM users WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['flash'] = 'Pengguna berhasil dihapus.';

        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();

    } catch (PDOException $e) {
        error_log($e->getMessage());

        if ($e->getCode() === '23000') {
            $_SESSION['flash'] =
                'Username sudah digunakan atau pengguna masih terkait dengan data lain.';
        } else {
            $_SESSION['flash'] =
                'Gagal menyimpan data pengguna.';
        }
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PESAN DAN FORM EDIT
|--------------------------------------------------------------------------
*/

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$editUser = null;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, role
         FROM users
         WHERE id = ?'
    );

    $stmt->execute([(int) $_GET['edit']]);
    $editUser = $stmt->fetch();

    if (!$editUser) {
        $message = 'Pengguna tidak ditemukan.';
    }
}

/*
|--------------------------------------------------------------------------
| DAFTAR PENGGUNA
|--------------------------------------------------------------------------
*/

$users = $pdo->query(
    'SELECT
        u.id,
        u.username,
        u.full_name,
        u.role,
        u.created_at,
        COUNT(sm.id) AS total_transactions
     FROM users u
     LEFT JOIN stock_movements sm ON sm.user_id = u.id
     GROUP BY
        u.id, u.username, u.full_name, u.role, u.created_at
     ORDER BY u.id DESC'
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manajemen Pengguna</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f1f5f9;
            color: #1e293b;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .panel {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 10px;
            overflow-x: auto;
        }

        .navigation {
            margin-bottom: 22px;
        }

        .navigation a {
            margin-right: 12px;
        }

        .field {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input, select, button {
            padding: 11px;
            font: inherit;
        }

        input, select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }

        button {
            cursor: pointer;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: white;
        }

        .delete-button {
            background: #dc2626;
        }

        .message {
            padding: 14px;
            border-radius: 6px;
            background: #dbeafe;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
        }

        .role {
            font-weight: bold;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .actions form {
            margin: 0;
        }

        .actions button {
            padding: 8px 10px;
        }

        .hint {
            color: #64748b;
            font-size: 13px;
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Manajemen Pengguna</h1>
    <p>Kelola akun admin dan staff Inventory Management System.</p>

    <div class="navigation">
        <a href="../dashboard.php">Dashboard</a>
        <a href="../categories/index.php">Kategori</a>
        <a href="../products/index.php">Produk</a>
        <a href="../suppliers/index.php">Supplier</a>
        <a href="../stock/index.php">Transaksi Stok</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>
            <?= $editUser ? 'Edit Pengguna' : 'Tambah Pengguna' ?>
        </h2>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action"
                   value="<?= $editUser ? 'update' : 'create' ?>">

            <?php if ($editUser): ?>
                <input type="hidden" name="id"
                       value="<?= (int) $editUser['id'] ?>">
            <?php endif; ?>

            <div class="field">
                <label for="username">Username</label>
                <input id="username"
                       type="text"
                       name="username"
                       minlength="3"
                       maxlength="50"
                       pattern="[A-Za-z0-9_.-]{3,50}"
                       required
                       value="<?= e($editUser['username'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="full_name">Nama lengkap</label>
                <input id="full_name"
                       type="text"
                       name="full_name"
                       maxlength="100"
                       required
                       value="<?= e($editUser['full_name'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="password">
                    Password
                    <?= $editUser ? '(kosongkan jika tidak diubah)' : '' ?>
                </label>
                <input id="password"
                       type="password"
                       name="password"
                       minlength="8"
                       maxlength="72"
                       <?= $editUser ? '' : 'required' ?>
                       autocomplete="new-password">

                <p class="hint">
                    Password minimal 8 byte dan maksimal 72 byte.
                    Password disimpan dalam bentuk hash.
                </p>
            </div>

            <div class="field">
                <label for="role">Role</label>

                <select id="role" name="role" required>
                    <option value="staff"
                        <?= ($editUser['role'] ?? 'staff') === 'staff'
                            ? 'selected'
                            : '' ?>>
                        Staff
                    </option>

                    <option value="admin"
                        <?= ($editUser['role'] ?? '') === 'admin'
                            ? 'selected'
                            : '' ?>>
                        Admin
                    </option>
                </select>

                <?php if (
                    $editUser &&
                    (int) $editUser['id'] === (int) $_SESSION['user']['id']
                ): ?>
                    <p class="hint">
                        Role akun yang sedang digunakan tetap admin.
                    </p>
                <?php endif; ?>
            </div>

            <button type="submit">
                <?= $editUser ? 'Simpan Perubahan' : 'Tambah Pengguna' ?>
            </button>

            <?php if ($editUser): ?>
                <a href="index.php">Batal</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Daftar Pengguna</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Nama Lengkap</th>
                    <th>Role</th>
                    <th>Jumlah Transaksi</th>
                    <th>Dibuat</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="7">Belum ada pengguna.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $item): ?>
                    <tr>
                        <td><?= (int) $item['id'] ?></td>
                        <td><?= e($item['username']) ?></td>
                        <td><?= e($item['full_name']) ?></td>

                        <td class="role">
                            <?= e(strtoupper($item['role'])) ?>
                        </td>

                        <td><?= (int) $item['total_transactions'] ?></td>
                        <td><?= e($item['created_at']) ?></td>

                        <td>
                            <div class="actions">
                                <a href="?edit=<?= (int) $item['id'] ?>">
                                    Edit
                                </a>

                                <?php if (
                                    (int) $item['id'] !==
                                    (int) $_SESSION['user']['id']
                                ): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Hapus pengguna ini?')">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete">

                                        <input type="hidden"
                                               name="id"
                                               value="<?= (int) $item['id'] ?>">

                                        <button class="delete-button"
                                                type="submit">
                                            Hapus
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
