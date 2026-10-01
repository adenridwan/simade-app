<?php
require_once 'config/database.php';
require_once 'auth.php';

$pdo = get_db();
ensure_auth_tables($pdo);
require_login();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS app_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(120) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS print_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_type VARCHAR(40) NOT NULL UNIQUE,
        template_name VARCHAR(120) NOT NULL,
        format_type VARCHAR(20) NOT NULL DEFAULT "html",
        template_content LONGTEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS master_units (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unit_code VARCHAR(24) NOT NULL UNIQUE,
        unit_name VARCHAR(80) NOT NULL,
        unit_symbol VARCHAR(24) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
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

function save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
}

$defaults = [
    'receipt_store_name' => 'Toko POS Kita',
    'receipt_store_address' => 'Jl. Kenangan No. 123',
    'receipt_store_phone' => '',
    'receipt_bank_account' => '',
    'receipt_logo_url' => '',
    'receipt_header_text' => '',
    'receipt_footer_text' => 'Terima kasih sudah berbelanja',
    'receipt_show_logo' => '0',
    'receipt_show_store_info' => '1',
    'receipt_show_item_code' => '0',
    'receipt_paper_width_mm' => '58',
    'receipt_font_size_px' => '12',
    'receipt_header_align' => 'center',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_receipt_template') {
    $payload = [
        'receipt_store_name' => trim($_POST['receipt_store_name'] ?? ''),
        'receipt_store_address' => trim($_POST['receipt_store_address'] ?? ''),
        'receipt_store_phone' => trim($_POST['receipt_store_phone'] ?? ''),
        'receipt_bank_account' => trim($_POST['receipt_bank_account'] ?? ''),
        'receipt_logo_url' => trim($_POST['receipt_logo_url'] ?? ''),
        'receipt_header_text' => trim($_POST['receipt_header_text'] ?? ''),
        'receipt_footer_text' => trim($_POST['receipt_footer_text'] ?? ''),
        'receipt_show_logo' => isset($_POST['receipt_show_logo']) ? '1' : '0',
        'receipt_show_store_info' => isset($_POST['receipt_show_store_info']) ? '1' : '0',
        'receipt_show_item_code' => isset($_POST['receipt_show_item_code']) ? '1' : '0',
        'receipt_paper_width_mm' => in_array($_POST['receipt_paper_width_mm'] ?? '58', ['58', '80'], true) ? $_POST['receipt_paper_width_mm'] : '58',
        'receipt_font_size_px' => (string)max(10, min(16, (int)($_POST['receipt_font_size_px'] ?? 12))),
        'receipt_header_align' => in_array($_POST['receipt_header_align'] ?? 'center', ['left', 'center'], true) ? $_POST['receipt_header_align'] : 'center',
    ];

    if ($payload['receipt_store_name'] === '') {
        $payload['receipt_store_name'] = $defaults['receipt_store_name'];
    }
    if ($payload['receipt_footer_text'] === '') {
        $payload['receipt_footer_text'] = $defaults['receipt_footer_text'];
    }

    if (!empty($_FILES['receipt_logo_file']) && (int)($_FILES['receipt_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadErr = (int)($_FILES['receipt_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr === UPLOAD_ERR_OK) {
            $tmpName = (string)$_FILES['receipt_logo_file']['tmp_name'];
            $fileSize = (int)$_FILES['receipt_logo_file']['size'];
            $origName = (string)$_FILES['receipt_logo_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
            if (!in_array($ext, $allowed, true)) {
                header('Location: settings.php?msg=error_logo_type');
                exit;
            }
            if ($fileSize > 2 * 1024 * 1024) {
                header('Location: settings.php?msg=error_logo_size');
                exit;
            }

            $uploadDir = __DIR__ . '/assets/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newName = 'logo_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.' . $ext;
            $destPath = $uploadDir . '/' . $newName;
            if (!move_uploaded_file($tmpName, $destPath)) {
                header('Location: settings.php?msg=error_logo_upload');
                exit;
            }
            $payload['receipt_logo_url'] = 'assets/uploads/' . $newName;
        } else {
            header('Location: settings.php?msg=error_logo_upload');
            exit;
        }
    }

    foreach ($payload as $key => $value) {
        save_setting($pdo, $key, $value);
    }

    header('Location: settings.php?msg=saved');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_print_templates') {
    $notaTemplate = trim((string)($_POST['template_nota_html'] ?? ''));
    $kwitansiTemplate = trim((string)($_POST['template_kwitansi_html'] ?? ''));
    $laporanTemplate = trim((string)($_POST['template_laporan_html'] ?? ''));

    $stmt = $pdo->prepare(
        'INSERT INTO print_templates (template_type, template_name, format_type, template_content, is_active)
         VALUES (:type, :name, "html", :content, 1)
         ON DUPLICATE KEY UPDATE
            template_name = VALUES(template_name),
            template_content = VALUES(template_content),
            is_active = VALUES(is_active),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':type' => 'nota',
        ':name' => 'Template Nota',
        ':content' => $notaTemplate,
    ]);
    $stmt->execute([
        ':type' => 'kwitansi',
        ':name' => 'Template Kwitansi',
        ':content' => $kwitansiTemplate,
    ]);
    $stmt->execute([
        ':type' => 'laporan_transaksi',
        ':name' => 'Template Daftar Transaksi',
        ':content' => $laporanTemplate,
    ]);

    header('Location: settings.php?msg=template_saved');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unit_create') {
    $code = strtolower(trim((string)($_POST['unit_code'] ?? '')));
    $name = trim((string)($_POST['unit_name'] ?? ''));
    $symbol = trim((string)($_POST['unit_symbol'] ?? ''));
    $isActive = isset($_POST['unit_is_active']) ? 1 : 0;
    if ($code === '' || $name === '' || $symbol === '') {
        header('Location: settings.php?msg=unit_error_required');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO master_units (unit_code, unit_name, unit_symbol, is_active)
             VALUES (:code, :name, :symbol, :active)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':symbol' => $symbol,
            ':active' => $isActive,
        ]);
        header('Location: settings.php?msg=unit_saved');
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            header('Location: settings.php?msg=unit_error_duplicate');
        } else {
            header('Location: settings.php?msg=unit_error');
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unit_update') {
    $id = (int)($_POST['unit_id'] ?? 0);
    $code = strtolower(trim((string)($_POST['unit_code'] ?? '')));
    $name = trim((string)($_POST['unit_name'] ?? ''));
    $symbol = trim((string)($_POST['unit_symbol'] ?? ''));
    $isActive = isset($_POST['unit_is_active']) ? 1 : 0;
    if ($id <= 0 || $code === '' || $name === '' || $symbol === '') {
        header('Location: settings.php?msg=unit_error_required');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE master_units
             SET unit_code = :code, unit_name = :name, unit_symbol = :symbol, is_active = :active
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':code' => $code,
            ':name' => $name,
            ':symbol' => $symbol,
            ':active' => $isActive,
        ]);
        header('Location: settings.php?msg=unit_saved');
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            header('Location: settings.php?msg=unit_error_duplicate');
        } else {
            header('Location: settings.php?msg=unit_error');
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unit_delete') {
    $id = (int)($_POST['unit_id'] ?? 0);
    if ($id <= 0) {
        header('Location: settings.php?msg=unit_error');
        exit;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM master_units WHERE id = :id');
        $stmt->execute([':id' => $id]);
        header('Location: settings.php?msg=unit_deleted');
    } catch (PDOException $e) {
        header('Location: settings.php?msg=unit_error_delete');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supplier_create') {
    $code = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
    $name = trim((string)($_POST['supplier_name'] ?? ''));
    $contactName = trim((string)($_POST['contact_name'] ?? ''));
    $phone = trim((string)($_POST['supplier_phone'] ?? ''));
    $address = trim((string)($_POST['supplier_address'] ?? ''));
    $notes = trim((string)($_POST['supplier_notes'] ?? ''));
    $isActive = isset($_POST['supplier_is_active']) ? 1 : 0;
    if ($code === '' || $name === '') {
        header('Location: settings.php?msg=supplier_error_required');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO master_suppliers (supplier_code, supplier_name, contact_name, phone, address, notes, is_active)
             VALUES (:code, :name, :contact_name, :phone, :address, :notes, :active)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':contact_name' => $contactName !== '' ? $contactName : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':address' => $address !== '' ? $address : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':active' => $isActive,
        ]);
        header('Location: settings.php?msg=supplier_saved');
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            header('Location: settings.php?msg=supplier_error_duplicate');
        } else {
            header('Location: settings.php?msg=supplier_error');
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supplier_update') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $code = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
    $name = trim((string)($_POST['supplier_name'] ?? ''));
    $contactName = trim((string)($_POST['contact_name'] ?? ''));
    $phone = trim((string)($_POST['supplier_phone'] ?? ''));
    $address = trim((string)($_POST['supplier_address'] ?? ''));
    $notes = trim((string)($_POST['supplier_notes'] ?? ''));
    $isActive = isset($_POST['supplier_is_active']) ? 1 : 0;
    if ($id <= 0 || $code === '' || $name === '') {
        header('Location: settings.php?msg=supplier_error_required');
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE master_suppliers
             SET supplier_code = :code, supplier_name = :name, contact_name = :contact_name, phone = :phone,
                 address = :address, notes = :notes, is_active = :active
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':code' => $code,
            ':name' => $name,
            ':contact_name' => $contactName !== '' ? $contactName : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':address' => $address !== '' ? $address : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':active' => $isActive,
        ]);
        header('Location: settings.php?msg=supplier_saved');
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            header('Location: settings.php?msg=supplier_error_duplicate');
        } else {
            header('Location: settings.php?msg=supplier_error');
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supplier_delete') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    if ($id <= 0) {
        header('Location: settings.php?msg=supplier_error');
        exit;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM master_suppliers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        header('Location: settings.php?msg=supplier_deleted');
    } catch (PDOException $e) {
        header('Location: settings.php?msg=supplier_error_delete');
    }
    exit;
}

// Import database from uploaded .sql file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'import_database') {
    if (!isset($_FILES['sql_file']) || ($_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Location: settings.php?msg=import_error&error=' . urlencode('File tidak di-upload atau terjadi kesalahan upload.'));
        exit;
    }

    $file = $_FILES['sql_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['sql'];
    if (!in_array($ext, $allowed, true)) {
        header('Location: settings.php?msg=import_error&error=' . urlencode('Ekstensi file tidak diperbolehkan. Hanya .sql yang diterima.'));
        exit;
    }

    // ukuran batas 10MB (sesuaikan bila perlu)
    if ($file['size'] > 10 * 1024 * 1024) {
        header('Location: settings.php?msg=import_error&error=' . urlencode('Ukuran file terlalu besar (max 10MB).'));
        exit;
    }

    $tmp = $file['tmp_name'];
    $sql = @file_get_contents($tmp);
    if ($sql === false) {
        header('Location: settings.php?msg=import_error&error=' . urlencode('Gagal membaca file SQL yang di-upload.'));
        exit;
    }

    try {
        // Jangan bungkus dump dalam transaksi PDO. File dump mengatur transaksinya
        // sendiri (ekspor dari aplikasi mobile menulis START TRANSACTION/COMMIT),
        // dan MySQL melakukan implicit commit pada setiap DDL. Akibatnya saat
        // $pdo->commit() dipanggil sudah tidak ada transaksi aktif, dan impor yang
        // sebenarnya berhasil tetap dilaporkan gagal dengan pesan
        // "There is no active transaction".
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
        // menjalankan seluruh isi file SQL; jika DB besar atau file kompleks, eksekusi bisa gagal => tangani exception
        $pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Dump boleh saja meninggalkan transaksi terbuka (mis. ada START
        // TRANSACTION tanpa COMMIT di akhir file).
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        header('Location: settings.php?msg=import_success');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Exception $_) {}
        }
        // FOREIGN_KEY_CHECKS bersifat per-sesi; jangan biarkan mati karena impor gagal
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1;'); } catch (Exception $_) {}
        $err = $e->getMessage();
        header('Location: settings.php?msg=import_error&error=' . urlencode($err));
        exit;
    }
}

$current = $defaults;
$rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'receipt_%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $key = (string)$row['setting_key'];
    if (array_key_exists($key, $current)) {
        $current[$key] = (string)$row['setting_value'];
    }
}

$templateRows = $pdo->query('SELECT template_type, template_content FROM print_templates WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
$printTemplates = [
    'nota' => '',
    'kwitansi' => '',
    'laporan_transaksi' => '',
];
foreach ($templateRows as $templateRow) {
    $type = (string)$templateRow['template_type'];
    if (array_key_exists($type, $printTemplates)) {
        $printTemplates[$type] = (string)$templateRow['template_content'];
    }
}

// Ensure default templates exist in DB and include {{discount}} and {{downpayment}}
$defaultNotaTemplate = <<<HTML
<div style="font-family:monospace; font-size:12px;">
  <div style="text-align:center; font-weight:bold;">{{store_name}}</div>
  <div style="text-align:center;">{{store_address}}</div>
  <div style="text-align:center;">{{store_phone}}</div>
  <hr />
  <div>Invoice: {{invoice_no}}</div>
  <div>Tanggal: {{transaction_at}}</div>
  <div>Customer: {{customer_name}}</div>
  <hr />
  <div>{{items_rows}}</div>
  <hr />
  <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>{{total}}</span></div>
  <div style="display:flex;justify-content:space-between;"><span>Diskon</span><span>{{discount}}</span></div>
  <div style="display:flex;justify-content:space-between;"><span>Uang Muka</span><span>{{downpayment}}</span></div>
    <div style="display:flex;justify-content:space-between;font-weight:bold;"><span>Sisa Bayar</span><span>{{sisa_bayar}}</span></div>
  <div style="display:flex;justify-content:space-between;"><span>Bayar</span><span>{{paid_amount}}</span></div>
  <div style="display:flex;justify-content:space-between;"><span>Kembali</span><span>{{change_amount}}</span></div>
  <hr />
  <div style="text-align:center;">{{footer_text}}</div>
</div>
HTML;

$defaultKwitansiTemplate = <<<HTML
<div style="font-family:monospace; font-size:12px;">
  <div style="text-align:center; font-weight:bold;">KWITANSI - {{store_name}}</div>
  <div>No: {{invoice_no}}</div>
  <div>Tanggal: {{transaction_at}}</div>
  <div>Untuk pembayaran: {{customer_name}}</div>
  <hr />
  <div>Total: {{total}}</div>
  <div>Diskon: {{discount}}</div>
  <div>Uang Muka: {{downpayment}}</div>
    <div>Sisa Bayar: {{sisa_bayar}}</div>
  <div>Dibayar: {{paid_amount}}</div>
  <div>Kembali: {{change_amount}}</div>
  <hr />
  <div>{{footer_text}}</div>
</div>
HTML;

$defaultLaporanTemplate = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;padding:12px;">
  <div style="text-align:center;margin-bottom:10px;">
    <div style="font-size:16px;font-weight:bold;">{{store_name}}</div>
    <div>{{store_address}}</div>
    <div>{{store_phone}}</div>
  </div>
  <div style="text-align:center;font-weight:bold;font-size:14px;margin-bottom:4px;">{{report_title}}</div>
  <div style="text-align:center;margin-bottom:10px;">Periode: {{period}} &bull; Status: {{filter_status}}</div>
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr>
        <th style="border:1px solid #999;padding:5px;text-align:center;">No</th>
        <th style="border:1px solid #999;padding:5px;text-align:left;">Invoice</th>
        <th style="border:1px solid #999;padding:5px;text-align:left;">Pelanggan</th>
        <th style="border:1px solid #999;padding:5px;text-align:left;">Waktu</th>
        <th style="border:1px solid #999;padding:5px;text-align:right;">Total</th>
        <th style="border:1px solid #999;padding:5px;text-align:right;">Diskon</th>
        <th style="border:1px solid #999;padding:5px;text-align:right;">Uang Muka</th>
        <th style="border:1px solid #999;padding:5px;text-align:right;">Sisa Bayar</th>
        <th style="border:1px solid #999;padding:5px;text-align:left;">Metode</th>
        <th style="border:1px solid #999;padding:5px;text-align:left;">Status</th>
      </tr>
    </thead>
    <tbody>
      {{rows}}
    </tbody>
    <tfoot>
      <tr style="font-weight:bold;background:#f1f5f9;">
        <td style="border:1px solid #999;padding:5px;" colspan="4">Total {{total_transaksi}} transaksi</td>
        <td style="border:1px solid #999;padding:5px;text-align:right;">{{grand_total}}</td>
        <td style="border:1px solid #999;padding:5px;text-align:right;">{{grand_discount}}</td>
        <td style="border:1px solid #999;padding:5px;text-align:right;">{{grand_downpayment}}</td>
        <td style="border:1px solid #999;padding:5px;text-align:right;">{{grand_sisa}}</td>
        <td style="border:1px solid #999;padding:5px;" colspan="2"></td>
      </tr>
    </tfoot>
  </table>
  <div style="margin-top:10px;display:flex;justify-content:space-between;">
    <span>{{footer_text}}</span>
    <span>Dicetak: {{print_date}}</span>
  </div>
</div>
HTML;

try {
    if (trim($printTemplates['nota']) === '') {
        $stmt = $pdo->prepare(
            'INSERT INTO print_templates (template_type, template_name, format_type, template_content, is_active)
             VALUES (:type, :name, "html", :content, 1)
             ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                template_content = VALUES(template_content),
                is_active = VALUES(is_active),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':type' => 'nota', ':name' => 'Template Nota', ':content' => $defaultNotaTemplate]);
        $printTemplates['nota'] = $defaultNotaTemplate;
    }
    if (trim($printTemplates['kwitansi']) === '') {
        $stmt = $pdo->prepare(
            'INSERT INTO print_templates (template_type, template_name, format_type, template_content, is_active)
             VALUES (:type, :name, "html", :content, 1)
             ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                template_content = VALUES(template_content),
                is_active = VALUES(is_active),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':type' => 'kwitansi', ':name' => 'Template Kwitansi', ':content' => $defaultKwitansiTemplate]);
        $printTemplates['kwitansi'] = $defaultKwitansiTemplate;
    }
    if (trim($printTemplates['laporan_transaksi']) === '') {
        $stmt = $pdo->prepare(
            'INSERT INTO print_templates (template_type, template_name, format_type, template_content, is_active)
             VALUES (:type, :name, "html", :content, 1)
             ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                template_content = VALUES(template_content),
                is_active = VALUES(is_active),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':type' => 'laporan_transaksi', ':name' => 'Template Daftar Transaksi', ':content' => $defaultLaporanTemplate]);
        $printTemplates['laporan_transaksi'] = $defaultLaporanTemplate;
    }
} catch (Throwable $e) {
    // If DB insert fails, silently continue and show whatever existing templates are present.
}

// Attempt to migrate existing Nota template that used paid_amount for Sisa Bayar to use sisa_bayar instead.
$oldSnippet = '<div style="display:flex;justify-content:space-between;font-weight:bold;"><span>Sisa Bayar</span><span>{{paid_amount}}</span></div>';
$newSnippet = '<div style="display:flex;justify-content:space-between;font-weight:bold;"><span>Sisa Bayar</span><span>{{sisa_bayar}}</span></div>';
try {
    if (isset($printTemplates['nota']) && strpos($printTemplates['nota'], '{{sisa_bayar}}') === false && strpos($printTemplates['nota'], $oldSnippet) !== false) {
        $updated = str_replace($oldSnippet, $newSnippet, $printTemplates['nota']);
        $updStmt = $pdo->prepare('UPDATE print_templates SET template_content = :content, updated_at = CURRENT_TIMESTAMP WHERE template_type = :type');
        $updStmt->execute([':content' => $updated, ':type' => 'nota']);
        $printTemplates['nota'] = $updated;
    }
} catch (Throwable $e) {
    // ignore migration errors
}

$units = $pdo->query('SELECT id, unit_code, unit_name, unit_symbol, is_active FROM master_units ORDER BY is_active DESC, unit_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query('SELECT id, supplier_code, supplier_name, contact_name, phone, address, notes, is_active FROM master_suppliers ORDER BY is_active DESC, supplier_name ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan - POS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/products.css">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        .settings-layout {
            display: grid;
            grid-template-columns: minmax(420px, 1fr) 360px;
            gap: 20px;
            margin-top: 20px;
            align-items: start;
        }
        .settings-panel {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 22px;
            box-shadow: var(--shadow-sm);
        }
        .settings-title {
            margin: 0 0 14px;
            font-size: 19px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .field {
            margin-bottom: 12px;
        }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-secondary);
        }
        .field input,
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: #fff;
            padding: 10px 11px;
            font-size: 14px;
            outline: none;
        }
        .field textarea { min-height: 76px; resize: vertical; }
        .field input:focus,
        .field textarea:focus,
        .field select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .check-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 8px 0 14px;
        }
        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px;
            font-size: 13px;
        }
        .panel-footer {
            border-top: 1px solid var(--border-color);
            padding-top: 12px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .receipt-preview-wrap {
            background: linear-gradient(135deg, #eef2ff 0%, #ecfeff 100%);
            border: 1px solid #c7d2fe;
        }
        .receipt-preview-paper {
            margin: 0 auto;
            width: 280px;
            background: #fff;
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            padding: 12px;
            font-family: "Courier New", monospace;
            font-size: 12px;
            color: #111827;
        }
        .muted-note {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }
        .cards {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .backup-panel {
            margin-top: 18px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px;
        }
        .template-panel {
            margin-top: 18px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px;
        }
        .template-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 10px;
        }
        .template-box textarea {
            width: 100%;
            min-height: 260px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-family: Consolas, monospace;
            font-size: 12px;
            line-height: 1.5;
            padding: 10px;
            resize: vertical;
        }
        .template-hint {
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-muted);
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 10px;
        }
        .backup-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
        }
        .btn-backup {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            background: #f8fafc;
        }
        .btn-backup:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #eef2ff;
        }
        .small-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            text-decoration: none;
            color: inherit;
        }
        .small-card .ic {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #4338ca;
        }
        .alert-save {
            background: #ecfdf5;
            border: 1px solid #10b981;
            color: #065f46;
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        .fold-card {
            margin-top: 18px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
        }
        .fold-summary {
            list-style: none;
            padding: 14px 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-weight: 700;
            font-size: 15px;
            background: #fafafa;
        }
        .fold-summary::-webkit-details-marker {
            display: none;
        }
        .fold-summary .left {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .fold-summary .right {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .fold-content {
            padding: 16px;
            animation: foldIn 160ms ease;
        }
        @keyframes foldIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .units-layout {
            display: grid;
            grid-template-columns: minmax(320px, 420px) 1fr;
            gap: 14px;
            align-items: start;
        }
        .unit-form-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }
        .unit-list-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
        }
        .unit-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px;
            background: #fff;
        }
        .unit-code {
            font-size: 11px;
            font-weight: 700;
            color: #4f46e5;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            padding: 2px 8px;
            display: inline-block;
            margin-bottom: 8px;
        }
        .unit-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .unit-symbol {
            font-size: 12px;
            color: var(--text-muted);
        }
        .unit-state {
            margin-top: 10px;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 3px 8px;
            border: 1px solid #a7f3d0;
            color: #065f46;
            background: #ecfdf5;
        }
        .unit-state.off {
            border-color: #e5e7eb;
            color: #6b7280;
            background: #f9fafb;
        }
        .unit-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
        }
        .supplier-layout {
            display: grid;
            grid-template-columns: minmax(340px, 460px) 1fr;
            gap: 14px;
            align-items: start;
        }
        .supplier-form-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }
        .supplier-list-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .supplier-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px;
            background: #fff;
        }
        .supplier-code {
            font-size: 11px;
            font-weight: 700;
            color: #0369a1;
            background: #ecfeff;
            border: 1px solid #a5f3fc;
            border-radius: 999px;
            padding: 2px 8px;
            display: inline-block;
            margin-bottom: 8px;
        }
        .supplier-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .supplier-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 2px;
        }
        .btn-mini {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #fff;
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 600;
            padding: 6px 10px;
            cursor: pointer;
        }
        .btn-mini.edit {
            border-color: #bfdbfe;
            color: #1d4ed8;
            background: #eff6ff;
        }
        .btn-mini.del {
            border-color: #fecaca;
            color: #b91c1c;
            background: #fef2f2;
        }
        @media (max-width: 1100px) {
            .settings-layout { grid-template-columns: 1fr; }
            .template-grid { grid-template-columns: 1fr; }
            .units-layout { grid-template-columns: 1fr; }
            .supplier-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="pos-layout">
    <nav class="side-nav">
        <div class="logo">
            <div class="logo-icon"><i data-feather="box"></i></div>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" title="Kasir"><i data-feather="shopping-cart"></i></a></li>
            <li><a href="transactions.php" title="Transaksi"><i data-feather="list"></i></a></li>
            <li><a href="products.php" title="Master Produk"><i data-feather="package"></i></a></li>
            <li><a href="price_variants.php" title="Varian Harga"><i data-feather="tag"></i></a></li>
            <li><a href="laporan.php" title="Laporan"><i data-feather="bar-chart-2"></i></a></li>
            <li class="active"><a href="settings.php" title="Pengaturan"><i data-feather="settings"></i></a></li>
            <li><a href="logout.php" title="Logout"><i data-feather="log-out"></i></a></li>
        </ul>
    </nav>

    <main style="flex:1; padding: 30px 44px; overflow-y: auto;">
        <header class="section-header" style="margin-bottom: 10px;">
            <div>
                <h1 style="font-size: 28px;">Pengaturan Sistem</h1>
                <p class="text-muted">Atur template cetakan resi dan konfigurasi sistem kasir.</p>
            </div>
        </header>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
            <div class="alert-save">
                Master template resi berhasil disimpan.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'invalid_export'): ?>
            <div class="alert-save" style="background:#fef2f2;border-color:#f87171;color:#991b1b;">
                Jenis export tidak valid.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'template_saved'): ?>
            <div class="alert-save">
                Master format laporan cetakan berhasil disimpan.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['error_logo_type', 'error_logo_size', 'error_logo_upload'], true)): ?>
            <div class="alert-save" style="background:#fef2f2;border-color:#f87171;color:#991b1b;">
                <?php if ($_GET['msg'] === 'error_logo_type') echo 'Format logo tidak valid. Gunakan PNG/JPG/JPEG/WEBP/SVG.'; ?>
                <?php if ($_GET['msg'] === 'error_logo_size') echo 'Ukuran logo terlalu besar (maksimal 2MB).'; ?>
                <?php if ($_GET['msg'] === 'error_logo_upload') echo 'Gagal upload logo. Coba lagi.'; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['unit_saved', 'unit_deleted'], true)): ?>
            <div class="alert-save">
                <?php if ($_GET['msg'] === 'unit_saved') echo 'Master satuan berhasil disimpan.'; ?>
                <?php if ($_GET['msg'] === 'unit_deleted') echo 'Master satuan berhasil dihapus.'; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['unit_error_required', 'unit_error_duplicate', 'unit_error_delete', 'unit_error'], true)): ?>
            <div class="alert-save" style="background:#fef2f2;border-color:#f87171;color:#991b1b;">
                <?php if ($_GET['msg'] === 'unit_error_required') echo 'Kode, nama, dan simbol satuan wajib diisi.'; ?>
                <?php if ($_GET['msg'] === 'unit_error_duplicate') echo 'Kode satuan sudah digunakan. Gunakan kode lain.'; ?>
                <?php if ($_GET['msg'] === 'unit_error_delete') echo 'Satuan tidak dapat dihapus karena masih terpakai data lain.'; ?>
                <?php if ($_GET['msg'] === 'unit_error') echo 'Terjadi kesalahan saat memproses master satuan.'; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['supplier_saved', 'supplier_deleted'], true)): ?>
            <div class="alert-save">
                <?php if ($_GET['msg'] === 'supplier_saved') echo 'Master supplier berhasil disimpan.'; ?>
                <?php if ($_GET['msg'] === 'supplier_deleted') echo 'Master supplier berhasil dihapus.'; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['supplier_error_required', 'supplier_error_duplicate', 'supplier_error_delete', 'supplier_error'], true)): ?>
            <div class="alert-save" style="background:#fef2f2;border-color:#f87171;color:#991b1b;">
                <?php if ($_GET['msg'] === 'supplier_error_required') echo 'Kode supplier dan nama supplier wajib diisi.'; ?>
                <?php if ($_GET['msg'] === 'supplier_error_duplicate') echo 'Kode supplier sudah digunakan. Gunakan kode lain.'; ?>
                <?php if ($_GET['msg'] === 'supplier_error_delete') echo 'Supplier tidak dapat dihapus karena masih terpakai data lain.'; ?>
                <?php if ($_GET['msg'] === 'supplier_error') echo 'Terjadi kesalahan saat memproses master supplier.'; ?>
            </div>
        <?php endif; ?>

        <div class="settings-layout">
            <section class="settings-panel">
                <h2 class="settings-title">
                    <i data-feather="printer" style="width:18px;height:18px;"></i>
                    Master Cetakan Resi
                </h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_receipt_template">

                    <div class="form-grid-2">
                        <div class="field">
                            <label for="receipt_store_name">Nama Toko</label>
                            <input type="text" id="receipt_store_name" name="receipt_store_name" value="<?= htmlspecialchars($current['receipt_store_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="receipt_store_phone">No. Telepon</label>
                            <input type="text" id="receipt_store_phone" name="receipt_store_phone" value="<?= htmlspecialchars($current['receipt_store_phone']) ?>" placeholder="Contoh: 0812-xxxx-xxxx">
                        </div>
                    </div>

                    <div class="field">
                        <label for="receipt_bank_account">No. Rekening (Opsional)</label>
                        <input type="text" id="receipt_bank_account" name="receipt_bank_account" value="<?= htmlspecialchars($current['receipt_bank_account']) ?>" placeholder="Contoh: BCA 1234567890 a/n SiMade">
                    </div>

                    <div class="field">
                        <label for="receipt_store_address">Alamat Toko</label>
                        <textarea id="receipt_store_address" name="receipt_store_address"><?= htmlspecialchars($current['receipt_store_address']) ?></textarea>
                    </div>

                    <div class="form-grid-2">
                        <div class="field">
                            <label for="receipt_logo_url">URL Logo</label>
                            <input type="text" id="receipt_logo_url" name="receipt_logo_url" value="<?= htmlspecialchars($current['receipt_logo_url']) ?>" placeholder="https://.../logo.png">
                            <div class="muted-note">Bisa isi URL logo atau upload file logo di bawah.</div>
                        </div>
                        <div class="field">
                            <label for="receipt_logo_file">Upload Logo</label>
                            <input type="file" id="receipt_logo_file" name="receipt_logo_file" accept=".png,.jpg,.jpeg,.webp,.svg">
                        </div>
                        <div class="field">
                            <label for="receipt_header_align">Posisi Header</label>
                            <select id="receipt_header_align" name="receipt_header_align">
                                <option value="center" <?= $current['receipt_header_align'] === 'center' ? 'selected' : '' ?>>Tengah</option>
                                <option value="left" <?= $current['receipt_header_align'] === 'left' ? 'selected' : '' ?>>Kiri</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="field">
                            <label for="receipt_paper_width_mm">Lebar Kertas</label>
                            <select id="receipt_paper_width_mm" name="receipt_paper_width_mm">
                                <option value="58" <?= $current['receipt_paper_width_mm'] === '58' ? 'selected' : '' ?>>58 mm</option>
                                <option value="80" <?= $current['receipt_paper_width_mm'] === '80' ? 'selected' : '' ?>>80 mm</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="receipt_font_size_px">Ukuran Font</label>
                            <input type="number" id="receipt_font_size_px" name="receipt_font_size_px" min="10" max="16" value="<?= htmlspecialchars($current['receipt_font_size_px']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="receipt_header_text">Teks Header Tambahan</label>
                        <input type="text" id="receipt_header_text" name="receipt_header_text" value="<?= htmlspecialchars($current['receipt_header_text']) ?>" placeholder="Contoh: Cabang Jakarta Barat">
                    </div>

                    <div class="field">
                        <label for="receipt_footer_text">Teks Footer</label>
                        <input type="text" id="receipt_footer_text" name="receipt_footer_text" value="<?= htmlspecialchars($current['receipt_footer_text']) ?>" required>
                    </div>

                    <div class="check-grid">
                        <label class="check-item"><input type="checkbox" name="receipt_show_logo" <?= $current['receipt_show_logo'] === '1' ? 'checked' : '' ?>> Tampilkan Logo</label>
                        <label class="check-item"><input type="checkbox" name="receipt_show_store_info" <?= $current['receipt_show_store_info'] === '1' ? 'checked' : '' ?>> Tampilkan Info Toko</label>
                        <label class="check-item"><input type="checkbox" name="receipt_show_item_code" <?= $current['receipt_show_item_code'] === '1' ? 'checked' : '' ?>> Tampilkan Kode Produk</label>
                    </div>

                    <div class="panel-footer">
                        <button type="submit" class="btn-primary">
                            <i data-feather="save" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                            Simpan Template
                        </button>
                    </div>
                </form>
            </section>

            <aside class="settings-panel receipt-preview-wrap">
                <h3 class="settings-title" style="font-size:16px;">
                    <i data-feather="eye" style="width:16px;height:16px;"></i>
                    Preview Struktur Resi
                </h3>
                <div class="receipt-preview-paper">
                    <div style="text-align: <?= htmlspecialchars($current['receipt_header_align']) ?>;">
                        <?php if ($current['receipt_show_logo'] === '1' && $current['receipt_logo_url'] !== ''): ?>
                            <img src="<?= htmlspecialchars($current['receipt_logo_url']) ?>" alt="logo" style="max-width:110px; max-height:50px; object-fit:contain;">
                        <?php endif; ?>
                        <?php if ($current['receipt_show_store_info'] === '1'): ?>
                            <div style="font-weight:700;"><?= htmlspecialchars($current['receipt_store_name']) ?></div>
                            <div style="font-size:11px;color:#4b5563;"><?= htmlspecialchars($current['receipt_store_address']) ?></div>
                            <?php if ($current['receipt_store_phone'] !== ''): ?>
                                <div style="font-size:11px;color:#4b5563;"><?= htmlspecialchars($current['receipt_store_phone']) ?></div>
                            <?php endif; ?>
                            <?php if ($current['receipt_bank_account'] !== ''): ?>
                                <div style="font-size:11px;color:#4b5563;">Rek: <?= htmlspecialchars($current['receipt_bank_account']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($current['receipt_header_text'] !== ''): ?>
                            <div style="font-size:11px;color:#4b5563;"><?= htmlspecialchars($current['receipt_header_text']) ?></div>
                        <?php endif; ?>
                    </div>
                    <hr style="border:none;border-top:1px dashed #d1d5db;margin:8px 0;">
                    <div style="font-size:11px;">Contoh Produk A x2 <span style="float:right;">Rp 40.000</span></div>
                    <div style="font-size:11px;">Contoh Produk B x1 <span style="float:right;">Rp 15.000</span></div>
                    <div style="clear:both;"></div>
                    <hr style="border:none;border-top:1px dashed #d1d5db;margin:8px 0;">
                    <div style="font-weight:700;">Total <span style="float:right;">Rp 55.000</span></div>
                    <div style="clear:both;"></div>
                    <div style="text-align:center;margin-top:10px;font-size:11px;color:#374151;"><?= htmlspecialchars($current['receipt_footer_text']) ?></div>
                </div>
                <p class="muted-note">Preview ini menampilkan struktur dasar. Hasil final mengikuti data transaksi saat cetak dari menu kasir.</p>
            </aside>
        </div>

        <div class="cards">
            <a href="price_variants.php" class="small-card">
                <div class="ic"><i data-feather="tag"></i></div>
                <div>
                    <strong>Master Variasi Harga</strong>
                    <div class="muted-note">Kelola varian Reseller, Promo, Grosir, dan lainnya.</div>
                </div>
            </a>
        </div>

        <details class="fold-card" open>
            <summary class="fold-summary">
                <span class="left">
                    <i data-feather="layers" style="width:16px;height:16px;"></i>
                    Master Satuan
                </span>
                <span class="right">Klik untuk tampilkan/sembunyikan</span>
            </summary>
            <div class="fold-content">
                <div class="units-layout">
                    <div class="unit-form-card">
                        <h3 class="settings-title" style="font-size:16px; margin-bottom:8px;">
                            <i data-feather="plus-circle" style="width:16px;height:16px;"></i>
                            Form Satuan
                        </h3>
                        <form method="POST" id="unitForm">
                            <input type="hidden" name="action" id="unitAction" value="unit_create">
                            <input type="hidden" name="unit_id" id="unitId" value="">
                            <div class="field">
                                <label for="unitCode">Kode Satuan</label>
                                <input type="text" id="unitCode" name="unit_code" maxlength="24" placeholder="contoh: gr, kg, pcs, pack" required>
                            </div>
                            <div class="field">
                                <label for="unitName">Nama Satuan</label>
                                <input type="text" id="unitName" name="unit_name" maxlength="80" placeholder="contoh: Gram, Kilogram, Bungkus" required>
                            </div>
                            <div class="field">
                                <label for="unitSymbol">Simbol Tampilan</label>
                                <input type="text" id="unitSymbol" name="unit_symbol" maxlength="24" placeholder="contoh: g, kg, pcs, bks" required>
                            </div>
                            <label class="check-item" style="margin-bottom:12px;">
                                <input type="checkbox" name="unit_is_active" id="unitActive" checked>
                                Status Aktif
                            </label>
                            <div class="panel-footer" style="border-top:0;padding-top:0;justify-content:flex-start;">
                                <button type="submit" class="btn-primary" id="unitSubmitBtn">
                                    <i data-feather="save" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                                    Simpan Satuan
                                </button>
                                <button type="button" class="btn-mini" onclick="resetUnitForm()">Reset</button>
                            </div>
                        </form>
                    </div>
                    <div>
                        <h3 class="settings-title" style="font-size:16px; margin-bottom:8px;">
                            <i data-feather="grid" style="width:16px;height:16px;"></i>
                            Daftar Satuan
                        </h3>
                        <div class="unit-list-grid">
                            <?php if (empty($units)): ?>
                                <div class="unit-form-card" style="grid-column:1/-1;">
                                    <div class="muted-note">Belum ada satuan. Tambahkan satuan dasar seperti gr, kg, pcs, pack, dus.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($units as $u): ?>
                                    <div class="unit-card">
                                        <span class="unit-code"><?= htmlspecialchars((string)$u['unit_code']) ?></span>
                                        <div class="unit-name"><?= htmlspecialchars((string)$u['unit_name']) ?></div>
                                        <div class="unit-symbol">Simbol: <?= htmlspecialchars((string)$u['unit_symbol']) ?></div>
                                        <span class="unit-state <?= (int)$u['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$u['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                                        <div class="unit-actions">
                                            <button
                                                type="button"
                                                class="btn-mini edit"
                                                onclick='editUnit(<?= json_encode([
                                                    'id' => (int)$u['id'],
                                                    'unit_code' => (string)$u['unit_code'],
                                                    'unit_name' => (string)$u['unit_name'],
                                                    'unit_symbol' => (string)$u['unit_symbol'],
                                                    'is_active' => (int)$u['is_active'],
                                                ], JSON_UNESCAPED_UNICODE) ?>)'>
                                                Edit
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Hapus satuan ini?');">
                                                <input type="hidden" name="action" value="unit_delete">
                                                <input type="hidden" name="unit_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn-mini del">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <details class="fold-card" open>
            <summary class="fold-summary">
                <span class="left">
                    <i data-feather="truck" style="width:16px;height:16px;"></i>
                    Master Supplier
                </span>
                <span class="right">Klik untuk tampilkan/sembunyikan</span>
            </summary>
            <div class="fold-content">
                <div class="supplier-layout">
                    <div class="supplier-form-card">
                        <h3 class="settings-title" style="font-size:16px; margin-bottom:8px;">
                            <i data-feather="plus-circle" style="width:16px;height:16px;"></i>
                            Form Supplier
                        </h3>
                        <form method="POST" id="supplierForm">
                            <input type="hidden" name="action" id="supplierAction" value="supplier_create">
                            <input type="hidden" name="supplier_id" id="supplierId" value="">
                            <div class="field">
                                <label for="supplierCode">Kode Supplier</label>
                                <input type="text" id="supplierCode" name="supplier_code" maxlength="24" placeholder="Contoh: SUP-001" required>
                            </div>
                            <div class="field">
                                <label for="supplierName">Nama Supplier</label>
                                <input type="text" id="supplierName" name="supplier_name" maxlength="120" placeholder="Nama perusahaan / toko" required>
                            </div>
                            <div class="field">
                                <label for="contactName">Nama PIC (Opsional)</label>
                                <input type="text" id="contactName" name="contact_name" maxlength="120" placeholder="Nama kontak supplier">
                            </div>
                            <div class="field">
                                <label for="supplierPhone">No. Telepon (Opsional)</label>
                                <input type="text" id="supplierPhone" name="supplier_phone" maxlength="40" placeholder="08xx-xxxx-xxxx">
                            </div>
                            <div class="field">
                                <label for="supplierAddress">Alamat (Opsional)</label>
                                <textarea id="supplierAddress" name="supplier_address" rows="2" style="min-height:66px;"></textarea>
                            </div>
                            <div class="field">
                                <label for="supplierNotes">Catatan (Opsional)</label>
                                <textarea id="supplierNotes" name="supplier_notes" rows="2" style="min-height:66px;"></textarea>
                            </div>
                            <label class="check-item" style="margin-bottom:12px;">
                                <input type="checkbox" name="supplier_is_active" id="supplierActive" checked>
                                Status Aktif
                            </label>
                            <div class="panel-footer" style="border-top:0;padding-top:0;justify-content:flex-start;">
                                <button type="submit" class="btn-primary" id="supplierSubmitBtn">
                                    <i data-feather="save" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                                    Simpan Supplier
                                </button>
                                <button type="button" class="btn-mini" onclick="resetSupplierForm()">Reset</button>
                            </div>
                        </form>
                    </div>
                    <div>
                        <h3 class="settings-title" style="font-size:16px; margin-bottom:8px;">
                            <i data-feather="users" style="width:16px;height:16px;"></i>
                            Daftar Supplier
                        </h3>
                        <div class="supplier-list-grid">
                            <?php if (empty($suppliers)): ?>
                                <div class="supplier-form-card" style="grid-column:1/-1;">
                                    <div class="muted-note">Belum ada data supplier. Tambahkan supplier untuk kebutuhan modul pembelian/hutang.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($suppliers as $s): ?>
                                    <div class="supplier-card">
                                        <span class="supplier-code"><?= htmlspecialchars((string)$s['supplier_code']) ?></span>
                                        <div class="supplier-name"><?= htmlspecialchars((string)$s['supplier_name']) ?></div>
                                        <div class="supplier-meta">PIC: <?= htmlspecialchars((string)($s['contact_name'] ?? '-')) ?></div>
                                        <div class="supplier-meta">Telp: <?= htmlspecialchars((string)($s['phone'] ?? '-')) ?></div>
                                        <div class="supplier-meta">Alamat: <?= htmlspecialchars((string)($s['address'] ?? '-')) ?></div>
                                        <span class="unit-state <?= (int)$s['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$s['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                                        <div class="unit-actions">
                                            <button
                                                type="button"
                                                class="btn-mini edit"
                                                onclick='editSupplier(<?= json_encode([
                                                    'id' => (int)$s['id'],
                                                    'supplier_code' => (string)$s['supplier_code'],
                                                    'supplier_name' => (string)$s['supplier_name'],
                                                    'contact_name' => (string)($s['contact_name'] ?? ''),
                                                    'phone' => (string)($s['phone'] ?? ''),
                                                    'address' => (string)($s['address'] ?? ''),
                                                    'notes' => (string)($s['notes'] ?? ''),
                                                    'is_active' => (int)$s['is_active'],
                                                ], JSON_UNESCAPED_UNICODE) ?>)'>
                                                Edit
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Hapus supplier ini?');">
                                                <input type="hidden" name="action" value="supplier_delete">
                                                <input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn-mini del">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <details class="fold-card">
            <summary class="fold-summary">
                <span class="left">
                    <i data-feather="database" style="width:16px;height:16px;"></i>
                    Backup Database
                </span>
                <span class="right">Klik untuk tampilkan/sembunyikan</span>
            </summary>
            <div class="fold-content">
                <section class="backup-panel" style="margin-top:0;">
                    <p class="muted-note">Download file backup sesuai kebutuhan. Gunakan SQL untuk restore penuh, CSV untuk analisis transaksi.</p>
                    <div class="backup-actions">
                        <a class="btn-backup" href="backup_export.php?type=full_sql">
                            <i data-feather="download" style="width:14px;height:14px;"></i>
                            Export SQL (Semua Data)
                        </a>
                        <a class="btn-backup" href="backup_export.php?type=transactions_sql">
                            <i data-feather="file-text" style="width:14px;height:14px;"></i>
                            Export SQL (Transaksi)
                        </a>
                        <a class="btn-backup" href="backup_export.php?type=transactions_csv">
                            <i data-feather="file" style="width:14px;height:14px;"></i>
                            Export CSV (Transaksi)
                        </a>
                    </div>
                </section>
            </div>
        </details>

        <details class="fold-card">
            <summary class="fold-summary">
                <span class="left">
                    <i data-feather="file-text" style="width:16px;height:16px;"></i>
                    Master Format Laporan Cetakan
                </span>
                <span class="right">Klik untuk tampilkan/sembunyikan</span>
            </summary>
            <div class="fold-content">
                <section class="template-panel" style="margin-top:0;">
                    <p class="muted-note">Template ini dipakai saat cetak di menu transaksi. Format tersimpan dalam tipe HTML dan bisa diedit kapan saja.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_print_templates">
                        <div class="template-grid">
                            <div class="template-box">
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Template Nota (HTML)</label>
                                <textarea name="template_nota_html" placeholder="<div>Template Nota...</div>"><?= htmlspecialchars($printTemplates['nota']) ?></textarea>
                            </div>
                            <div class="template-box">
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Template Kwitansi (HTML)</label>
                                <textarea name="template_kwitansi_html" placeholder="<div>Template Kwitansi...</div>"><?= htmlspecialchars($printTemplates['kwitansi']) ?></textarea>
                            </div>
                            <div class="template-box" style="grid-column:1 / -1;">
                                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Template Daftar Transaksi (HTML)</label>
                                <textarea name="template_laporan_html" placeholder="<div>Template Daftar Transaksi...</div>"><?= htmlspecialchars($printTemplates['laporan_transaksi']) ?></textarea>
                            </div>
                        </div>
                        <div class="template-hint">
                            Placeholder yang tersedia:
                            <code>{{store_name}}</code>
                            <code>{{store_address}}</code>
                            <code>{{store_phone}}</code>
                            <code>{{bank_account}}</code>
                            <code>{{logo_url}}</code>
                            <code>{{logo_img}}</code>
                            <code>{{invoice_no}}</code>
                            <code>{{transaction_at}}</code>
                            <code>{{customer_name}}</code>
                            <code>{{items_rows}}</code>
                            <code>{{total}}</code>
                            <code>{{discount}}</code>
                            <code>{{downpayment}}</code>
                            <code>{{sisa_bayar}}</code>
                            <code>{{paid_amount}}</code>
                            <code>{{change_amount}}</code>
                            <code>{{status}}</code>
                            <code>{{footer_text}}</code>
                            <div class="muted-note" style="margin-top:8px;">Catatan: <strong>{{total}}</strong> menunjukkan nilai subtotal (sebelum dikurangi diskon dan uang muka). Gunakan <strong>{{sisa_bayar}}</strong> untuk menampilkan sisa yang harus dibayar (subtotal - diskon - uang muka).</div>
                            <div style="margin-top:10px;">
                                Khusus <strong>Template Daftar Transaksi</strong>:
                                <code>{{report_title}}</code>
                                <code>{{period}}</code>
                                <code>{{filter_status}}</code>
                                <code>{{filter_search}}</code>
                                <code>{{print_date}}</code>
                                <code>{{rows}}</code>
                                <code>{{total_transaksi}}</code>
                                <code>{{grand_total}}</code>
                                <code>{{grand_discount}}</code>
                                <code>{{grand_downpayment}}</code>
                                <code>{{grand_sisa}}</code>
                                <code>{{grand_paid}}</code>
                                <div class="muted-note" style="margin-top:8px;"><strong>{{rows}}</strong> diisi otomatis dengan baris <code>&lt;tr&gt;</code> berisi 10 kolom: No, Invoice, Pelanggan, Waktu, Total, Diskon, Uang Muka, Sisa Bayar, Metode, Status. Placeholder toko (<code>{{store_name}}</code>, <code>{{logo_img}}</code>, dll) dan <code>{{footer_text}}</code> juga tersedia di template ini.</div>
                            </div>
                        </div>
                        <div class="panel-footer" style="margin-top:10px;">
                            <button type="submit" class="btn-primary">
                                <i data-feather="save" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                                Simpan Template Cetak
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </details>

        <!-- IMPORT DATABASE -->
        <div style="margin-top:18px;border:1px solid var(--border-color);background:#fff;padding:12px;border-radius:10px;">
            <div style="font-weight:700;margin-bottom:8px;">Impor Database (.sql)</div>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'import_success'): ?>
                <div style="background:#ecfdf5;border:1px solid #10b981;color:#065f46;padding:8px;border-radius:8px;margin-bottom:8px;">Impor database berhasil.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'import_error'): ?>
                <div style="background:#fff4f4;border:1px solid #f43f5e;color:#7f1d1d;padding:8px;border-radius:8px;margin-bottom:8px;">
                    Terjadi kesalahan saat impor: <?= htmlspecialchars((string)($_GET['error'] ?? 'Unknown error')) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Impor database akan mengeksekusi SQL dari file yang Anda pilih dan dapat mengubah/menimpa data saat ini. Pastikan Anda sudah melakukan backup. Lanjutkan impor?');">
                <input type="hidden" name="action" value="import_database">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="file" name="sql_file" accept=".sql" required>
                    <button class="btn-primary" type="submit">Impor Database</button>
                </div>
                <div style="margin-top:8px;font-size:12px;color:var(--text-muted);">File .sql akan dieksekusi langsung pada database. Disarankan membuat backup terlebih dahulu.</div>
            </form>
        </div>
    </main>
</div>
<script>
function editUnit(unit) {
    document.getElementById('unitAction').value = 'unit_update';
    document.getElementById('unitId').value = String(unit.id || '');
    document.getElementById('unitCode').value = String(unit.unit_code || '');
    document.getElementById('unitName').value = String(unit.unit_name || '');
    document.getElementById('unitSymbol').value = String(unit.unit_symbol || '');
    document.getElementById('unitActive').checked = Number(unit.is_active || 0) === 1;
    document.getElementById('unitSubmitBtn').innerText = 'Update Satuan';
}

function resetUnitForm() {
    document.getElementById('unitAction').value = 'unit_create';
    document.getElementById('unitId').value = '';
    document.getElementById('unitCode').value = '';
    document.getElementById('unitName').value = '';
    document.getElementById('unitSymbol').value = '';
    document.getElementById('unitActive').checked = true;
    document.getElementById('unitSubmitBtn').innerText = 'Simpan Satuan';
}

function editSupplier(supplier) {
    document.getElementById('supplierAction').value = 'supplier_update';
    document.getElementById('supplierId').value = String(supplier.id || '');
    document.getElementById('supplierCode').value = String(supplier.supplier_code || '');
    document.getElementById('supplierName').value = String(supplier.supplier_name || '');
    document.getElementById('contactName').value = String(supplier.contact_name || '');
    document.getElementById('supplierPhone').value = String(supplier.phone || '');
    document.getElementById('supplierAddress').value = String(supplier.address || '');
    document.getElementById('supplierNotes').value = String(supplier.notes || '');
    document.getElementById('supplierActive').checked = Number(supplier.is_active || 0) === 1;
    document.getElementById('supplierSubmitBtn').innerText = 'Update Supplier';
}

function resetSupplierForm() {
    document.getElementById('supplierAction').value = 'supplier_create';
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierCode').value = '';
    document.getElementById('supplierName').value = '';
    document.getElementById('contactName').value = '';
    document.getElementById('supplierPhone').value = '';
    document.getElementById('supplierAddress').value = '';
    document.getElementById('supplierNotes').value = '';
    document.getElementById('supplierActive').checked = true;
    document.getElementById('supplierSubmitBtn').innerText = 'Simpan Supplier';
}

feather.replace();
</script>
</body>
</html>
