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
        header('Location: product_links.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $priceRaw = $_POST['supplier_price'] ?? '';

            if ($productId <= 0 || $supplierId <= 0) {
                throw new RuntimeException(
                    'Pilih produk dan supplier yang valid.'
                );
            }

            if (
                $priceRaw === '' ||
                !is_numeric($priceRaw) ||
                !is_finite((float) $priceRaw) ||
                (float) $priceRaw < 0 ||
                (float) $priceRaw > 9999999999.99
            ) {
                throw new RuntimeException(
                    'Harga beli harus berupa angka nonnegatif '
                    . 'dengan maksimal dua angka desimal.'
                );
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE id = ?'
            );
            $stmt->execute([$productId]);

            if ((int) $stmt->fetchColumn() === 0) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM suppliers WHERE id = ?'
            );
            $stmt->execute([$supplierId]);

            if ((int) $stmt->fetchColumn() === 0) {
                throw new RuntimeException('Supplier tidak ditemukan.');
            }

            $price = round((float) $priceRaw, 2);

            $stmt = $pdo->prepare(
                'INSERT INTO product_suppliers
                    (product_id, supplier_id, supplier_price)
                 VALUES
                    (:product_id, :supplier_id, :supplier_price)
                 ON DUPLICATE KEY UPDATE
                    supplier_price = :updated_price'
            );

            $stmt->execute([
                'product_id' => $productId,
                'supplier_id' => $supplierId,
                'supplier_price' => $price,
                'updated_price' => $price
            ]);

            $_SESSION['flash'] =
                'Relasi produk dan supplier berhasil disimpan.';
        } elseif ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);

            $stmt = $pdo->prepare(
                'DELETE FROM product_suppliers
                 WHERE product_id = :product_id
                   AND supplier_id = :supplier_id'
            );

            $stmt->execute([
                'product_id' => $productId,
                'supplier_id' => $supplierId
            ]);

            $_SESSION['flash'] = 'Relasi berhasil dihapus.';
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['flash'] = 'Gagal menyimpan relasi produk–supplier.';
    }

    header('Location: product_links.php');
    exit;
}

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$products = $pdo->query(
    'SELECT id, sku, name FROM products ORDER BY name ASC'
)->fetchAll();

$suppliers = $pdo->query(
    'SELECT id, name FROM suppliers ORDER BY name ASC'
)->fetchAll();

$links = $pdo->query(
    'SELECT ps.product_id, ps.supplier_id, ps.supplier_price,
            p.sku, p.name AS product_name,
            s.name AS supplier_name
     FROM product_suppliers ps
     INNER JOIN products p ON p.id = ps.product_id
     INNER JOIN suppliers s ON s.id = ps.supplier_id
     ORDER BY p.name ASC, s.name ASC'
)->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relasi Produk dan Supplier</title>

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

        select, input, button {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            margin: 6px 0 14px;
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

    <h1>Relasi Produk–Supplier</h1>

    <p>
        <a href="../dashboard.php">Dashboard</a> |
        <a href="index.php">Manajemen Supplier</a> |
        <a href="../products/index.php">Manajemen Produk</a>
    </p>

    <?php if ($message !== ''): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Hubungkan Produk dengan Supplier</h2>

        <?php if (!$products || !$suppliers): ?>
            <p>
                Kamu perlu memiliki minimal satu produk dan satu supplier
                sebelum membuat relasi.
            </p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action" value="save">

                <label>Produk</label>
                <select name="product_id" required>
                    <option value="">-- Pilih produk --</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>">
                            <?= e($product['sku']) ?> -
                            <?= e($product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Supplier</label>
                <select name="supplier_id" required>
                    <option value="">-- Pilih supplier --</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>">
                            <?= e($supplier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Harga beli dari supplier (Rp)</label>
                <input type="number" name="supplier_price"
                       min="0" max="9999999999.99"
                       step="0.01" required>

                <button type="submit">Simpan Relasi</button>
            </form>

            <p>
                Jika pasangan produk dan supplier sudah ada, menyimpan
                pasangan yang sama akan memperbarui harga belinya.
            </p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Daftar Relasi</h2>

        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Produk</th>
                    <th>Supplier</th>
                    <th>Harga Beli</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$links): ?>
                <tr>
                    <td colspan="5">Belum ada relasi produk–supplier.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($links as $link): ?>
                    <tr>
                        <td><?= e($link['sku']) ?></td>
                        <td><?= e($link['product_name']) ?></td>
                        <td><?= e($link['supplier_name']) ?></td>
                        <td>
                            Rp <?= number_format(
                                (float) $link['supplier_price'],
                                2,
                                ',',
                                '.'
                            ) ?>
                        </td>
                        <td>
                            <form method="POST"
                                  onsubmit="return confirm('Hapus relasi ini?')">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">

                                <input type="hidden" name="action"
                                       value="delete">

                                <input type="hidden" name="product_id"
                                       value="<?= (int) $link['product_id'] ?>">

                                <input type="hidden" name="supplier_id"
                                       value="<?= (int) $link['supplier_id'] ?>">

                                <button type="submit">Hapus Relasi</button>
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