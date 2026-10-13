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

$isAdmin = ($role === 'admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* PROSES TAMBAH, EDIT, DAN HAPUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Staff tidak diizinkan mengubah data produk.');
    }

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
            $sku = trim($_POST['sku'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $priceRaw = $_POST['price'] ?? '';
            $minimumStockRaw = $_POST['minimum_stock'] ?? '';

            if ($sku === '' || strlen($sku) > 50) {
                throw new RuntimeException(
                    'SKU wajib diisi, maksimal 50 karakter.'
                );
            }

            if ($name === '' || strlen($name) > 150) {
                throw new RuntimeException(
                    'Nama produk wajib diisi, maksimal 150 karakter.'
                );
            }

            if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                throw new RuntimeException(
                    'Harga harus berupa angka dan tidak boleh negatif.'
                );
            }

            $minimumStock = filter_var(
                $minimumStockRaw,
                FILTER_VALIDATE_INT
            );

            if ($minimumStock === false || $minimumStock < 0) {
                throw new RuntimeException(
                    'Minimum stok harus berupa bilangan bulat nonnegatif.'
                );
            }

            if ($categoryId <= 0) {
                throw new RuntimeException('Pilih kategori yang valid.');
            }

            $checkCategory = $pdo->prepare(
                'SELECT id FROM categories WHERE id = ?'
            );
            $checkCategory->execute([$categoryId]);

            if (!$checkCategory->fetch()) {
                throw new RuntimeException('Kategori tidak ditemukan.');
            }

            $price = (float) $priceRaw;

            if ($action === 'create') {
                /*
                 * Stok awal selalu 0.
                 * Stok selanjutnya berubah melalui transaksi barang masuk.
                 */
                $stmt = $pdo->prepare(
                    'INSERT INTO products
                        (category_id, sku, name, description,
                         price, stock, minimum_stock)
                     VALUES
                        (:category_id, :sku, :name, :description,
                         :price, 0, :minimum_stock)'
                );

                $stmt->execute([
                    'category_id' => $categoryId,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description ?: null,
                    'price' => $price,
                    'minimum_stock' => $minimumStock
                ]);

                $_SESSION['flash'] =
                    'Produk berhasil ditambahkan. Stok awal adalah 0.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('ID produk tidak valid.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE products
                     SET category_id = :category_id,
                         sku = :sku,
                         name = :name,
                         description = :description,
                         price = :price,
                         minimum_stock = :minimum_stock
                     WHERE id = :id'
                );

                $stmt->execute([
                    'category_id' => $categoryId,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description ?: null,
                    'price' => $price,
                    'minimum_stock' => $minimumStock,
                    'id' => $id
                ]);

                $_SESSION['flash'] = 'Produk berhasil diperbarui.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID produk tidak valid.');
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_movements
                 WHERE product_id = ?'
            );
            $stmt->execute([$id]);
            $hasMovements = (int) $stmt->fetchColumn() > 0;

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM product_suppliers
                 WHERE product_id = ?'
            );
            $stmt->execute([$id]);
            $hasSuppliers = (int) $stmt->fetchColumn() > 0;

            if ($hasMovements || $hasSuppliers) {
                throw new RuntimeException(
                    'Produk tidak dapat dihapus karena memiliki histori '
                    . 'stok atau relasi supplier.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM products WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['flash'] = 'Produk berhasil dihapus.';
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
    } catch (PDOException $e) {
        $_SESSION['flash'] =
            'Gagal menyimpan data. Periksa SKU unik dan relasi database.';
    }

    header('Location: index.php');
    exit;
}

/* PESAN */
$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* KATEGORI UNTUK DROPDOWN */
$categories = $pdo->query(
    'SELECT id, name FROM categories ORDER BY name ASC'
)->fetchAll();

/* FORM EDIT */
$editProduct = null;

if ($isAdmin && isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM products WHERE id = ?'
    );
    $stmt->execute([(int) $_GET['edit']]);
    $editProduct = $stmt->fetch();

    if (!$editProduct) {
        $message = 'Produk tidak ditemukan.';
    }
}

/* SEARCH, FILTER, SORTING */
$search = trim($_GET['q'] ?? '');
$categoryFilter = max(0, (int) ($_GET['category_id'] ?? 0));

$sortColumns = [
    'name' => 'p.name',
    'sku' => 'p.sku',
    'price' => 'p.price',
    'stock' => 'p.stock',
    'created_at' => 'p.created_at'
];

$sort = $_GET['sort'] ?? 'name';

if (!isset($sortColumns[$sort])) {
    $sort = 'name';
}

$direction = strtoupper($_GET['direction'] ?? 'ASC');

if (!in_array($direction, ['ASC', 'DESC'], true)) {
    $direction = 'ASC';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :q_name OR p.sku LIKE :q_sku)';
    $params['q_name'] = '%' . $search . '%';
    $params['q_sku'] = '%' . $search . '%';
}

if ($categoryFilter > 0) {
    $where[] = 'p.category_id = :category_id';
    $params['category_id'] = $categoryFilter;
}

$whereSql = $where
    ? ' WHERE ' . implode(' AND ', $where)
    : '';

/* TOTAL DATA */
$countSql = "
    SELECT COUNT(*)
    FROM products p
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalProducts = (int) $countStmt->fetchColumn();

/* PAGINATION */
$perPage = 10;
$totalPages = max(1, (int) ceil($totalProducts / $perPage));

$page = max(1, (int) ($_GET['page'] ?? 1));
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

/* AMBIL PRODUK */
$sql = "
    SELECT p.id, p.sku, p.name, p.description,
           p.price, p.stock, p.minimum_stock, p.created_at,
           c.name AS category_name
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY {$sortColumns[$sort]} $direction
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $type = is_int($value)
        ? PDO::PARAM_INT
        : PDO::PARAM_STR;

    $stmt->bindValue(':' . $key, $value, $type);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$products = $stmt->fetchAll();

$pageParams = [
    'q' => $search,
    'category_id' => $categoryFilter,
    'sort' => $sort,
    'direction' => $direction
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Produk</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #f4f6f9;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .panel {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow-x: auto;
        }

        input, select, textarea, button {
            padding: 10px;
            margin: 5px 0;
            box-sizing: border-box;
        }

        input, select, textarea {
            max-width: 100%;
        }

        .field {
            margin-bottom: 12px;
        }

        .field input, .field select, .field textarea {
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
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
            padding: 12px;
            background: #e0f2fe;
            margin-bottom: 15px;
        }

        .low-stock {
            color: #b91c1c;
            font-weight: bold;
        }

        .pagination {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 8px 12px;
            background: #e9eef5;
            text-decoration: none;
        }

        .actions form {
            display: inline;
        }

        .filters {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Manajemen Produk</h1>

    <p>
        <a href="../dashboard.php">Dashboard</a> |
        <a href="../categories/index.php">Data Kategori</a>
    </p>

    <?php if ($message !== ''): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="panel">
            <h2>
                <?= $editProduct ? 'Edit Produk' : 'Tambah Produk' ?>
            </h2>

            <?php if (!$categories): ?>
                <p>
                    Tambahkan kategori terlebih dahulu sebelum
                    menambahkan produk.
                </p>
                <a href="../categories/index.php">Buka Data Kategori</a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION['csrf_token']) ?>">

                    <input type="hidden" name="action"
                           value="<?= $editProduct ? 'update' : 'create' ?>">

                    <?php if ($editProduct): ?>
                        <input type="hidden" name="id"
                               value="<?= (int) $editProduct['id'] ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label>SKU</label>
                        <input type="text" name="sku" maxlength="50"
                               required
                               value="<?= e($editProduct['sku'] ?? '') ?>">
                    </div>

                    <div class="field">
                        <label>Nama produk</label>
                        <input type="text" name="name" maxlength="150"
                               required
                               value="<?= e($editProduct['name'] ?? '') ?>">
                    </div>

                    <div class="field">
                        <label>Kategori</label>
                        <select name="category_id" required>
                            <option value="">-- Pilih kategori --</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= (int) ($editProduct['category_id'] ?? 0)
                                        === (int) $category['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Harga</label>
                        <input type="number" name="price"
                               min="0" step="0.01" required
                               value="<?= e($editProduct['price'] ?? '0.00') ?>">
                    </div>

                    <div class="field">
                        <label>Minimum stok</label>
                        <input type="number" name="minimum_stock"
                               min="0" step="1" required
                               value="<?= e($editProduct['minimum_stock'] ?? 5) ?>">
                    </div>

                    <div class="field">
                        <label>Deskripsi</label>
                        <textarea name="description" rows="3"><?= e(
                            $editProduct['description'] ?? ''
                        ) ?></textarea>
                    </div>

                    <?php if ($editProduct): ?>
                        <p>
                            Stok saat ini:
                            <strong><?= (int) $editProduct['stock'] ?></strong>.
                            Stok tidak diubah melalui form edit.
                        </p>
                    <?php else: ?>
                        <p>Stok awal produk baru adalah 0.</p>
                    <?php endif; ?>

                    <button type="submit">
                        <?= $editProduct ? 'Simpan Perubahan' : 'Tambah Produk' ?>
                    </button>

                    <?php if ($editProduct): ?>
                        <a href="index.php">Batal</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Daftar Produk</h2>

        <form method="GET" class="filters">
            <input type="text" name="q"
                   placeholder="Cari nama atau SKU..."
                   value="<?= e($search) ?>">

            <select name="category_id">
                <option value="0">Semua kategori</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $categoryFilter === (int) $category['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Urutkan</label>
            <select name="sort">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                    Nama
                </option>
                <option value="sku" <?= $sort === 'sku' ? 'selected' : '' ?>>
                    SKU
                </option>
                <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>
                    Harga
                </option>
                <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>
                    Stok
                </option>
                <option value="created_at"
                    <?= $sort === 'created_at' ? 'selected' : '' ?>>
                    Tanggal dibuat
                </option>
            </select>

            <select name="direction">
                <option value="ASC"
                    <?= $direction === 'ASC' ? 'selected' : '' ?>>
                    Naik (ASC)
                </option>
                <option value="DESC"
                    <?= $direction === 'DESC' ? 'selected' : '' ?>>
                    Turun (DESC)
                </option>
            </select>

            <button type="submit">Terapkan</button>
            <a href="index.php">Reset</a>
        </form>

        <p>Total produk: <strong><?= $totalProducts ?></strong></p>

        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Nama produk</th>
                    <th>Kategori</th>
                    <th>Harga</th>
                    <th>Stok</th>
                    <th>Minimum stok</th>
                    <th>Status stok</th>

                    <?php if ($isAdmin): ?>
                        <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
            <?php if (!$products): ?>
                <tr>
                    <td colspan="<?= $isAdmin ? 8 : 7 ?>">
                        Belum ada produk atau data tidak ditemukan.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= e($product['sku']) ?></td>
                        <td><?= e($product['name']) ?></td>
                        <td><?= e($product['category_name']) ?></td>
                        <td>
                            Rp <?= number_format(
                                (float) $product['price'],
                                2,
                                ',',
                                '.'
                            ) ?>
                        </td>
                        <td><?= (int) $product['stock'] ?></td>
                        <td><?= (int) $product['minimum_stock'] ?></td>
                        <td>
                            <?php if (
                                (int) $product['stock']
                                <= (int) $product['minimum_stock']
                            ): ?>
                                <span class="low-stock">Stok rendah</span>
                            <?php else: ?>
                                Aman
                            <?php endif; ?>
                        </td>

                        <?php if ($isAdmin): ?>
                            <td>
                                <div class="actions">
                                    <a href="?<?= e(http_build_query([
                                        'edit' => (int) $product['id'],
                                        'q' => $search,
                                        'category_id' => $categoryFilter,
                                        'sort' => $sort,
                                        'direction' => $direction
                                    ])) ?>">Edit</a>

                                    <form method="POST"
                                          onsubmit="return confirm('Hapus produk ini?')">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action"
                                               value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int) $product['id'] ?>">

                                        <button type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>
                Halaman <?= $page ?> dari <?= $totalPages ?>
            </span>

            <?php if ($page > 1): ?>
                <?php
                $previousParams = $pageParams;
                $previousParams['page'] = $page - 1;
                ?>
                <a href="?<?= e(http_build_query($previousParams)) ?>">
                    Sebelumnya
                </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <?php
                $nextParams = $pageParams;
                $nextParams['page'] = $page + 1;
                ?>
                <a href="?<?= e(http_build_query($nextParams)) ?>">
                    Berikutnya
                </a>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>