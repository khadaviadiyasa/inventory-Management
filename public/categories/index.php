<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

require_login();
require_role('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = 'Permintaan tidak valid. Silakan coba lagi.';
        header('Location: index.php');
        exit;
    }

    try {
        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $id = (int) ($_POST['id'] ?? 0);

            if ($name === '' || strlen($name) > 100) {
                throw new RuntimeException(
                    'Nama kategori wajib diisi, maksimal 100 karakter.'
                );
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO categories (name, description)
                     VALUES (:name, :description)'
                );

                $stmt->execute([
                    'name' => $name,
                    'description' => $description ?: null
                ]);

                $_SESSION['flash'] = 'Kategori berhasil ditambahkan.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('ID kategori tidak valid.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE categories
                     SET name = :name, description = :description
                     WHERE id = :id'
                );

                $stmt->execute([
                    'name' => $name,
                    'description' => $description ?: null,
                    'id' => $id
                ]);

                $_SESSION['flash'] = 'Kategori berhasil diperbarui.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE category_id = ?'
            );
            $stmt->execute([$id]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Kategori tidak dapat dihapus karena masih digunakan produk.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM categories WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['flash'] = 'Kategori berhasil dihapus.';
        }
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
    } catch (PDOException $e) {
        $_SESSION['flash'] =
            'Gagal menyimpan data. Pastikan nama kategori belum digunakan.';
    }

    header('Location: index.php');
    exit;
}

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$editCategory = null;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM categories WHERE id = ?'
    );
    $stmt->execute([(int) $_GET['edit']]);
    $editCategory = $stmt->fetch();

    if (!$editCategory) {
        $message = 'Kategori tidak ditemukan.';
    }
}

$stmt = $pdo->query(
    'SELECT c.id, c.name, c.description, c.created_at,
            COUNT(p.id) AS total_products
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id, c.name, c.description, c.created_at
     ORDER BY c.name ASC'
);

$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Kategori</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #f4f6f9;
            color: #222;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .panel {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        input, textarea, button, .button {
            padding: 10px;
            margin: 5px 0;
            box-sizing: border-box;
        }

        input, textarea {
            width: 100%;
        }

        button, .button {
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #e9eef5;
        }

        .message {
            padding: 12px;
            background: #e0f2fe;
            margin-bottom: 15px;
        }

        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .actions form {
            margin: 0;
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Manajemen Kategori</h1>

    <p>
        <a href="../dashboard.php">Dashboard</a> |
        <a href="../products/index.php">Data Produk</a>
    </p>

    <?php if ($message !== ''): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>
            <?= $editCategory ? 'Edit Kategori' : 'Tambah Kategori' ?>
        </h2>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action"
                   value="<?= $editCategory ? 'update' : 'create' ?>">

            <?php if ($editCategory): ?>
                <input type="hidden" name="id"
                       value="<?= (int) $editCategory['id'] ?>">
            <?php endif; ?>

            <label>Nama kategori</label>
            <input type="text" name="name" maxlength="100"
                   value="<?= e($editCategory['name'] ?? '') ?>"
                   required>

            <label>Deskripsi</label>
            <textarea name="description" rows="3"><?= e(
                $editCategory['description'] ?? ''
            ) ?></textarea>

            <button type="submit">
                <?= $editCategory ? 'Simpan Perubahan' : 'Tambah Kategori' ?>
            </button>

            <?php if ($editCategory): ?>
                <a class="button" href="index.php">Batal</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Daftar Kategori</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama</th>
                    <th>Deskripsi</th>
                    <th>Jumlah Produk</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$categories): ?>
                <tr>
                    <td colspan="5">Belum ada kategori.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?= (int) $category['id'] ?></td>
                        <td><?= e($category['name']) ?></td>
                        <td><?= e($category['description'] ?? '-') ?></td>
                        <td><?= (int) $category['total_products'] ?></td>
                        <td>
                            <div class="actions">
                                <a href="?edit=<?= (int) $category['id'] ?>">
                                    Edit
                                </a>

                                <form method="POST"
                                      onsubmit="return confirm('Hapus kategori ini?')">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action"
                                           value="delete">
                                    <input type="hidden" name="id"
                                           value="<?= (int) $category['id'] ?>">

                                    <button type="submit">Hapus</button>
                                </form>
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