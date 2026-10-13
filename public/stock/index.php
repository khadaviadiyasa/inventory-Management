<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

require_login();

$role = $_SESSION['user']['role'] ?? '';

if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| PROSES TRANSAKSI BARANG MASUK DAN KELUAR
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        $_SESSION['flash'] = 'Permintaan tidak valid.';
        header('Location: index.php');
        exit;
    }

    try {
        $productId = filter_var(
            $_POST['product_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $_POST['quantity'] ?? '',
            FILTER_VALIDATE_INT
        );

        $type = $_POST['type'] ?? '';
        $note = trim($_POST['note'] ?? '');

        if ($productId === false || $productId < 1) {
            throw new RuntimeException('Pilih produk yang valid.');
        }

        if (
            $quantity === false ||
            $quantity < 1 ||
            $quantity > 2147483647
        ) {
            throw new RuntimeException(
                'Jumlah transaksi harus berupa bilangan bulat positif.'
            );
        }

        if (!in_array($type, ['IN', 'OUT'], true)) {
            throw new RuntimeException(
                'Jenis transaksi tidak valid.'
            );
        }

        if (strlen($note) > 2000) {
            throw new RuntimeException(
                'Catatan maksimal 2000 byte.'
            );
        }

        $userId = (int) $_SESSION['user']['id'];

        /*
         * Mulai transaksi database.
         */
        $pdo->beginTransaction();

        /*
         * Kunci baris produk agar stok tidak berubah bersamaan
         * ketika ada transaksi lain pada produk yang sama.
         */
        $stmt = $pdo->prepare(
            'SELECT id, name, stock
             FROM products
             WHERE id = :id
             FOR UPDATE'
        );

        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException(
                'Produk tidak ditemukan.'
            );
        }

        $currentStock = (int) $product['stock'];

        /*
         * BARANG MASUK
         */
        if ($type === 'IN') {

            if ($currentStock > 2147483647 - $quantity) {
                throw new RuntimeException(
                    'Jumlah stok melebihi batas yang diizinkan.'
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE products
                 SET stock = stock + :quantity
                 WHERE id = :id'
            );

            $stmt->execute([
                'quantity' => $quantity,
                'id' => $productId
            ]);

        /*
         * BARANG KELUAR
         */
        } else {

            if ($quantity > $currentStock) {
                throw new RuntimeException(
                    'Stok tidak mencukupi. Stok tersedia: '
                    . $currentStock
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE products
                 SET stock = stock - :quantity
                 WHERE id = :id'
            );

            $stmt->execute([
                'quantity' => $quantity,
                'id' => $productId
            ]);
        }

        /*
         * CATAT HISTORI TRANSAKSI
         */
        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements
                (product_id, user_id, type, quantity, note)
             VALUES
                (:product_id, :user_id, :type, :quantity, :note)'
        );

        $stmt->execute([
            'product_id' => $productId,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'note' => $note !== '' ? $note : null
        ]);

        /*
         * Jika update stok dan penyimpanan histori berhasil,
         * simpan seluruh perubahan.
         */
        $pdo->commit();

        $_SESSION['flash'] =
            ($type === 'IN' ? 'Barang masuk' : 'Barang keluar')
            . ' berhasil dicatat untuk produk '
            . $product['name']
            . '. Jumlah: '
            . $quantity
            . '.';

    } catch (RuntimeException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['flash'] = $e->getMessage();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($e->getMessage());

        $_SESSION['flash'] =
            'Transaksi gagal diproses. Perubahan telah dibatalkan.';
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL PESAN
|--------------------------------------------------------------------------
*/

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/*
|--------------------------------------------------------------------------
| AMBIL DATA PRODUK
|--------------------------------------------------------------------------
*/

$products = $pdo->query(
    'SELECT id, sku, name, stock, minimum_stock
     FROM products
     ORDER BY name ASC'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| RINGKASAN TRANSAKSI
|--------------------------------------------------------------------------
*/

$summary = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE
            WHEN type = 'IN' THEN quantity
            ELSE 0
        END), 0) AS total_in,

        COALESCE(SUM(CASE
            WHEN type = 'OUT' THEN quantity
            ELSE 0
        END), 0) AS total_out
     FROM stock_movements"
)->fetch();

/*
|--------------------------------------------------------------------------
| HISTORI TRANSAKSI TERBARU
|--------------------------------------------------------------------------
*/

$history = $pdo->query(
    'SELECT
        sm.id,
        sm.type,
        sm.quantity,
        sm.note,
        sm.created_at,
        p.sku,
        p.name AS product_name,
        u.full_name,
        u.username
     FROM stock_movements sm
     INNER JOIN products p ON p.id = sm.product_id
     INNER JOIN users u ON u.id = sm.user_id
     ORDER BY sm.id DESC
     LIMIT 30'
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Transaksi Stok - Inventory</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #1e293b;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            margin-bottom: 8px;
        }

        .navigation {
            margin-bottom: 25px;
        }

        .navigation a {
            margin-right: 15px;
            color: #2563eb;
            text-decoration: none;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .card,
        .panel {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px #0000000a;
        }

        .card h3 {
            margin: 0 0 12px;
            font-size: 14px;
            color: #64748b;
        }

        .number {
            font-size: 26px;
            font-weight: bold;
        }

        .panel {
            margin-bottom: 22px;
        }

        .field {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font: inherit;
        }

        textarea {
            resize: vertical;
        }

        button {
            border: 0;
            padding: 12px 18px;
            background: #2563eb;
            color: white;
            font-size: 14px;
            border-radius: 6px;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .message {
            padding: 14px;
            background: #dbeafe;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 12px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }

        .in {
            background: #dcfce7;
            color: #166534;
        }

        .out {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #64748b;
        }

        @media (max-width: 700px) {
            body {
                padding: 14px;
            }

            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Transaksi Stok</h1>

    <p>
        Kelola barang masuk dan barang keluar.
    </p>

    <div class="navigation">
        <a href="../dashboard.php">Dashboard</a>
        <a href="../products/index.php">Produk</a>
        <a href="../categories/index.php">Kategori</a>
        <a href="../suppliers/index.php">Supplier</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="cards">

        <div class="card">
            <h3>Total Unit Barang Masuk</h3>

            <div class="number">
                <?= number_format((int) $summary['total_in']) ?>
            </div>
        </div>

        <div class="card">
            <h3>Total Unit Barang Keluar</h3>

            <div class="number">
                <?= number_format((int) $summary['total_out']) ?>
            </div>
        </div>

        <div class="card">
            <h3>Total Produk</h3>

            <div class="number">
                <?= count($products) ?>
            </div>
        </div>

    </div>

    <div class="panel">

        <h2>Catat Transaksi</h2>

        <?php if (!$products): ?>

            <p>
                Belum ada produk. Tambahkan produk terlebih dahulu
                melalui halaman manajemen produk.
            </p>

        <?php else: ?>

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">

                <div class="field">

                    <label for="type">Jenis Transaksi</label>

                    <select name="type" id="type" required>
                        <option value="IN">Barang Masuk</option>
                        <option value="OUT">Barang Keluar</option>
                    </select>

                </div>

                <div class="field">

                    <label for="product_id">Produk</label>

                    <select name="product_id"
                            id="product_id"
                            required>

                        <option value="">
                            -- Pilih produk --
                        </option>

                        <?php foreach ($products as $product): ?>

                            <option value="<?= (int) $product['id'] ?>">
                                <?= e($product['sku']) ?>
                                -
                                <?= e($product['name']) ?>
                                (Stok: <?= (int) $product['stock'] ?>)
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="field">

                    <label for="quantity">Jumlah</label>

                    <input
                        type="number"
                        name="quantity"
                        id="quantity"
                        min="1"
                        step="1"
                        max="2147483647"
                        required
                    >

                </div>

                <div class="field">

                    <label for="note">Catatan</label>

                    <textarea
                        name="note"
                        id="note"
                        rows="3"
                        maxlength="2000"
                        placeholder="Contoh: Pembelian stok dari supplier"
                    ></textarea>

                </div>

                <button type="submit">
                    Simpan Transaksi
                </button>

            </form>

        <?php endif; ?>

    </div>

    <div class="panel">

        <h2>Histori Transaksi Terbaru</h2>

        <p>
            Menampilkan maksimal 30 transaksi terakhir.
        </p>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tanggal</th>
                        <th>SKU</th>
                        <th>Produk</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Pengguna</th>
                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$history): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada histori transaksi.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($history as $item): ?>

                        <tr>

                            <td>
                                <?= (int) $item['id'] ?>
                            </td>

                            <td>
                                <?= e($item['created_at']) ?>
                            </td>

                            <td>
                                <?= e($item['sku']) ?>
                            </td>

                            <td>
                                <?= e($item['product_name']) ?>
                            </td>

                            <td>
                                <?php if ($item['type'] === 'IN'): ?>
                                    <span class="badge in">
                                        Barang Masuk
                                    </span>
                                <?php else: ?>
                                    <span class="badge out">
                                        Barang Keluar
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (int) $item['quantity']
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $item['full_name']
                                    ?: $item['username']
                                ) ?>
                            </td>

                            <td>
                                <?= e($item['note'] ?? '-') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>