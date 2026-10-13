<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/escape.php';

require_login();

$user = $_SESSION['user'];

/*
|--------------------------------------------------------------------------
| STATISTIK INVENTORY
|--------------------------------------------------------------------------
*/

$stats = $pdo->query(
    'SELECT
        COUNT(*) AS total_products,
        COALESCE(SUM(stock * price), 0) AS inventory_value,
        COALESCE(SUM(stock), 0) AS total_stock,
        COALESCE(SUM(
            CASE
                WHEN stock <= minimum_stock THEN 1
                ELSE 0
            END
        ), 0) AS low_stock_count
     FROM products'
)->fetch();

/*
|--------------------------------------------------------------------------
| TOTAL TRANSAKSI BARANG MASUK DAN KELUAR
|--------------------------------------------------------------------------
*/

$movementStats = $pdo->query(
    "SELECT
        COALESCE(SUM(
            CASE WHEN type = 'IN' THEN quantity ELSE 0 END
        ), 0) AS total_in,

        COALESCE(SUM(
            CASE WHEN type = 'OUT' THEN quantity ELSE 0 END
        ), 0) AS total_out
     FROM stock_movements"
)->fetch();

/*
|--------------------------------------------------------------------------
| JUMLAH KATEGORI DAN SUPPLIER
|--------------------------------------------------------------------------
*/

$totalCategories = (int) $pdo->query(
    'SELECT COUNT(*) FROM categories'
)->fetchColumn();

$totalSuppliers = (int) $pdo->query(
    'SELECT COUNT(*) FROM suppliers'
)->fetchColumn();

/*
|--------------------------------------------------------------------------
| DAFTAR PRODUK DENGAN STOK RENDAH
|--------------------------------------------------------------------------
*/

$lowStockProducts = $pdo->query(
    'SELECT
        p.id,
        p.sku,
        p.name,
        p.stock,
        p.minimum_stock,
        c.name AS category_name
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     WHERE p.stock <= p.minimum_stock
     ORDER BY p.stock ASC, p.name ASC
     LIMIT 10'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| HISTORI TRANSAKSI TERBARU
|--------------------------------------------------------------------------
*/

$recentMovements = $pdo->query(
    'SELECT
        sm.id,
        sm.type,
        sm.quantity,
        sm.created_at,
        p.sku,
        p.name AS product_name,
        u.full_name,
        u.username
     FROM stock_movements sm
     INNER JOIN products p ON p.id = sm.product_id
     INNER JOIN users u ON u.id = sm.user_id
     ORDER BY sm.id DESC
     LIMIT 8'
)->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Dashboard Inventory</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            width: 235px;
            padding: 25px 16px;
            background: #172554;
            color: white;
        }

        .brand {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 35px;
        }

        .sidebar a {
            display: block;
            padding: 12px;
            margin-bottom: 7px;
            border-radius: 6px;
            color: #dbeafe;
            text-decoration: none;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #2563eb;
            color: white;
        }

        .main {
            margin-left: 235px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .topbar h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .muted {
            color: #64748b;
            font-size: 14px;
        }

        .user-info {
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
        }

        .logout-form {
            display: inline;
        }

        .logout {
            padding: 9px 14px;
            margin-left: 8px;
            border: 0;
            border-radius: 6px;
            background: #dc2626;
            color: white;
            cursor: pointer;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            padding: 22px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px #0f172a08;
        }

        .stat-title {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 14px;
        }

        .stat-number {
            font-size: 26px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .stat-note {
            margin-top: 10px;
            color: #64748b;
            font-size: 12px;
        }

        .panel {
            padding: 22px;
            margin-bottom: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px #0f172a08;
        }

        .panel h2 {
            margin-top: 0;
            font-size: 19px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: #475569;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-in {
            background: #dcfce7;
            color: #166534;
        }

        .badge-out {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-low {
            background: #fef3c7;
            color: #92400e;
        }

        .empty {
            padding: 24px;
            text-align: center;
            color: #64748b;
        }

        .footer {
            margin-top: 30px;
            color: #64748b;
            font-size: 13px;
            text-align: center;
        }

        @media (max-width: 1000px) {
            .sidebar {
                width: 190px;
            }

            .main {
                margin-left: 190px;
                padding: 20px;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .sidebar {
                position: relative;
                width: 100%;
                padding: 18px;
            }

            .brand {
                margin-bottom: 16px;
            }

            .sidebar a {
                display: inline-block;
                padding: 9px;
            }

            .main {
                margin-left: 0;
                padding: 16px;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        Inventory App
    </div>

    <a href="dashboard.php" class="active">
        Dashboard
    </a>

    <a href="categories/index.php">
        Kategori
    </a>

    <a href="products/index.php">
        Produk
    </a>

    <a href="suppliers/index.php">
        Supplier
    </a>

    <a href="suppliers/product_links.php">
        Relasi Supplier
    </a>

    <a href="stock/index.php">
        Transaksi Stok
    </a>

</aside>

<main class="main">

    <div class="topbar">

        <div>
            <h1>Dashboard Inventory</h1>

            <div class="muted">
                Ringkasan informasi persediaan barang.
            </div>
        </div>

        <div class="user-info">

            <strong>
                <?= e($user['full_name']) ?>
            </strong>

            <span class="muted">
                (<?= e($user['role']) ?>)
            </span>

            <form
                class="logout-form"
                action="logout.php"
                method="POST"
            >
                <button type="submit" class="logout">
                    Logout
                </button>
            </form>

        </div>

    </div>

    <!-- STATISTIK UTAMA -->

    <div class="stats">

        <div class="stat-card">
            <div class="stat-title">Total Produk</div>

            <div class="stat-number">
                <?= number_format((int) $stats['total_products']) ?>
            </div>

            <div class="stat-note">
                Jumlah jenis produk terdaftar
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Unit Stok</div>

            <div class="stat-number">
                <?= number_format((int) $stats['total_stock']) ?>
            </div>

            <div class="stat-note">
                Jumlah stok seluruh produk
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Nilai Inventory</div>

            <div class="stat-number">
                Rp <?= number_format(
                    (float) $stats['inventory_value'],
                    0,
                    ',',
                    '.'
                ) ?>
            </div>

            <div class="stat-note">
                Total stok dikali harga produk
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Produk Stok Rendah</div>

            <div class="stat-number">
                <?= number_format((int) $stats['low_stock_count']) ?>
            </div>

            <div class="stat-note">
                Stok kurang dari atau sama dengan batas minimum
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Barang Masuk</div>

            <div class="stat-number">
                <?= number_format((int) $movementStats['total_in']) ?>
            </div>

            <div class="stat-note">
                Akumulasi unit barang masuk
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Barang Keluar</div>

            <div class="stat-number">
                <?= number_format((int) $movementStats['total_out']) ?>
            </div>

            <div class="stat-note">
                Akumulasi unit barang keluar
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Kategori</div>

            <div class="stat-number">
                <?= number_format($totalCategories) ?>
            </div>

            <div class="stat-note">
                Kategori produk terdaftar
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Supplier</div>

            <div class="stat-number">
                <?= number_format($totalSuppliers) ?>
            </div>

            <div class="stat-note">
                Supplier terdaftar
            </div>
        </div>

    </div>

    <!-- LAPORAN STOK RENDAH -->

    <section class="panel">

        <h2>Produk dengan Stok Rendah</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Kategori</th>
                        <th>Stok Saat Ini</th>
                        <th>Minimum Stok</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$lowStockProducts): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            Tidak ada produk dengan stok rendah.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($lowStockProducts as $product): ?>

                        <tr>

                            <td><?= e($product['sku']) ?></td>

                            <td><?= e($product['name']) ?></td>

                            <td><?= e($product['category_name']) ?></td>

                            <td>
                                <?= number_format((int) $product['stock']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (int) $product['minimum_stock']
                                ) ?>
                            </td>

                            <td>
                                <span class="badge badge-low">
                                    Stok Rendah
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- HISTORI TERBARU -->

    <section class="panel">

        <h2>Histori Transaksi Terbaru</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>SKU</th>
                        <th>Produk</th>
                        <th>Jenis Transaksi</th>
                        <th>Jumlah</th>
                        <th>Pengguna</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$recentMovements): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            Belum ada transaksi stok.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($recentMovements as $movement): ?>

                        <tr>

                            <td><?= e($movement['created_at']) ?></td>

                            <td><?= e($movement['sku']) ?></td>

                            <td><?= e($movement['product_name']) ?></td>

                            <td>
                                <?php if ($movement['type'] === 'IN'): ?>

                                    <span class="badge badge-in">
                                        Barang Masuk
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-out">
                                        Barang Keluar
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?= number_format((int) $movement['quantity']) ?>
                            </td>

                            <td>
                                <?= e(
                                    $movement['full_name']
                                    ?: $movement['username']
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p>
            <a href="stock/index.php">
                Lihat halaman transaksi stok
            </a>
        </p>

    </section>

    <div class="footer">
        Inventory Management System
    
    
    </div>

</main>

</body>
</html>
<?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
    <a href="users/index.php">Manajemen Pengguna</a>
<?php endif; ?>