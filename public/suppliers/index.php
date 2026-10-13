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

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = 'Permintaan tidak valid.';
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '' || strlen($name) > 150) {
                throw new RuntimeException(
                    'Nama supplier wajib diisi, maksimal 150 karakter.'
                );
            }

            if (strlen($phone) > 30) {
                throw new RuntimeException(
                    'Nomor telepon maksimal 30 karakter.'
                );
            }

            if (strlen($email) > 100) {
                throw new RuntimeException(
                    'Email maksimal 100 karakter.'
                );
            }

            if (
                $email !== '' &&
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException('Format email tidak valid.');
            }

            if (strlen($address) > 10000) {
                throw new RuntimeException('Alamat terlalu panjang.');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO suppliers (name, phone, email, address)
                     VALUES (:name, :phone, :email, :address)'
                );

                $stmt->execute([
                    'name' => $name,
                    'phone' => $phone ?: null,
                    'email' => $email ?: null,
                    'address' => $address ?: null
                ]);

                $_SESSION['flash'] = 'Supplier berhasil ditambahkan.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('ID supplier tidak valid.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE suppliers
                     SET name = :name,
                         phone = :phone,
                         email = :email,
                         address = :address
                     WHERE id = :id'
                );

                $stmt->execute([
                    'name' => $name,
                    'phone' => $phone ?: null,
                    'email' => $email ?: null,
                    'address' => $address ?: null,
                    'id' => $id
                ]);

                $_SESSION['flash'] = 'Supplier berhasil diperbarui.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID supplier tidak valid.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM product_suppliers
                 WHERE supplier_id = ?'
            );
            $stmt->execute([$id]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Supplier tidak dapat dihapus karena masih terhubung '
                    . 'dengan produk. Hapus relasinya terlebih dahulu.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM suppliers WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['flash'] = 'Supplier berhasil dihapus.';
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['flash'] = 'Gagal menyimpan data supplier.';
    }

    header('Location: index.php');
    exit;
}

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$editSupplier = null;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM suppliers WHERE id = ?'
    );
    $stmt->execute([(int) $_GET['edit']]);
    $editSupplier = $stmt->fetch();

    if (!$editSupplier) {
        $message = 'Supplier tidak ditemukan.';
    }
}

$suppliers = $pdo->query(
    'SELECT s.id, s.name, s.phone, s.email, s.address,
            COUNT(ps.product_id) AS total_products
     FROM suppliers s
     LEFT JOIN product_suppliers ps ON ps.supplier_id = s.id
     GROUP BY s.id, s.name, s.phone, s.email, s.address
     ORDER BY s.name ASC'
)->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Supplier</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #f4f6f9;
            color: #222;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .panel {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow-x: auto;
        }

        input, textarea, button {
            box-sizing: border-box;
            padding: 10px;
            margin: 5px 0;
        }

        input, textarea {
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #e9eef5;
        }

        .message {
            background: #e0f2fe;
            padding: 12px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

<div class="container">

    <h1>Manajemen Supplier</h1>

    <p>
        <a href="../dashboard.php">Dashboard</a> |
        <a href="../categories/index.php">Kategori</a> |
        <a href="../products/index.php">Produk</a> |
        <a href="product_links.php">Relasi Produk–Supplier</a>
    </p>

    <?php if ($message !== ''): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>
            <?= $editSupplier ? 'Edit Supplier' : 'Tambah Supplier' ?>
        </h2>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action"
                   value="<?= $editSupplier ? 'update' : 'create' ?>">

            <?php if ($editSupplier): ?>
                <input type="hidden" name="id"
                       value="<?= (int) $editSupplier['id'] ?>">
            <?php endif; ?>

            <label>Nama supplier</label>
            <input type="text" name="name" maxlength="150" required
                   value="<?= e($editSupplier['name'] ?? '') ?>">

            <label>Nomor telepon</label>
            <input type="text" name="phone" maxlength="30"
                   value="<?= e($editSupplier['phone'] ?? '') ?>">

            <label>Email</label>
            <input type="email" name="email" maxlength="100"
                   value="<?= e($editSupplier['email'] ?? '') ?>">

            <label>Alamat</label>
            <textarea name="address" rows="3"><?= e(
                $editSupplier['address'] ?? ''
            ) ?></textarea>

            <button type="submit">
                <?= $editSupplier ? 'Simpan Perubahan' : 'Tambah Supplier' ?>
            </button>

            <?php if ($editSupplier): ?>
                <a href="index.php">Batal</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Daftar Supplier</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama</th>
                    <th>Telepon</th>
                    <th>Email</th>
                    <th>Alamat</th>
                    <th>Jumlah Produk</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$suppliers): ?>
                <tr>
                    <td colspan="7">Belum ada supplier.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?= (int) $supplier['id'] ?></td>
                        <td><?= e($supplier['name']) ?></td>
                        <td><?= e($supplier['phone'] ?? '-') ?></td>
                        <td><?= e($supplier['email'] ?? '-') ?></td>
                        <td><?= e($supplier['address'] ?? '-') ?></td>
                        <td><?= (int) $supplier['total_products'] ?></td>

                        <td>
                            <a href="?edit=<?= (int) $supplier['id'] ?>">
                                Edit
                            </a>

                            <form method="POST"
                                  onsubmit="return confirm('Hapus supplier ini?')">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action"
                                       value="delete">
                                <input type="hidden" name="id"
                                       value="<?= (int) $supplier['id'] ?>">

                                <button type="submit">Hapus</button>
                            </form>
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