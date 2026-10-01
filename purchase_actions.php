<?php
require_once 'config/database.php';
require_once 'auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
ensure_auth_tables($pdo);
require_api_login();

function json_error_purchase(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function ensure_purchase_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_suppliers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(24) NOT NULL UNIQUE,
            supplier_name VARCHAR(120) NOT NULL,
            contact_name VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            address TEXT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $hasSupplierId = $pdo->query("SHOW COLUMNS FROM products LIKE 'supplier_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasSupplierId) {
        $pdo->exec('ALTER TABLE products ADD COLUMN supplier_id INT UNSIGNED NULL AFTER unit_base_qty');
    }

    // Menggunakan purchase_orders sesuai permintaan
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(50) NOT NULL UNIQUE,
            supplier_id INT UNSIGNED NOT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NULL,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            total DECIMAL(15,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            status ENUM("unpaid","partial","paid") NOT NULL DEFAULT "unpaid",
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_supplier_id (supplier_id),
            CONSTRAINT fk_purchase_supplier
                FOREIGN KEY (supplier_id) REFERENCES master_suppliers(id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Menggunakan purchase_order_items sesuai permintaan
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            product_code VARCHAR(60) NULL,
            product_name VARCHAR(200) NOT NULL,
            qty DECIMAL(15,4) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_id (purchase_id),
            CONSTRAINT fk_purchase_items_order
                FOREIGN KEY (purchase_id) REFERENCES purchase_orders(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS supplier_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(60) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_id (purchase_id),
            INDEX idx_supplier_id (supplier_id),
            CONSTRAINT fk_supplier_payments_order
                FOREIGN KEY (purchase_id) REFERENCES purchase_orders(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_supplier_payments_supplier
                FOREIGN KEY (supplier_id) REFERENCES master_suppliers(id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // add proof_file column if missing
    $hasProof = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'proof_file'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasProof) {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN proof_file VARCHAR(255) NULL AFTER notes");
    }
}

function recalc_purchase_status(PDO $pdo, int $purchaseId): void
{
    $stmt = $pdo->prepare('SELECT total, paid_amount FROM purchase_orders WHERE id = :id');
    $stmt->execute([':id' => $purchaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $total = (float)($row['total'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $status = 'unpaid';
    if ($paid <= 0) {
        $status = 'unpaid';
    } elseif ($paid >= $total) {
        $status = 'paid';
        $paid = $total;
    } else {
        $status = 'partial';
    }

    $up = $pdo->prepare('UPDATE purchase_orders SET paid_amount = :paid, status = :status WHERE id = :id');
    $up->execute([
        ':paid' => $paid,
        ':status' => $status,
        ':id' => $purchaseId,
    ]);
}

ensure_purchase_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error_purchase(422, 'ID pembelian tidak valid.');
        }
        $head = $pdo->prepare(
            'SELECT pi.*, s.supplier_name, s.supplier_code
             FROM purchase_orders pi
             INNER JOIN master_suppliers s ON s.id = pi.supplier_id
             WHERE pi.id = :id'
        );
        $head->execute([':id' => $id]);
        $purchase = $head->fetch(PDO::FETCH_ASSOC);
        if (!$purchase) {
            json_error_purchase(404, 'Data pembelian tidak ditemukan.');
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_id = :id ORDER BY id ASC');
        $itemsStmt->execute([':id' => $id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $payStmt = $pdo->prepare('SELECT * FROM supplier_payments WHERE purchase_id = :id ORDER BY payment_date DESC, id DESC');
        $payStmt->execute([':id' => $id]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'purchase' => $purchase,
            'items' => $items,
            'payments' => $payments,
        ]);
        exit;
    }

    if ($action === 'list_today') {
        // Bisa ditambahkan jika perlu list
        exit;
    }

    json_error_purchase(400, 'Action GET tidak dikenal.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error_purchase(405, 'Method tidak diizinkan.');
}

// Support JSON payloads and multipart/form-data (for file uploads)
if (!empty($_FILES) || (isset($_POST['action']) && strpos(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false)) {
    // multipart/form-data upload path
    $action = (string)($_POST['action'] ?? '');
    $payload = $_POST; // minimal mapping for handler usage
} else {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_error_purchase(400, 'Payload tidak valid.');
    }
    $action = (string)($payload['action'] ?? '');
}
try {
    $pdo->beginTransaction();

    if ($action === 'create_purchase') {
        $supplierId = (int)($payload['supplier_id'] ?? 0);
        $invoiceDate = trim((string)($payload['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string)($payload['due_date'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($supplierId <= 0 || empty($items)) {
            json_error_purchase(422, 'Supplier dan item pembelian wajib diisi.');
        }

        $subtotal = 0.0;
        $cleanItems = [];
        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? 0);
            $unitCost = (float)($item['unit_cost'] ?? 0);
            if ($qty <= 0 || $unitCost < 0) {
                continue;
            }
            $lineSubtotal = $qty * $unitCost;
            $subtotal += $lineSubtotal;
            $cleanItems[] = [
                'product_id' => isset($item['product_id']) ? (int)$item['product_id'] : null,
                'product_code' => (string)($item['product_code'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'subtotal' => $lineSubtotal,
            ];
        }
        if (empty($cleanItems)) {
            json_error_purchase(422, 'Item pembelian tidak valid.');
        }

        $invoiceNo = trim((string)($payload['invoice_no'] ?? ''));
        if ($invoiceNo === '') {
            $invoiceNo = 'PO-' . date('YmdHis') . '-' . random_int(100, 999);
        }

        $headStmt = $pdo->prepare(
            'INSERT INTO purchase_orders
                (invoice_no, supplier_id, invoice_date, due_date, subtotal, total, paid_amount, status, notes)
             VALUES
                (:invoice_no, :supplier_id, :invoice_date, :due_date, :subtotal, :total, 0, "unpaid", :notes)'
        );
        $headStmt->execute([
            ':invoice_no' => $invoiceNo,
            ':supplier_id' => $supplierId,
            ':invoice_date' => $invoiceDate,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
            ':subtotal' => $subtotal,
            ':total' => $subtotal,
            ':notes' => $notes !== '' ? $notes : null,
        ]);
        $purchaseId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO purchase_order_items
                (purchase_id, product_id, product_code, product_name, qty, unit_cost, subtotal)
             VALUES
                (:purchase_id, :product_id, :product_code, :product_name, :qty, :unit_cost, :subtotal)'
        );

        $stockStmt = $pdo->prepare('UPDATE products SET stok = stok + :qty WHERE id = :id');

        foreach ($cleanItems as $it) {
            $itemStmt->execute([
                ':purchase_id' => $purchaseId,
                ':product_id' => $it['product_id'] ?: null,
                ':product_code' => $it['product_code'] !== '' ? $it['product_code'] : null,
                ':product_name' => $it['product_name'],
                ':qty' => $it['qty'],
                ':unit_cost' => $it['unit_cost'],
                ':subtotal' => $it['subtotal'],
            ]);

            // Update stok jika produk terdaftar
            if ($it['product_id']) {
                $stockStmt->execute([
                    ':qty' => $it['qty'],
                    ':id' => $it['product_id']
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'purchase_id' => $purchaseId, 'invoice_no' => $invoiceNo]);
        exit;
    }

    if ($action === 'add_payment') {
        $purchaseId = (int)($payload['purchase_id'] ?? 0);
        $amount = (float)($payload['amount'] ?? 0);
        $paymentDate = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
        $method = trim((string)($payload['payment_method'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        if ($purchaseId <= 0 || $amount <= 0) {
            json_error_purchase(422, 'Data pembayaran tidak valid.');
        }

        $headStmt = $pdo->prepare('SELECT id, supplier_id, total, paid_amount FROM purchase_orders WHERE id = :id FOR UPDATE');
        $headStmt->execute([':id' => $purchaseId]);
        $head = $headStmt->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            json_error_purchase(404, 'Invoice pembelian tidak ditemukan.');
        }

        $newPaid = (float)$head['paid_amount'] + $amount;
        if ($newPaid > (float)$head['total']) {
            $amount = max(0, (float)$head['total'] - (float)$head['paid_amount']);
            $newPaid = (float)$head['paid_amount'] + $amount;
        }
        if ($amount <= 0) {
            json_error_purchase(422, 'Invoice sudah lunas.');
        }

        $payStmt = $pdo->prepare(
            'INSERT INTO supplier_payments
                (purchase_id, supplier_id, payment_date, amount, payment_method, notes)
             VALUES
                (:purchase_id, :supplier_id, :payment_date, :amount, :payment_method, :notes)'
        );
        $payStmt->execute([
            ':purchase_id' => $purchaseId,
            ':supplier_id' => (int)$head['supplier_id'],
            ':payment_date' => $paymentDate,
            ':amount' => $amount,
            ':payment_method' => $method !== '' ? $method : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $upPaid = $pdo->prepare('UPDATE purchase_orders SET paid_amount = :paid WHERE id = :id');
        $upPaid->execute([
            ':paid' => $newPaid,
            ':id' => $purchaseId,
        ]);
        recalc_purchase_status($pdo, $purchaseId);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upload_proof') {
        // expects multipart/form-data with 'purchase_id' and file field 'proof'
        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        if ($purchaseId <= 0) {
            json_error_purchase(422, 'ID pembelian tidak valid.');
        }
        if (empty($_FILES['proof']) || !is_uploaded_file($_FILES['proof']['tmp_name'])) {
            json_error_purchase(422, 'File bukti tidak ditemukan.');
        }

        $file = $_FILES['proof'];
        $allowed = ['image/png','image/jpeg','application/pdf'];
        if (!in_array($file['type'], $allowed)) {
            // allow by extension fallback
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png','jpg','jpeg','pdf'])) {
                json_error_purchase(422, 'Tipe file tidak diperbolehkan. (png/jpg/pdf)');
            }
        }

        $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'purchase_proofs';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fname = 'po_' . $purchaseId . '_' . time() . '_' . random_int(1000,9999) . '.' . $ext;
        $dest = $uploadsDir . DIRECTORY_SEPARATOR . $fname;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_error_purchase(500, 'Gagal menyimpan file.');
        }

        // store relative web path
        $webPath = 'uploads/purchase_proofs/' . $fname;
        $up = $pdo->prepare('UPDATE purchase_orders SET proof_file = :pf WHERE id = :id');
        $up->execute([':pf' => $webPath, ':id' => $purchaseId]);

        $pdo->commit();
        echo json_encode(['success' => true, 'path' => $webPath]);
        exit;
    }

    if ($action === 'update_purchase') {
        $purchaseId = (int)($payload['purchase_id'] ?? 0);
        $supplierId = (int)($payload['supplier_id'] ?? 0);
        $invoiceDate = trim((string)($payload['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string)($payload['due_date'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($purchaseId <= 0 || $supplierId <= 0) {
            json_error_purchase(422, 'Data tidak valid untuk pembaruan.');
        }

        // lock purchase row
        $headStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE');
        $headStmt->execute([':id' => $purchaseId]);
        $head = $headStmt->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            json_error_purchase(404, 'Invoice pembelian tidak ditemukan.');
        }

        // revert stock for old items
        $oldItemsStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_id = :id');
        $oldItemsStmt->execute([':id' => $purchaseId]);
        $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $updateStockDec = $pdo->prepare('UPDATE products SET stok = stok - :qty WHERE id = :id');
        foreach ($oldItems as $oit) {
            if (!empty($oit['product_id'])) {
                $updateStockDec->execute([':qty' => (float)$oit['qty'], ':id' => (int)$oit['product_id']]);
            }
        }

        // remove old items
        $delItems = $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_id = :id');
        $delItems->execute([':id' => $purchaseId]);

        // prepare insert for new items and add stock
        $itemStmt = $pdo->prepare(
            'INSERT INTO purchase_order_items
                (purchase_id, product_id, product_code, product_name, qty, unit_cost, subtotal)
             VALUES
                (:purchase_id, :product_id, :product_code, :product_name, :qty, :unit_cost, :subtotal)'
        );
        $updateStockInc = $pdo->prepare('UPDATE products SET stok = stok + :qty WHERE id = :id');

        $subtotal = 0.0;
        $cleanItems = [];
        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? 0);
            $unitCost = (float)($item['unit_cost'] ?? 0);
            if ($qty <= 0 || $unitCost < 0) continue;
            $lineSubtotal = $qty * $unitCost;
            $subtotal += $lineSubtotal;
            $cleanItems[] = [
                'product_id' => isset($item['product_id']) ? (int)$item['product_id'] : null,
                'product_code' => (string)($item['product_code'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'subtotal' => $lineSubtotal,
            ];
        }

        if (empty($cleanItems)) {
            json_error_purchase(422, 'Item pembelian tidak valid.');
        }

        // insert new items and increment stock
        foreach ($cleanItems as $it) {
            $itemStmt->execute([
                ':purchase_id' => $purchaseId,
                ':product_id' => $it['product_id'] ?: null,
                ':product_code' => $it['product_code'] !== '' ? $it['product_code'] : null,
                ':product_name' => $it['product_name'],
                ':qty' => $it['qty'],
                ':unit_cost' => $it['unit_cost'],
                ':subtotal' => $it['subtotal'],
            ]);
            if ($it['product_id']) {
                $updateStockInc->execute([':qty' => $it['qty'], ':id' => $it['product_id']]);
            }
        }

        // update purchase header totals
        $up = $pdo->prepare('UPDATE purchase_orders SET supplier_id = :supplier_id, invoice_date = :invoice_date, due_date = :due_date, subtotal = :subtotal, total = :total, notes = :notes, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $up->execute([
            ':supplier_id' => $supplierId,
            ':invoice_date' => $invoiceDate,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
            ':subtotal' => $subtotal,
            ':total' => $subtotal,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => $purchaseId,
        ]);

        // recalc status (will cap paid_amount if needed)
        recalc_purchase_status($pdo, $purchaseId);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_purchase') {
        $purchaseId = (int)($payload['purchase_id'] ?? 0);
        if ($purchaseId <= 0) {
            json_error_purchase(422, 'ID pembelian tidak valid.');
        }

        // prevent deletion if payments exist
        $payCntStmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_payments WHERE purchase_id = :id');
        $payCntStmt->execute([':id' => $purchaseId]);
        $payCount = (int)$payCntStmt->fetchColumn();
        if ($payCount > 0) {
            json_error_purchase(422, 'Tidak dapat menghapus PO yang sudah memiliki pembayaran. Hapus/rollback pembayaran terlebih dahulu.');
        }

        // fetch items to revert stock
        $itemsStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_id = :id');
        $itemsStmt->execute([':id' => $purchaseId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $updateStockDec = $pdo->prepare('UPDATE products SET stok = stok - :qty WHERE id = :id');
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                $updateStockDec->execute([':qty' => (float)$it['qty'], ':id' => (int)$it['product_id']]);
            }
        }

        // Lepas tag di transaksi penjualan supaya nota lama tidak ikut hilang, cuma jadi tanpa pembelian.
        $hasSalesPurchaseTag = $pdo->query("SHOW COLUMNS FROM sales_transactions LIKE 'purchase_id'")->fetch(PDO::FETCH_ASSOC);
        if ($hasSalesPurchaseTag) {
            $untag = $pdo->prepare('UPDATE sales_transactions SET purchase_id = NULL WHERE purchase_id = :id');
            $untag->execute([':id' => $purchaseId]);
        }

        // delete purchase (items and payments will cascade)
        $del = $pdo->prepare('DELETE FROM purchase_orders WHERE id = :id');
        $del->execute([':id' => $purchaseId]);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    json_error_purchase(400, 'Action POST tidak dikenal.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PURCHASE_ACTIONS_ERROR] ' . $e->getMessage());
    json_error_purchase(500, 'Gagal memproses transaksi pembelian: ' . $e->getMessage());
}
