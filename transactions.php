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
$receiptTemplate = [
    'store_name' => 'Toko POS Kita',
    'store_address' => 'Jl. Kenangan No. 123',
    'store_phone' => '',
    'bank_account' => '',
    'logo_url' => '',
    'header_text' => '',
    'footer_text' => 'Terima kasih sudah berbelanja',
    'show_logo' => '0',
    'show_store_info' => '1',
    'show_item_code' => '0',
    'paper_width_mm' => '58',
    'font_size_px' => '12',
    'header_align' => 'center',
];
$rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'receipt_%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $key = (string)$row['setting_key'];
    $value = (string)$row['setting_value'];
    if ($key === 'receipt_store_name') $receiptTemplate['store_name'] = $value;
    if ($key === 'receipt_store_address') $receiptTemplate['store_address'] = $value;
    if ($key === 'receipt_store_phone') $receiptTemplate['store_phone'] = $value;
    if ($key === 'receipt_bank_account') $receiptTemplate['bank_account'] = $value;
    if ($key === 'receipt_logo_url') $receiptTemplate['logo_url'] = $value;
    if ($key === 'receipt_header_text') $receiptTemplate['header_text'] = $value;
    if ($key === 'receipt_footer_text') $receiptTemplate['footer_text'] = $value;
    if ($key === 'receipt_show_logo') $receiptTemplate['show_logo'] = $value;
    if ($key === 'receipt_show_store_info') $receiptTemplate['show_store_info'] = $value;
    if ($key === 'receipt_show_item_code') $receiptTemplate['show_item_code'] = $value;
    if ($key === 'receipt_paper_width_mm') $receiptTemplate['paper_width_mm'] = $value;
    if ($key === 'receipt_font_size_px') $receiptTemplate['font_size_px'] = $value;
    if ($key === 'receipt_header_align') $receiptTemplate['header_align'] = $value;
}
$printTemplates = [
    'nota' => '',
    'kwitansi' => '',
    'laporan_transaksi' => '',
];
$templateRows = $pdo->query('SELECT template_type, template_content FROM print_templates WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
foreach ($templateRows as $templateRow) {
    $type = (string)$templateRow['template_type'];
    if (array_key_exists($type, $printTemplates)) {
        $printTemplates[$type] = (string)$templateRow['template_content'];
    }
}

// Daftar pembelian untuk filter & tag "sumber pembelian" pada transaksi.
$purchaseOptions = [];
$hasPurchaseModule = (bool)$pdo->query("SHOW TABLES LIKE 'purchase_orders'")->fetch(PDO::FETCH_NUM)
    && (bool)$pdo->query("SHOW TABLES LIKE 'master_suppliers'")->fetch(PDO::FETCH_NUM);
if ($hasPurchaseModule) {
    $purchaseOptions = $pdo->query(
        'SELECT po.id, po.invoice_no, po.invoice_date, sup.supplier_name
         FROM purchase_orders po
         LEFT JOIN master_suppliers sup ON sup.id = po.supplier_id
         ORDER BY po.invoice_date DESC, po.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi SiMade - Transaksi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/products.css">
    <script src="assets/js/feather-local.js"></script>
    <style>
        .tx-toolbar {
            display: grid;
            grid-template-columns: 140px 140px 130px 170px 160px 160px 1fr auto;
            gap: 10px;
            margin: 14px 0 16px;
        }

        .tx-group-row td {
            background: #eef2ff;
            font-weight: 700;
            color: #3730a3;
            border-bottom: 1px solid #c7d2fe;
        }

        .tx-group-meta {
            font-weight: 500;
            font-size: 12px;
            color: #4338ca;
        }

        .tx-purchase-tag {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ===== Label transaksi: dock, drag & drop, pilih massal ===== */
        .tx-group-dock {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .tx-group-dock-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .tx-group-dock-title {
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
        }

        .tx-group-dock-hint {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .tx-group-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tx-chip {
            border: 1px dashed #c7d2fe;
            background: #f5f7ff;
            border-radius: 12px;
            padding: 8px 11px;
            min-width: 150px;
            cursor: pointer;
            text-align: left;
            transition: background .12s, border-color .12s, transform .12s;
        }

        .tx-chip:hover {
            border-color: #818cf8;
        }

        .tx-chip.drop-hover {
            background: #e0e7ff;
            border-color: #4f46e5;
            border-style: solid;
            transform: translateY(-1px);
        }

        .tx-chip.tx-chip-none {
            border-color: #fecaca;
            background: #fff5f5;
        }

        .tx-chip.tx-chip-none.drop-hover {
            background: #fee2e2;
            border-color: #dc2626;
        }

        .tx-chip-name {
            font-size: 12px;
            font-weight: 700;
            color: #3730a3;
            display: block;
        }

        .tx-chip.tx-chip-none .tx-chip-name {
            color: #b91c1c;
        }

        .tx-chip-meta {
            font-size: 11px;
            color: var(--text-muted);
            display: block;
            margin-top: 2px;
        }

        .tx-group-empty {
            font-size: 12px;
            color: var(--text-muted);
        }

        .tx-bulkbar {
            display: none;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--border-color);
        }

        .tx-bulkbar.active {
            display: flex;
        }

        .tx-bulk-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 5px 8px;
            background: #fafafa;
        }

        .tx-bulk-dim {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .tx-bulkbar-count {
            font-size: 12px;
            font-weight: 700;
            color: #3730a3;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            padding: 4px 10px;
        }

        .tx-col-check {
            width: 38px;
            text-align: center !important;
        }

        .tx-table tbody tr[draggable="true"] {
            cursor: grab;
        }

        .tx-table tbody tr.tx-dragging {
            opacity: .45;
        }

        .tx-table tbody tr.tx-row-selected td {
            background: #f5f3ff;
        }

        .tx-group-row.drop-hover td {
            background: #c7d2fe;
            box-shadow: inset 0 0 0 2px #4f46e5;
        }

        .tx-group-badge {
            font-size: 11px;
            font-weight: 700;
            color: #3730a3;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
            padding: 3px 8px;
            display: inline-block;
        }

        .tx-grip {
            color: #9ca3af;
            font-size: 12px;
            letter-spacing: 1px;
            cursor: grab;
            user-select: none;
        }

        /* Papan tarik-lepas dua panel di modal Kelola Label. */
        .tx-board {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px;
        }

        .tx-board-pane {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #f9fafb;
            display: flex;
            flex-direction: column;
            min-height: 240px;
        }

        .tx-board-pane.drop-hover {
            background: #eef2ff;
            border-color: #4f46e5;
        }

        .tx-board-head {
            padding: 9px 11px;
            border-bottom: 1px solid var(--border-color);
            font-size: 12px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
        }

        .tx-board-list {
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            overflow: auto;
            max-height: 300px;
            flex: 1;
        }

        .tx-board-item {
            border: 1px solid var(--border-color);
            background: #fff;
            border-radius: 10px;
            padding: 7px 9px;
            font-size: 12px;
            cursor: grab;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
        }

        .tx-board-item.tx-dragging {
            opacity: .45;
        }

        .tx-board-item-main {
            min-width: 0;
        }

        .tx-board-item-name {
            font-weight: 700;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tx-board-item-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        .tx-board-empty {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            padding: 16px 8px;
        }

        .tx-group-manage-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 9px 11px;
            background: #fff;
            margin-bottom: 8px;
        }

        @media (max-width: 760px) {
            .tx-board {
                grid-template-columns: 1fr;
            }
        }

        .tx-input,
        .tx-select {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px;
            font-size: 13px;
            background: #fff;
        }

        .tx-table-wrap {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .tx-table-scroll {
            max-height: calc(100vh - 270px);
            overflow: auto;
        }

        .tx-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tx-table th,
        .tx-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            text-align: left;
        }

        .tx-col-no {
            width: 56px;
            text-align: center !important;
        }

        .tx-table th {
            background: #f9fafb;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.04em;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .badge {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 999px;
            display: inline-block;
        }

        .badge.pending {
            background: #ffedd5;
            color: #9a3412;
            border: 1px solid #fdba74;
        }

        .badge.paid {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .btn-link {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 9px;
            background: #fff;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #1f2937;
        }

        .btn-link.btn-detail {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .btn-link.btn-nota {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .btn-link.btn-kwitansi {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #c2410c;
        }

        .btn-link.btn-continue {
            background: #f5f3ff;
            border-color: #ddd6fe;
            color: #6d28d9;
        }

        .tx-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #fff;
            border-top: 1px solid var(--border-color);
        }

        .btn-danger-lite {
            border-color: #fecaca !important;
            color: #b91c1c !important;
            background: #fff1f2 !important;
        }

        .btn-danger-lite:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .tx-pager-btn {
            border: 1px solid var(--border-color);
            background: #fff;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .tx-modal {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.6);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 14px;
        }

        .tx-modal.active {
            display: flex;
        }

        .tx-modal-card {
            background: #fff;
            border-radius: 16px;
            width: min(760px, 100%);
            max-height: 92vh;
            overflow: auto;
            border: 1px solid var(--border-color);
        }

        .tx-modal-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tx-modal-body {
            padding: 14px 16px;
        }

        .toast-wrap {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast {
            min-width: 280px;
            max-width: 360px;
            border-radius: 12px;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            background: #fff;
            box-shadow: var(--shadow-md);
            font-size: 13px;
            font-weight: 600;
        }

        .cancel-modal {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.55);
            z-index: 1200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .cancel-modal.active {
            display: flex;
        }

        .cancel-modal-card {
            width: min(420px, 100%);
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.16);
            overflow: hidden;
        }

        .cancel-modal-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cancel-modal-body {
            padding: 18px;
        }

        .cancel-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 14px;
        }

        .cancel-input {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 11px 12px;
            font-size: 14px;
            outline: none;
        }

        .cancel-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }

        .cancel-validation {
            display: none;
            margin-top: 10px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .toast.success {
            border-color: #6ee7b7;
            background: #ecfdf5;
            color: #065f46;
        }

        .toast.error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .toast.info {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1e40af;
        }

        .tx-modal-card.tx-modal-wide {
            width: min(900px, 100%);
        }

        @media (max-width: 1200px) {
            .tx-toolbar {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 900px) {
            .tx-toolbar {
                grid-template-columns: 1fr;
            }
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
                <li class="active"><a href="transactions.php" title="Transaksi"><i data-feather="list"></i></a></li>
                <li><a href="products.php" title="Master Produk"><i data-feather="package"></i></a></li>
                <li><a href="price_variants.php" title="Varian Harga"><i data-feather="tag"></i></a></li>
                <li><a href="laporan.php" title="Laporan"><i data-feather="bar-chart-2"></i></a></li>
                <li><a href="settings.php" title="Pengaturan"><i data-feather="settings"></i></a></li>
                <li><a href="logout.php" title="Logout"><i data-feather="log-out"></i></a></li>
            </ul>
        </nav>

        <main class="products-section" style="flex:1; padding: 24px 30px; max-width: 100vw;">
            <header class="section-header" style="margin-bottom: 8px;">
                <div>
                    <h1 style="font-size: 26px; margin-bottom: 4px;">Daftar Transaksi</h1>
                    <p class="text-muted">Menu terpisah untuk mengelola transaksi agar kasir tetap rapi walau data pelanggan banyak.</p>
                </div>
            </header>

            <div class="tx-toolbar">
                <input type="date" id="txStartDate" class="tx-input" title="Tanggal Awal">
                <input type="date" id="txEndDate" class="tx-input" title="Tanggal Akhir">
                <select id="txStatus" class="tx-select">
                    <option value="all">Semua Status</option>
                    <option value="pending">Belum Bayar</option>
                    <option value="paid">Sudah Bayar</option>
                </select>
                <select id="txPurchase" class="tx-select" title="Filter sumber pembelian">
                    <option value="">Semua Pembelian</option>
                    <option value="none">Tanpa Pembelian</option>
                    <?php foreach ($purchaseOptions as $po): ?>
                        <option value="<?= (int)$po['id'] ?>"><?= htmlspecialchars($po['invoice_no'] . ' • ' . ($po['supplier_name'] ?: 'Tanpa Supplier'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="txGroup" class="tx-select" title="Filter label transaksi">
                    <option value="">Semua Label</option>
                    <option value="none">Tanpa Label</option>
                </select>
                <select id="txGroupBy" class="tx-select" title="Cara menampilkan daftar transaksi">
                    <option value="">Tampilkan: Datar</option>
                    <option value="group">Tampilkan per: Label</option>
                    <option value="purchase">Tampilkan per: Pembelian</option>
                    <option value="supplier">Tampilkan per: Supplier</option>
                    <option value="date">Tampilkan per: Tanggal</option>
                </select>
                <input type="text" id="txSearch" class="tx-input" placeholder="Cari invoice / nama pelanggan">
                <div style="display:flex;gap:8px;">
                    <button id="btnSearch" class="btn-primary">Cari</button>
                    <button id="btnPrintList" class="btn-primary" type="button" title="Cetak daftar transaksi sesuai filter">
                        <i data-feather="printer" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                        Cetak Daftar
                    </button>
                </div>
            </div>

            <div class="tx-group-dock">
                <div class="tx-group-dock-head">
                    <div>
                        <div class="tx-group-dock-title">Label Transaksi</div>
                        <div class="tx-group-dock-hint">Label bebas untuk keperluan Anda sendiri (mis. Titipan Masjid, Reseller Blok A). Tarik baris ke salah satu kotak, atau centang beberapa baris lalu pakai tombol di bawah.</div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn-link btn-detail" type="button" id="btnNewGroup">
                            <i data-feather="folder-plus" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                            Buat Label
                        </button>
                        <button class="btn-link" type="button" id="btnManageGroup">
                            <i data-feather="sliders" style="width:14px;height:14px;vertical-align:text-bottom;"></i>
                            Kelola Label
                        </button>
                    </div>
                </div>
                <div class="tx-group-chips" id="txGroupChips"></div>
                <div class="tx-bulkbar" id="txBulkBar">
                    <span class="tx-bulkbar-count" id="txBulkCount">0 dipilih</span>

                    <div class="tx-bulk-group">
                        <span class="tx-bulk-dim">Label</span>
                        <select class="tx-select" id="txBulkGroup" style="min-width:190px;">
                            <option value="">— Pilih label tujuan —</option>
                        </select>
                        <button class="btn-link btn-detail" type="button" id="btnBulkAssign">Masukkan</button>
                        <button class="btn-link btn-danger-lite" type="button" id="btnBulkRemove">Keluarkan</button>
                    </div>

                    <div class="tx-bulk-group">
                        <span class="tx-bulk-dim">Asal Pembelian</span>
                        <select class="tx-select" id="txBulkPurchase" style="min-width:190px;">
                            <option value="">— Pilih pembelian —</option>
                            <?php foreach ($purchaseOptions as $po): ?>
                                <option value="<?= (int)$po['id'] ?>"><?= htmlspecialchars($po['invoice_no'] . ' • ' . ($po['supplier_name'] ?: 'Tanpa Supplier'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-link btn-detail" type="button" id="btnBulkPurchase">Tandai</button>
                        <button class="btn-link btn-danger-lite" type="button" id="btnBulkPurchaseClear">Hapus Tag</button>
                    </div>

                    <button class="btn-link" type="button" id="btnBulkClear">Bersihkan Pilihan</button>
                </div>
            </div>

            <div class="tx-table-wrap">
                <div class="tx-table-scroll">
                    <table class="tx-table">
                        <thead>
                            <tr>
                                <th class="tx-col-check"><input type="checkbox" id="txCheckAll" title="Pilih semua baris di halaman ini"></th>
                                <th class="tx-col-no">No</th>
                                <th>Invoice</th>
                                <th>Pelanggan</th>
                                <th>Label</th>
                                <th>Pembelian</th>
                                <th>Waktu</th>
                                <th>Total</th>
                                <th>Diskon</th>
                                <th>Uang Muka</th>
                                <th>Sisa Bayar</th>
                                <th>Metode</th>
                                <th>Status</th>
                                <th>Flag Cetak</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="txTbody">
                            <tr>
                                <td colspan="15" style="text-align:center;color:var(--text-muted);">Memuat data...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="tx-pagination">
                    <div id="txPageInfo" style="font-size:12px;color:var(--text-muted);"></div>
                    <div style="display:flex;gap:8px;">
                        <button class="tx-pager-btn" id="txPrev">Sebelumnya</button>
                        <button class="tx-pager-btn" id="txNext">Berikutnya</button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="tx-modal" id="txModal">
        <div class="tx-modal-card">
            <div class="tx-modal-head">
                <strong>Detail Transaksi</strong>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <button class="btn-link btn-nota" type="button" id="txDetailPrintNota">Cetak Nota</button>
                    <button class="btn-link btn-kwitansi" type="button" id="txDetailPrintKwitansi">Cetak Kwitansi</button>
                    <button class="btn-link" type="button" onclick="closeTxModal()">Tutup</button>
                </div>
            </div>
            <div class="tx-modal-body" id="txModalBody"></div>
        </div>
    </div>
    <div class="tx-modal" id="groupModal">
        <div class="tx-modal-card tx-modal-wide">
            <div class="tx-modal-head">
                <strong>Kelola Label Transaksi</strong>
                <button class="btn-link" type="button" onclick="closeGroupModal()">Tutup</button>
            </div>
            <div class="tx-modal-body">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Buat label baru</div>
                <div class="tx-group-manage-row">
                    <input type="text" id="newGroupName" class="tx-input" style="flex:1;min-width:180px;" placeholder="Nama label (mis. Titipan Masjid)">
                    <input type="text" id="newGroupNote" class="tx-input" style="flex:1;min-width:180px;" placeholder="Catatan (opsional)">
                    <button class="btn-link btn-detail" type="button" id="btnCreateGroup">Simpan</button>
                </div>

                <div style="font-size:11px;color:var(--text-muted);margin:14px 0 6px;">Label tersimpan</div>
                <div id="groupManageList"></div>

                <div style="font-size:11px;color:var(--text-muted);margin:16px 0 0;">
                    Papan tarik-lepas — pilih label, lalu tarik transaksi antar panel.
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
                    <select class="tx-select" id="boardGroupSelect" style="flex:1;min-width:220px;">
                        <option value="">— Pilih label —</option>
                    </select>
                    <input type="text" id="boardSearch" class="tx-input" style="flex:1;min-width:180px;" placeholder="Cari invoice / pelanggan">
                </div>
                <div class="tx-board">
                    <div class="tx-board-pane" id="boardPaneAvailable" data-board-target="none">
                        <div class="tx-board-head">
                            <span>Tanpa Label</span>
                            <span id="boardAvailableCount" style="color:var(--text-muted);font-weight:500;"></span>
                        </div>
                        <div class="tx-board-list" id="boardAvailableList"></div>
                    </div>
                    <div class="tx-board-pane" id="boardPaneMembers" data-board-target="group">
                        <div class="tx-board-head">
                            <span id="boardMembersTitle">Isi Label</span>
                            <span id="boardMembersCount" style="color:var(--text-muted);font-weight:500;"></span>
                        </div>
                        <div class="tx-board-list" id="boardMembersList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="cancel-modal" id="cancelPaidModal">
        <div class="cancel-modal-card">
            <div class="cancel-modal-head">
                <strong>Batal Bayar</strong>
                <button class="btn-link" type="button" onclick="closeCancelPaidModal()">Tutup</button>
            </div>
            <div class="cancel-modal-body">
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;">Masukkan password login untuk mengubah transaksi sudah bayar menjadi belum bayar.</p>
                <input type="password" id="cancelPaidPassword" class="cancel-input" placeholder="Masukkan password login">
                <div id="cancelPaidValidation" class="cancel-validation"></div>
                <div class="cancel-modal-actions">
                    <button class="btn-link" type="button" onclick="closeCancelPaidModal()">Batal</button>
                    <button class="btn-link btn-danger-lite" type="button" id="confirmCancelPaidBtn">Konfirmasi</button>
                </div>
            </div>
        </div>
    </div>
    <div class="cancel-modal" id="newLabelModal">
        <div class="cancel-modal-card">
            <div class="cancel-modal-head">
                <strong>Buat Label Baru</strong>
                <button class="btn-link" type="button" onclick="closeNewLabelModal()">Tutup</button>
            </div>
            <div class="cancel-modal-body">
                <p id="newLabelHint" style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;"></p>
                <input type="text" id="newLabelNameInput" class="cancel-input" placeholder="Nama label (mis. Titipan Masjid)" maxlength="120" style="margin-bottom:8px;">
                <input type="text" id="newLabelNoteInput" class="cancel-input" placeholder="Catatan (opsional)" maxlength="255">
                <div id="newLabelValidation" class="cancel-validation"></div>
                <div class="cancel-modal-actions">
                    <button class="btn-link" type="button" onclick="closeNewLabelModal()">Batal</button>
                    <button class="btn-link btn-detail" type="button" id="submitNewLabelBtn">Simpan</button>
                </div>
            </div>
        </div>
    </div>
    <div class="cancel-modal" id="confirmModal">
        <div class="cancel-modal-card">
            <div class="cancel-modal-head">
                <strong id="confirmModalTitle">Konfirmasi</strong>
                <button class="btn-link" type="button" onclick="resolveConfirm(false)">Tutup</button>
            </div>
            <div class="cancel-modal-body">
                <p id="confirmModalMessage" style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;"></p>
                <div class="cancel-modal-actions">
                    <button class="btn-link" type="button" onclick="resolveConfirm(false)">Batal</button>
                    <button class="btn-link btn-danger-lite" type="button" id="confirmModalOkBtn" onclick="resolveConfirm(true)">Hapus</button>
                </div>
            </div>
        </div>
    </div>
    <div id="printArea" class="hidden"></div>
    <div id="toastWrap" class="toast-wrap"></div>

    <script>
        feather.replace();
        window.__RECEIPT_TEMPLATE__ = <?= json_encode($receiptTemplate, JSON_UNESCAPED_UNICODE) ?>;
        window.__PRINT_TEMPLATES__ = <?= json_encode($printTemplates, JSON_UNESCAPED_UNICODE) ?>;
        window.__PURCHASE_OPTIONS__ = <?= json_encode($purchaseOptions, JSON_UNESCAPED_UNICODE) ?>;
        const txStartDate = document.getElementById('txStartDate');
        const txEndDate = document.getElementById('txEndDate');
        const txStatus = document.getElementById('txStatus');
        const txPurchase = document.getElementById('txPurchase');
        const txGroup = document.getElementById('txGroup');
        const txGroupBy = document.getElementById('txGroupBy');
        const txCheckAll = document.getElementById('txCheckAll');
        const txGroupChips = document.getElementById('txGroupChips');
        const txBulkBar = document.getElementById('txBulkBar');
        const txBulkCount = document.getElementById('txBulkCount');
        const txBulkGroup = document.getElementById('txBulkGroup');
        const btnNewGroup = document.getElementById('btnNewGroup');
        const btnManageGroup = document.getElementById('btnManageGroup');
        const btnBulkAssign = document.getElementById('btnBulkAssign');
        const btnBulkRemove = document.getElementById('btnBulkRemove');
        const txBulkPurchase = document.getElementById('txBulkPurchase');
        const btnBulkPurchase = document.getElementById('btnBulkPurchase');
        const btnBulkPurchaseClear = document.getElementById('btnBulkPurchaseClear');
        const btnBulkClear = document.getElementById('btnBulkClear');
        const groupModal = document.getElementById('groupModal');
        const newGroupName = document.getElementById('newGroupName');
        const newGroupNote = document.getElementById('newGroupNote');
        const btnCreateGroup = document.getElementById('btnCreateGroup');
        const groupManageList = document.getElementById('groupManageList');
        const boardGroupSelect = document.getElementById('boardGroupSelect');
        const boardSearch = document.getElementById('boardSearch');
        const boardAvailableList = document.getElementById('boardAvailableList');
        const boardMembersList = document.getElementById('boardMembersList');
        const boardAvailableCount = document.getElementById('boardAvailableCount');
        const boardMembersCount = document.getElementById('boardMembersCount');
        const boardMembersTitle = document.getElementById('boardMembersTitle');
        const boardPaneAvailable = document.getElementById('boardPaneAvailable');
        const boardPaneMembers = document.getElementById('boardPaneMembers');
        const txSearch = document.getElementById('txSearch');
        const btnSearch = document.getElementById('btnSearch');
        const btnPrintList = document.getElementById('btnPrintList');
        const txTbody = document.getElementById('txTbody');
        const txPageInfo = document.getElementById('txPageInfo');
        const txPrev = document.getElementById('txPrev');
        const txNext = document.getElementById('txNext');
        const txModal = document.getElementById('txModal');
        const txModalBody = document.getElementById('txModalBody');
        const txDetailPrintNota = document.getElementById('txDetailPrintNota');
        const txDetailPrintKwitansi = document.getElementById('txDetailPrintKwitansi');
        const cancelPaidModal = document.getElementById('cancelPaidModal');
        const cancelPaidPassword = document.getElementById('cancelPaidPassword');
        const cancelPaidValidation = document.getElementById('cancelPaidValidation');
        const confirmCancelPaidBtn = document.getElementById('confirmCancelPaidBtn');
        const newLabelModal = document.getElementById('newLabelModal');
        const newLabelHint = document.getElementById('newLabelHint');
        const newLabelNameInput = document.getElementById('newLabelNameInput');
        const newLabelNoteInput = document.getElementById('newLabelNoteInput');
        const newLabelValidation = document.getElementById('newLabelValidation');
        const submitNewLabelBtn = document.getElementById('submitNewLabelBtn');
        const confirmModal = document.getElementById('confirmModal');
        const confirmModalTitle = document.getElementById('confirmModalTitle');
        const confirmModalMessage = document.getElementById('confirmModalMessage');
        const confirmModalOkBtn = document.getElementById('confirmModalOkBtn');
        const printArea = document.getElementById('printArea');
        const toastWrap = document.getElementById('toastWrap');
        const receiptTemplate = (window.__RECEIPT_TEMPLATE__ && typeof window.__RECEIPT_TEMPLATE__ === 'object') ? window.__RECEIPT_TEMPLATE__ : {};
        const printTemplates = (window.__PRINT_TEMPLATES__ && typeof window.__PRINT_TEMPLATES__ === 'object') ? window.__PRINT_TEMPLATES__ : {};
        const purchaseOptions = Array.isArray(window.__PURCHASE_OPTIONS__) ? window.__PURCHASE_OPTIONS__ : [];

        let page = 1;
        let totalPages = 1;
        let searchDebounceTimer = null;
        let activeCancelPaidId = 0;
        let activeDetailId = 0;
        // Label transaksi: cache daftar label, baris terpilih, dan payload drag aktif.
        let transactionGroups = [];
        let ungroupedCount = 0;
        const selectedTxIds = new Set();
        let dragPayloadIds = [];
        let boardSearchTimer = null;

        function formatRupiah(value) {
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(Number(value || 0));
        }

        function showToast(message, type = 'info') {
            const div = document.createElement('div');
            div.className = `toast ${type}`;
            div.textContent = message;
            toastWrap.appendChild(div);
            setTimeout(() => {
                div.style.opacity = '0';
                div.style.transform = 'translateY(-4px)';
            }, 2600);
            setTimeout(() => div.remove(), 3200);
        }

        function setCancelValidation(message = '') {
            cancelPaidValidation.textContent = message;
            cancelPaidValidation.style.display = message ? 'block' : 'none';
        }

        function escHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function applyPrintTemplate(templateHtml, placeholders) {
            let output = String(templateHtml || '');
            Object.entries(placeholders).forEach(([key, val]) => {
                output = output.split(`{{${key}}}`).join(String(val ?? ''));
            });
            return output;
        }
        /*<div>${escHtml(it.product_name || '-')} ${it.variant_name ? `(${escHtml(it.variant_name)})` : ''}</div>*/
        function buildItemsRows(items) {
            return items.map((it) => `
        <div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:6px;">
            <div>

                <div>${escHtml(it.product_name || '-')} </div>
                <div style="font-size:11px;color:#6b7280;">${Number(it.qty || 0)} x ${formatRupiah(it.price || 0)}</div>
            </div>
            <div style="text-align:right;">${formatRupiah(it.subtotal || 0)}</div>
        </div>
    `).join('');
        }

        function setToday() {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            const firstDayOfMonth = `${yyyy}-${mm}-01`;
            const today = `${yyyy}-${mm}-${dd}`;
            txStartDate.value = firstDayOfMonth;
            txEndDate.value = today;
        }
        // Nilai grouping: '' | 'group' | 'purchase' | 'supplier' | 'date'
        function currentGroupBy() {
            return txGroupBy ? (txGroupBy.value || '') : '';
        }

        function currentGroupFilter() {
            return txGroup ? (txGroup.value || '') : '';
        }

        // Isi ulang <select> label tanpa menghilangkan pilihan yang sedang aktif.
        function fillGroupSelect(select, placeholder, includeNone) {
            if (!select) return;
            const previous = select.value;
            const options = ['<option value="">' + escHtml(placeholder) + '</option>'];
            if (includeNone) options.push('<option value="none">Tanpa Label</option>');
            transactionGroups.forEach((g) => {
                options.push(`<option value="${Number(g.id)}">${escHtml(g.group_name)}</option>`);
            });
            select.innerHTML = options.join('');
            const stillExists = Array.from(select.options).some((opt) => opt.value === previous);
            select.value = stillExists ? previous : '';
        }

        function renderGroupChips() {
            if (!txGroupChips) return;
            const noneChip = `
        <button type="button" class="tx-chip tx-chip-none" data-group-id="" title="Tarik ke sini untuk melepas label transaksi">
            <span class="tx-chip-name">Tanpa Label</span>
            <span class="tx-chip-meta">${ungroupedCount} transaksi &bull; klik untuk filter</span>
        </button>`;
            if (!transactionGroups.length) {
                txGroupChips.innerHTML = noneChip
                    + '<div class="tx-group-empty" style="align-self:center;">Belum ada label. Klik "Buat Label" untuk membuat yang pertama.</div>';
                return;
            }
            txGroupChips.innerHTML = noneChip + transactionGroups.map((g) => `
        <button type="button" class="tx-chip" data-group-id="${Number(g.id)}" title="${escHtml(g.group_note || 'Tarik transaksi ke sini')}">
            <span class="tx-chip-name">${escHtml(g.group_name)}</span>
            <span class="tx-chip-meta">${Number(g.tx_count || 0)} transaksi &bull; ${formatRupiah(g.tx_total || 0)}</span>
        </button>`).join('');
        }

        async function loadGroups() {
            try {
                const res = await fetch('checkout_actions.php?action=list_groups');
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal memuat label');
                transactionGroups = Array.isArray(data.groups) ? data.groups : [];
                ungroupedCount = Number(data.ungrouped_count || 0);
            } catch (err) {
                transactionGroups = [];
                ungroupedCount = 0;
            }
            renderGroupChips();
            fillGroupSelect(txGroup, 'Semua Label', true);
            fillGroupSelect(txBulkGroup, '\u2014 Pilih label tujuan \u2014', false);
            fillGroupSelect(boardGroupSelect, '\u2014 Pilih label \u2014', false);
            renderGroupManageList();
        }

        // ===== Pilih massal lewat checkbox =====
        function updateBulkBar() {
            if (!txBulkBar) return;
            const count = selectedTxIds.size;
            txBulkBar.classList.toggle('active', count > 0);
            if (txBulkCount) txBulkCount.textContent = `${count} dipilih`;
            if (txCheckAll) {
                const boxes = Array.from(document.querySelectorAll('.tx-row-check'));
                const checked = boxes.filter((b) => b.checked).length;
                txCheckAll.checked = boxes.length > 0 && checked === boxes.length;
                txCheckAll.indeterminate = checked > 0 && checked < boxes.length;
            }
        }

        function clearSelection() {
            selectedTxIds.clear();
            document.querySelectorAll('.tx-row-check').forEach((box) => {
                box.checked = false;
                const row = box.closest('tr');
                if (row) row.classList.remove('tx-row-selected');
            });
            updateBulkBar();
        }

        // ===== Inti fitur: simpan penempatan label =====
        // Dipakai bersama oleh drag-drop, aksi massal, papan modal, dan panel detail.
        async function assignTransactionsToGroup(ids, groupId) {
            const payloadIds = (ids || []).map(Number).filter((v) => v > 0);
            if (!payloadIds.length) {
                showToast('Pilih minimal satu transaksi.', 'error');
                return false;
            }
            const normalizedGroupId = (groupId === '' || groupId === null || groupId === undefined)
                ? ''
                : Number(groupId);
            try {
                const res = await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'assign_group',
                        transaction_ids: payloadIds,
                        group_id: normalizedGroupId,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Gagal memindahkan transaksi');
                showToast(data.message || 'Transaksi dipindahkan.', 'success');
                clearSelection();
                await loadGroups();
                await loadTransactions();
                if (groupModal && groupModal.classList.contains('active')) await refreshBoard();
                return true;
            } catch (err) {
                showToast(err.message || 'Gagal memindahkan transaksi', 'error');
                return false;
            }
        }

        function dateKeyOf(row) {
            // transaction_at berformat 'YYYY-MM-DD HH:MM:SS'.
            return String(row.transaction_at || '').slice(0, 10);
        }

        function groupKeyOf(row, groupBy) {
            if (groupBy === 'group') {
                return row.group_id ? `grp:${row.group_id}` : 'none';
            }
            if (groupBy === 'date') {
                const key = dateKeyOf(row);
                return key ? `day:${key}` : 'none';
            }
            if (groupBy === 'supplier') {
                return row.supplier_name ? `sup:${row.supplier_name}` : 'none';
            }
            return row.purchase_id ? `po:${row.purchase_id}` : 'none';
        }

        function groupLabelOf(row, groupBy) {
            if (groupBy === 'group') {
                if (!row.group_id) return 'Tanpa Label';
                const note = row.group_note ? ` • ${row.group_note}` : '';
                return `${row.group_name || 'Label #' + row.group_id}${note}`;
            }
            if (groupBy === 'date') {
                const key = dateKeyOf(row);
                return key ? formatDateId(key) : 'Tanpa Tanggal';
            }
            if (!row.purchase_id) return 'Tanpa Pembelian';
            if (groupBy === 'supplier') return row.supplier_name || 'Tanpa Supplier';
            const supplier = row.supplier_name ? ` • ${row.supplier_name}` : '';
            const tanggal = row.purchase_invoice_date ? ` • ${formatDateId(row.purchase_invoice_date)}` : '';
            return `${row.purchase_invoice_no || 'PO #' + row.purchase_id}${supplier}${tanggal}`;
        }

        function groupCellOf(row) {
            if (!row.group_id) {
                return '<span style="color:var(--text-muted);">&mdash;</span>';
            }
            return `<span class="tx-group-badge">${escHtml(row.group_name || 'Label #' + row.group_id)}</span>`;
        }

        function purchaseCellOf(row) {
            if (!row.purchase_id) {
                return '<span style="color:var(--text-muted);">—</span>';
            }
            return `<div>${escHtml(row.purchase_invoice_no || 'PO #' + row.purchase_id)}</div>
                    <div class="tx-purchase-tag">${escHtml(row.supplier_name || 'Tanpa Supplier')}</div>`;
        }

        // Bagi baris jadi grup berurutan; server sudah mengurutkan sesuai group_by.
        function buildGroups(rows, groupBy) {
            const groups = [];
            let current = null;
            rows.forEach((row) => {
                const key = groupKeyOf(row, groupBy);
                if (!current || current.key !== key) {
                    current = { key, label: groupLabelOf(row, groupBy), rows: [] };
                    groups.push(current);
                }
                current.rows.push(row);
            });
            return groups;
        }

        function groupTotalOf(rows) {
            return rows.reduce((sum, row) => sum + (Number(row.subtotal || 0) - Number(row.discount || 0)), 0);
        }

        async function loadTransactions() {
            txTbody.innerHTML = '<tr><td colspan="15" style="text-align:center;color:var(--text-muted);">Memuat data...</td></tr>';
            const groupBy = currentGroupBy();
            const params = new URLSearchParams({
                action: 'list_today',
                page: String(page),
                page_size: '20',
                start_date: (txStartDate.value || '').trim(),
                end_date: (txEndDate.value || '').trim(),
                status: txStatus.value || 'all',
                purchase_id: txPurchase ? (txPurchase.value || '') : '',
                group_id: currentGroupFilter(),
                group_by: groupBy,
                search: (txSearch.value || '').trim(),
            });
            const res = await fetch(`checkout_actions.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                txTbody.innerHTML = '<tr><td colspan="15" style="text-align:center;color:#b91c1c;">Gagal memuat data transaksi</td></tr>';
                return;
            }
            const rows = data.transactions || [];
            const pg = data.pagination || {};
            page = Number(pg.page || 1);
            totalPages = Number(pg.total_pages || 1);
            const pageSize = Number(pg.page_size || 20);
            const rowStart = groupBy ? 1 : (((page - 1) * pageSize) + 1);
            if (groupBy) {
                // Mode berkelompok menampilkan seluruh data terfilter, jadi paginasi dinonaktifkan.
                const modeLabels = {
                    group: 'per label',
                    supplier: 'per supplier',
                    purchase: 'per pembelian',
                    date: 'per tanggal',
                };
                const modeLabel = modeLabels[groupBy] || 'berkelompok';
                txPageInfo.textContent = `Tampilan ${modeLabel} • Semua ${pg.total_rows || 0} transaksi sesuai filter`;
                txPrev.disabled = true;
                txNext.disabled = true;
            } else {
                txPageInfo.textContent = `Halaman ${pg.page || 1} / ${pg.total_pages || 1} • Total ${pg.total_rows || 0} transaksi`;
                txPrev.disabled = page <= 1;
                txNext.disabled = page >= totalPages;
            }

            if (!rows.length) {
                txTbody.innerHTML = '<tr><td colspan="15" style="text-align:center;color:var(--text-muted);">Tidak ada data.</td></tr>';
                updateBulkBar();
                return;
            }

            if (groupBy) {
                let counter = 0;
                txTbody.innerHTML = buildGroups(rows, groupBy).map((group) => {
                    // Hanya mode per-label yang bisa menerima drop di baris judulnya.
                    const dropAttr = groupBy === 'group'
                        ? ` data-drop-group="${group.key === 'none' ? '' : String(group.key).replace('grp:', '')}"`
                        : '';
                    const dropHint = groupBy === 'group'
                        ? '<span class="tx-group-meta"> • tarik baris ke sini</span>'
                        : '';
                    const head = `
        <tr class="tx-group-row"${dropAttr}>
            <td colspan="15">
                ${escHtml(group.label)}
                <span class="tx-group-meta">— ${group.rows.length} transaksi • ${formatRupiah(groupTotalOf(group.rows))}</span>
                ${dropHint}
            </td>
        </tr>`;
                    const body = group.rows.map((row) => buildTxRow(row, ++counter)).join('');
                    return head + body;
                }).join('');
                updateBulkBar();
                return;
            }

            txTbody.innerHTML = rows.map((row, idx) => buildTxRow(row, rowStart + idx)).join('');
            updateBulkBar();
        }

        function buildTxRow(row, rowNumber) {
            const txId = Number(row.id);
            const isSelected = selectedTxIds.has(txId);
            return `
        <tr draggable="true" data-tx-id="${txId}" class="${isSelected ? 'tx-row-selected' : ''}">
            <td class="tx-col-check">
                <input type="checkbox" class="tx-row-check" data-tx-id="${txId}" ${isSelected ? 'checked' : ''}>
                <div class="tx-grip" title="Tarik baris ini ke kotak label di atas">&#8942;&#8942;</div>
            </td>
            <td class="tx-col-no">${rowNumber}</td>
            <td>${row.invoice_no}</td>
            <td>${row.customer_name || 'Pelanggan Umum'}</td>
            <td>${groupCellOf(row)}</td>
            <td>${purchaseCellOf(row)}</td>
            <td>${row.transaction_at}</td>
            <td>${formatRupiah(row.subtotal)}</td>
            <td>${formatRupiah(row.discount || 0)}</td>
            <td>${formatRupiah(row.downpayment || 0)}</td>
            <td>${formatRupiah((row.subtotal || 0) - (row.discount || 0) - (row.downpayment || 0))}</td>
            <td>
                <div>${row.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai'}</div>
                <div style="font-size:11px;color:var(--text-muted);">${row.payment_note ? escHtml(row.payment_note) : (Number(row.is_non_cash) === 1 ? 'Non tunai' : 'Tunai')}</div>
            </td>
            <td><span class="badge ${row.status}">${row.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar'}</span></td>
            <td><span class="badge ${Number(row.is_printed) === 1 ? 'paid' : 'pending'}">${Number(row.is_printed) === 1 ? 'Sudah Cetak' : 'Belum Cetak'}</span></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="btn-link btn-detail" onclick="openTxDetail(${row.id})">Detail</button>
                <button class="btn-link btn-nota" onclick="printTxNota(${row.id})">Cetak Nota</button>
                <button class="btn-link btn-kwitansi" onclick="printTxKwitansi(${row.id})">Cetak Kwitansi</button>
                ${row.status === 'paid' ? `<button class="btn-link btn-danger-lite" onclick="openCancelPaidModal(${row.id})">Batal Bayar</button>` : ''}
                ${row.status === 'pending' ? `<a class="btn-link btn-continue" href="index.php?draft_id=${row.id}">Lanjutkan</a>` : ''}
                <button class="btn-link btn-danger-lite" onclick="deleteTx(${row.id})" ${(row.status === 'pending' && Number(row.is_printed) === 0) ? '' : 'disabled'}>Delete</button>
            </td>
        </tr>
    `;
        }
        window.openTxDetail = async function(id) {
            const res = await fetch(`checkout_actions.php?action=detail&id=${id}`);
            const data = await res.json();
            if (!data.success) {
                showToast(data.message || 'Gagal memuat detail', 'error');
                return;
            }
            activeDetailId = Number(id || 0);
            const tx = data.transaction || {};
            const items = data.items || [];
            const totalQty = items.reduce((sum, it) => sum + Number(it.qty || 0), 0);
            const totalSubtotal = items.reduce((sum, it) => sum + Number(it.subtotal || 0), 0);
            txModalBody.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;">
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Invoice</div><div style="font-weight:700;">${escHtml(tx.invoice_no || '-')}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Status</div><div style="font-weight:700;">${tx.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar'}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Pelanggan</div><div style="font-weight:700;">${escHtml(tx.customer_name || 'Pelanggan Umum')}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Waktu</div><div style="font-weight:700;">${escHtml(tx.transaction_at || '-')}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Diskon</div><div style="font-weight:700;">${formatRupiah(tx.discount || 0)}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Uang Muka</div><div style="font-weight:700;">${formatRupiah(tx.downpayment || 0)}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Metode Bayar</div><div style="font-weight:700;">${tx.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai'}</div></div>
            <div style="border:1px solid var(--border-color);border-radius:10px;padding:10px;background:#f8fafc;"><div style="font-size:11px;color:var(--text-muted);">Keterangan</div><div style="font-weight:700;">${escHtml(tx.payment_note || '-')}</div></div>
        </div>
        <table class="tx-table">
            <thead><tr><th>Produk</th><th>Varian</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
            <tbody>
                ${items.map(it => `
                    <tr>
                        <td>${escHtml(it.product_name || '-')}</td>
                        <td>${escHtml(it.variant_name || '-')}</td>
                        <td>${formatRupiah(it.price)}</td>
                        <td style="text-align:center;">${Number(it.qty || 0)}</td>
                        <td>${formatRupiah(it.subtotal)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        <div style="display:flex;justify-content:flex-end;gap:20px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid var(--border-color);font-weight:700;">
            <div>Total Qty: ${totalQty}</div>
            <div>Total Subtotal: ${formatRupiah(totalSubtotal)}</div>
        </div>
        ${buildGroupTagPanel(tx)}
        ${buildPurchaseTagPanel(tx)}
    `;
            txModal.classList.add('active');
        };

        // Panel untuk memindahkan transaksi ini ke label bebas.
        function buildGroupTagPanel(tx) {
            const currentId = tx.group_id ? String(tx.group_id) : '';
            if (!transactionGroups.length) {
                return `
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);font-size:12px;color:var(--text-muted);">
            Belum ada label transaksi. Tutup jendela ini lalu klik <strong>Buat Label</strong> untuk membuatnya.
        </div>`;
            }
            const opts = ['<option value="" ' + (currentId === '' ? 'selected' : '') + '>&mdash; Tanpa Label &mdash;</option>']
                .concat(transactionGroups.map((g) => {
                    const value = String(g.id);
                    const label = g.group_note ? `${g.group_name} • ${g.group_note}` : g.group_name;
                    return `<option value="${value}" ${currentId === value ? 'selected' : ''}>${escHtml(label)}</option>`;
                }))
                .join('');
            return `
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Label Transaksi</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <select id="txDetailGroup" class="tx-select" style="flex:1;min-width:240px;">${opts}</select>
                <button class="btn-link btn-detail" type="button" onclick="saveTxGroup()">Simpan</button>
            </div>
        </div>`;
        }

        window.saveTxGroup = async function() {
            const select = document.getElementById('txDetailGroup');
            if (!select || !activeDetailId) return;
            const txId = activeDetailId;
            const ok = await assignTransactionsToGroup([txId], select.value || '');
            if (ok) closeTxModal();
        };

        // Panel opsional untuk menandai transaksi ini berasal dari pembelian (PO) mana.
        function buildPurchaseTagPanel(tx) {
            if (!purchaseOptions.length) {
                return `
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);font-size:12px;color:var(--text-muted);">
            Belum ada data pembelian. Buat PO di menu <a href="purchases.php" style="color:#1D4ED8;font-weight:600;">Pembelian</a> untuk bisa mengelompokkan transaksi.
        </div>`;
            }
            const currentId = tx.purchase_id ? String(tx.purchase_id) : '';
            const opts = [`<option value="" ${currentId === '' ? 'selected' : ''}>— Tanpa Pembelian —</option>`]
                .concat(purchaseOptions.map((po) => {
                    const value = String(po.id);
                    const label = `${po.invoice_no} • ${po.supplier_name || 'Tanpa Supplier'}${po.invoice_date ? ' • ' + formatDateId(po.invoice_date) : ''}`;
                    return `<option value="${value}" ${currentId === value ? 'selected' : ''}>${escHtml(label)}</option>`;
                }))
                .join('');
            return `
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-color);">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Sumber Pembelian (opsional)</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <select id="txDetailPurchase" class="tx-select" style="flex:1;min-width:240px;">${opts}</select>
                <button class="btn-link btn-detail" type="button" onclick="saveTxPurchase()">Simpan</button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">Dipakai untuk menampilkan daftar transaksi per pembelian / per supplier. Untuk menandai banyak transaksi sekaligus, centang barisnya di daftar lalu pakai tombol <strong>Asal Pembelian</strong>.</div>
        </div>`;
        }

        window.saveTxPurchase = async function() {
            const select = document.getElementById('txDetailPurchase');
            if (!select || !activeDetailId) return;
            try {
                const res = await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_purchase',
                        transaction_id: activeDetailId,
                        purchase_id: select.value || '',
                    }),
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.message || 'Gagal menyimpan sumber pembelian', 'error');
                    return;
                }
                showToast(data.message || 'Sumber pembelian tersimpan', 'success');
                closeTxModal();
                await loadTransactions();
            } catch (err) {
                showToast('Gagal menyimpan sumber pembelian', 'error');
            }
        };
        window.closeTxModal = function() {
            activeDetailId = 0;
            txModal.classList.remove('active');
        };

        window.openCancelPaidModal = function(id) {
            activeCancelPaidId = Number(id || 0);
            if (!activeCancelPaidId) return;
            cancelPaidPassword.value = '';
            setCancelValidation('');
            cancelPaidModal.classList.add('active');
            setTimeout(() => cancelPaidPassword.focus(), 60);
        };

        window.closeCancelPaidModal = function() {
            activeCancelPaidId = 0;
            cancelPaidPassword.value = '';
            setCancelValidation('');
            cancelPaidModal.classList.remove('active');
        };

        function buildPrintableNote(tx, items) {
            const itemRows = buildItemsRows(items);
            const storeName = receiptTemplate.store_name || 'Toko POS Kita';
            const storeAddress = receiptTemplate.store_address || '';
            const storePhone = receiptTemplate.store_phone || '';
            const bankAccount = receiptTemplate.bank_account || '';
            const footerText = receiptTemplate.footer_text || 'Terima kasih sudah berbelanja';
            const statusText = tx.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar';
            if (printTemplates.nota && String(printTemplates.nota).trim() !== '') {
                const logoUrl = receiptTemplate.logo_url || '';
                const logoImg = logoUrl ? `<img src="${escHtml(logoUrl)}" alt="Logo" style="max-width:120px;max-height:60px;object-fit:contain;">` : '';
                const subtotal = Number(tx.subtotal || 0);
                const discountVal = Number(tx.discount || 0);
                const dpVal = Number(tx.downpayment || 0);
                const sisaBayar = Math.max(0, subtotal - discountVal - dpVal);
                const paymentMethodLabel = tx.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai';
                return applyPrintTemplate(printTemplates.nota, {
                    store_name: escHtml(storeName),
                    store_address: escHtml(storeAddress),
                    store_phone: escHtml(storePhone),
                    bank_account: escHtml(bankAccount),
                    logo_url: escHtml(logoUrl),
                    logo_img: logoImg,
                    invoice_no: escHtml(tx.invoice_no || '-'),
                    transaction_at: escHtml(tx.transaction_at || '-'),
                    customer_name: escHtml(tx.customer_name || 'Pelanggan Umum'),
                    items_rows: itemRows,
                    // total should be the subtotal (sum of item subtotals) before discount and downpayment
                    total: escHtml(formatRupiah(subtotal)),
                    discount: escHtml(formatRupiah(tx.discount || 0)),
                    downpayment: escHtml(formatRupiah(tx.downpayment || 0)),
                    sisa_bayar: escHtml(formatRupiah(sisaBayar)),
                    paid_amount: escHtml(formatRupiah(tx.paid_amount || 0)),
                    change_amount: escHtml(formatRupiah(tx.change_amount || 0)),
                    payment_method: escHtml(paymentMethodLabel),
                    payment_note: escHtml(tx.payment_note || ''),
                    status: escHtml(statusText),
                    footer_text: escHtml(footerText),
                });
            }

            const headerAlign = receiptTemplate.header_align || 'center';
            const showLogo = String(receiptTemplate.show_logo || '0') === '1';
            const showStoreInfo = String(receiptTemplate.show_store_info || '1') === '1';
            const showItemCode = String(receiptTemplate.show_item_code || '0') === '1';
            const thermalWidth = Number(receiptTemplate.paper_width_mm || 58) === 80 ? '76mm' : '58mm';
            const fontSize = Number(receiptTemplate.font_size_px || 12);
            const logoUrl = receiptTemplate.logo_url || '';
            const headerText = receiptTemplate.header_text || '';
            /* <span>${escHtml(it.product_name)}${it.variant_name ? ` (${escHtml(it.variant_name)})` : ''}${showItemCode ? `<div style="font-size:10px;color:#666;">${escHtml(it.product_code || '')}</div>` : ''}</span> */
            const thermalItemRows = items.map((it) => `
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:12px;">
             <span>${escHtml(it.product_name)}${showItemCode ? `<div style="font-size:10px;color:#666;">${escHtml(it.product_code || '')}</div>` : ''}</span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:11px;color:#555;">
            <span>${Number(it.qty)} x ${formatRupiah(it.price)}</span>
            <span>${formatRupiah(it.subtotal)}</span>
        </div>
    `).join('');
            return `
        <div style="width:${thermalWidth};margin:0 auto;font-family:'Courier New',monospace;color:#111;padding:10px;font-size:${fontSize}px;">
            <div style="text-align:${headerAlign};margin-bottom:8px;">
                ${showLogo && logoUrl ? `<img src="${logoUrl}" alt="logo" style="max-width:110px;max-height:50px;object-fit:contain;margin-bottom:4px;">` : ''}
                ${showStoreInfo ? `<h3 style="margin:0;font-size:15px;">${escHtml(storeName)}</h3>` : ''}
                ${showStoreInfo && storeAddress ? `<div style="font-size:11px;color:#555;">${escHtml(storeAddress)}</div>` : ''}
                ${showStoreInfo && storePhone ? `<div style="font-size:11px;color:#555;">${escHtml(storePhone)}</div>` : ''}
                ${showStoreInfo && bankAccount ? `<div style="font-size:11px;color:#555;">Rek: ${escHtml(bankAccount)}</div>` : ''}
                ${headerText ? `<div style="font-size:11px;color:#555;">${escHtml(headerText)}</div>` : ''}
                <div style="font-size:11px;color:#555;">${escHtml(tx.invoice_no || '-')}</div>
                <div style="font-size:11px;color:#555;">${escHtml(tx.transaction_at || '-')}</div>
            </div>
            <div style="font-size:11px;margin-bottom:8px;">Pelanggan: ${escHtml(tx.customer_name || 'Pelanggan Umum')}</div>
            <div style="border-bottom:1px dashed #000;margin:8px 0;"></div>
            ${thermalItemRows}
            <div style="border-bottom:1px dashed #000;margin:8px 0;"></div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Diskon</span><span>${formatRupiah(tx.discount || 0)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Uang Muka</span><span>${formatRupiah(tx.downpayment || 0)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Total</span><span>${formatRupiah(Number(tx.subtotal || 0))}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Sisa Bayar</span><span>${formatRupiah(Math.max(0, Number(tx.subtotal || 0) - Number(tx.discount || 0) - Number(tx.downpayment || 0)))}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>${tx.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai'}</span><span>${formatRupiah(tx.paid_amount)}</span>
            </div>
            ${tx.payment_note ? `<div style="font-size:11px;color:#555;">Ket: ${escHtml(tx.payment_note)}</div>` : ''}
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Kembalian</span><span>${formatRupiah(tx.change_amount)}</span>
            </div>
            <div style="margin-top:8px;font-size:11px;">Status: ${tx.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar'}</div>
            <div style="text-align:center;margin-top:8px;font-size:11px;color:#4b5563;">${escHtml(footerText)}</div>
        </div>
    `;
        }

        function buildPrintableKwitansi(tx, items) {
            const itemRows = buildItemsRows(items);
            const storeName = receiptTemplate.store_name || 'Toko POS Kita';
            const storeAddress = receiptTemplate.store_address || '';
            const storePhone = receiptTemplate.store_phone || '';
            const bankAccount = receiptTemplate.bank_account || '';
            const footerText = receiptTemplate.footer_text || 'Terima kasih sudah berbelanja';
            const statusText = tx.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar';

            if (printTemplates.kwitansi && String(printTemplates.kwitansi).trim() !== '') {
                const logoUrl = receiptTemplate.logo_url || '';
                const logoImg = logoUrl ? `<img src="${escHtml(logoUrl)}" alt="Logo" style="max-width:120px;max-height:60px;object-fit:contain;">` : '';
                return applyPrintTemplate(printTemplates.kwitansi, {
                    store_name: escHtml(storeName),
                    store_address: escHtml(storeAddress),
                    store_phone: escHtml(storePhone),
                    bank_account: escHtml(bankAccount),
                    logo_url: escHtml(logoUrl),
                    logo_img: logoImg,
                    invoice_no: escHtml(tx.invoice_no || '-'),
                    transaction_at: escHtml(tx.transaction_at || '-'),
                    customer_name: escHtml(tx.customer_name || 'Pelanggan Umum'),
                    items_rows: itemRows,
                    total: escHtml(formatRupiah(tx.total || 0)),
                    discount: escHtml(formatRupiah(tx.discount || 0)),
                    downpayment: escHtml(formatRupiah(tx.downpayment || 0)),
                    paid_amount: escHtml(formatRupiah(tx.paid_amount || 0)),
                    change_amount: escHtml(formatRupiah(tx.change_amount || 0)),
                    payment_method: escHtml(tx.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai'),
                    payment_note: escHtml(tx.payment_note || ''),
                    status: escHtml(statusText),
                    footer_text: escHtml(footerText),
                });
            }

            return `
        <div style="font-family:Arial,sans-serif;font-size:13px;color:#222;max-width:700px;margin:0 auto;border:1px solid #ddd;padding:16px;">
            <h2 style="text-align:center;margin:0 0 14px;">KWITANSI</h2>
            <table style="width:100%;margin-bottom:12px;">
                <tr><td style="width:140px;">No Kwitansi</td><td>: ${escHtml(tx.invoice_no || '-')}</td></tr>
                <tr><td>Tanggal</td><td>: ${escHtml(tx.transaction_at || '-')}</td></tr>
                <tr><td>Sudah terima dari</td><td>: ${escHtml(tx.customer_name || 'Pelanggan Umum')}</td></tr>
            </table>
            <div style="border:1px dashed #aaa;padding:10px;margin-bottom:12px;">${itemRows}</div>
            <table style="width:100%;">
                <tr><td style="width:140px;">Total</td><td>: ${formatRupiah(tx.total || 0)}</td></tr>
                <tr><td>Diskon</td><td>: ${formatRupiah(tx.discount || 0)}</td></tr>
                <tr><td>Uang Muka</td><td>: ${formatRupiah(tx.downpayment || 0)}</td></tr>
                <tr><td>Metode Bayar</td><td>: ${escHtml(tx.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai')}</td></tr>
                <tr><td>Keterangan</td><td>: ${escHtml(tx.payment_note || '-')}</td></tr>
                <tr><td>Status</td><td>: ${statusText}</td></tr>
                <tr><td>Rekening</td><td>: ${escHtml(bankAccount || '-')}</td></tr>
            </table>
            <div style="text-align:center;margin-top:12px;color:#6b7280;">${escHtml(footerText)}</div>
        </div>
    `;
        }

        function formatDateId(ymd) {
            const parts = String(ymd || '').split('-');
            if (parts.length !== 3) return String(ymd || '-');
            return `${parts[2]}-${parts[1]}-${parts[0]}`;
        }

        function getListFilterMeta() {
            const start = (txStartDate.value || '').trim();
            const end = (txEndDate.value || '').trim();
            let period = 'Semua tanggal';
            if (start && end) {
                period = start === end ? formatDateId(start) : `${formatDateId(start)} s/d ${formatDateId(end)}`;
            } else if (start) {
                period = `Mulai ${formatDateId(start)}`;
            } else if (end) {
                period = `Sampai ${formatDateId(end)}`;
            }
            const statusLabels = {
                all: 'Semua Status',
                pending: 'Belum Bayar',
                paid: 'Sudah Bayar'
            };
            const groupBy = currentGroupBy();
            const groupValue = currentGroupFilter();
            let groupFilterLabel = 'Semua Label';
            if (groupValue === 'none') {
                groupFilterLabel = 'Tanpa Label';
            } else if (groupValue !== '' && txGroup) {
                groupFilterLabel = txGroup.options[txGroup.selectedIndex].text;
            }
            const purchaseValue = txPurchase ? (txPurchase.value || '') : '';
            let purchaseLabel = 'Semua Pembelian';
            if (purchaseValue === 'none') {
                purchaseLabel = 'Tanpa Pembelian';
            } else if (purchaseValue !== '' && txPurchase) {
                purchaseLabel = txPurchase.options[txPurchase.selectedIndex].text;
            }
            return {
                period,
                status: statusLabels[txStatus.value] || 'Semua Status',
                search: (txSearch.value || '').trim(),
                purchase: purchaseLabel,
                groupFilter: groupFilterLabel,
                groupBy,
                groupLabel: {
                    group: 'Per Label',
                    supplier: 'Per Supplier',
                    purchase: 'Per Pembelian',
                    date: 'Per Tanggal',
                }[groupBy] || 'Datar',
            };
        }

        async function fetchAllFilteredTransactions() {
            const collected = [];
            let current = 1;
            let pages = 1;
            do {
                const params = new URLSearchParams({
                    action: 'list_today',
                    page: String(current),
                    page_size: '100',
                    start_date: (txStartDate.value || '').trim(),
                    end_date: (txEndDate.value || '').trim(),
                    status: txStatus.value || 'all',
                    purchase_id: txPurchase ? (txPurchase.value || '') : '',
                    group_id: currentGroupFilter(),
                    group_by: currentGroupBy(),
                    search: (txSearch.value || '').trim(),
                });
                const res = await fetch(`checkout_actions.php?${params.toString()}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal memuat data transaksi');
                collected.push(...(data.transactions || []));
                pages = Number((data.pagination || {}).total_pages || 1);
                current += 1;
            } while (current <= pages);
            return collected;
        }

        function buildPrintableList(rows, meta) {
            const storeName = receiptTemplate.store_name || 'Toko POS Kita';
            const storeAddress = receiptTemplate.store_address || '';
            const storePhone = receiptTemplate.store_phone || '';
            const bankAccount = receiptTemplate.bank_account || '';
            const logoUrl = receiptTemplate.logo_url || '';
            const footerText = receiptTemplate.footer_text || 'Terima kasih sudah berbelanja';
            const logoImg = logoUrl ? `<img src="${escHtml(logoUrl)}" alt="Logo" style="max-width:120px;max-height:60px;object-fit:contain;">` : '';

            let grandTotal = 0;
            let grandDiscount = 0;
            let grandDownpayment = 0;
            let grandSisa = 0;
            let grandPaid = 0;
            const printColumns = 12;

            const cell = 'border:1px solid #999;padding:4px;';
            let counter = 0;
            const buildPrintRow = (row) => {
                const subtotal = Number(row.subtotal || 0);
                const discountVal = Number(row.discount || 0);
                const dpVal = Number(row.downpayment || 0);
                const sisaBayar = Math.max(0, subtotal - discountVal - dpVal);
                grandTotal += subtotal;
                grandDiscount += discountVal;
                grandDownpayment += dpVal;
                grandSisa += sisaBayar;
                grandPaid += Number(row.paid_amount || 0);
                counter += 1;
                const purchaseText = row.purchase_id
                    ? `${row.purchase_invoice_no || 'PO #' + row.purchase_id}${row.supplier_name ? ' (' + row.supplier_name + ')' : ''}`
                    : '-';
                return `
        <tr>
            <td style="${cell}text-align:center;">${counter}</td>
            <td style="${cell}">${escHtml(row.invoice_no || '-')}</td>
            <td style="${cell}">${escHtml(row.customer_name || 'Pelanggan Umum')}</td>
            <td style="${cell}">${escHtml(row.group_name || '-')}</td>
            <td style="${cell}">${escHtml(purchaseText)}</td>
            <td style="${cell}">${escHtml(row.transaction_at || '-')}</td>
            <td style="${cell}text-align:right;">${formatRupiah(subtotal)}</td>
            <td style="${cell}text-align:right;">${formatRupiah(discountVal)}</td>
            <td style="${cell}text-align:right;">${formatRupiah(dpVal)}</td>
            <td style="${cell}text-align:right;">${formatRupiah(sisaBayar)}</td>
            <td style="${cell}">${row.payment_method === 'transfer' ? 'Transfer Rek' : 'Tunai'}</td>
            <td style="${cell}">${row.status === 'paid' ? 'Sudah Bayar' : 'Belum Bayar'}</td>
        </tr>`;
            };

            const rowsHtml = meta.groupBy
                ? buildGroups(rows, meta.groupBy).map((group) => `
        <tr>
            <td style="${cell}background:#eef2ff;font-weight:bold;" colspan="${printColumns}">
                ${escHtml(group.label)} — ${group.rows.length} transaksi &bull; ${formatRupiah(groupTotalOf(group.rows))}
            </td>
        </tr>${group.rows.map(buildPrintRow).join('')}`).join('')
                : rows.map(buildPrintRow).join('');

            const printedAt = new Date().toLocaleString('id-ID');

            if (printTemplates.laporan_transaksi && String(printTemplates.laporan_transaksi).trim() !== '') {
                return applyPrintTemplate(printTemplates.laporan_transaksi, {
                    store_name: escHtml(storeName),
                    store_address: escHtml(storeAddress),
                    store_phone: escHtml(storePhone),
                    bank_account: escHtml(bankAccount),
                    logo_url: escHtml(logoUrl),
                    logo_img: logoImg,
                    report_title: 'DAFTAR TRANSAKSI',
                    period: escHtml(meta.period),
                    filter_status: escHtml(meta.status),
                    filter_search: escHtml(meta.search || '-'),
                    filter_purchase: escHtml(meta.purchase || 'Semua Pembelian'),
                    filter_group: escHtml(meta.groupFilter || 'Semua Label'),
                    group_label: escHtml(meta.groupLabel || 'Datar'),
                    print_date: escHtml(printedAt),
                    rows: rowsHtml,
                    total_transaksi: String(rows.length),
                    grand_total: escHtml(formatRupiah(grandTotal)),
                    grand_discount: escHtml(formatRupiah(grandDiscount)),
                    grand_downpayment: escHtml(formatRupiah(grandDownpayment)),
                    grand_sisa: escHtml(formatRupiah(grandSisa)),
                    grand_paid: escHtml(formatRupiah(grandPaid)),
                    footer_text: escHtml(footerText),
                });
            }

            const headCell = 'border:1px solid #999;padding:5px;';
            return `
        <div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;padding:12px;">
            <div style="text-align:center;margin-bottom:10px;">
                <div style="font-size:16px;font-weight:bold;">${escHtml(storeName)}</div>
                ${storeAddress ? `<div>${escHtml(storeAddress)}</div>` : ''}
                ${storePhone ? `<div>${escHtml(storePhone)}</div>` : ''}
            </div>
            <div style="text-align:center;font-weight:bold;font-size:14px;margin-bottom:4px;">DAFTAR TRANSAKSI</div>
            <div style="text-align:center;margin-bottom:10px;">Periode: ${escHtml(meta.period)} &bull; Status: ${escHtml(meta.status)}</div>
            <div style="text-align:center;margin-bottom:10px;">Pembelian: ${escHtml(meta.purchase || 'Semua Pembelian')} &bull; Filter Label: ${escHtml(meta.groupFilter || 'Semua Label')} &bull; Tampilan: ${escHtml(meta.groupLabel || 'Datar')}</div>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="${headCell}text-align:center;">No</th>
                        <th style="${headCell}text-align:left;">Invoice</th>
                        <th style="${headCell}text-align:left;">Pelanggan</th>
                        <th style="${headCell}text-align:left;">Label</th>
                        <th style="${headCell}text-align:left;">Pembelian</th>
                        <th style="${headCell}text-align:left;">Waktu</th>
                        <th style="${headCell}text-align:right;">Total</th>
                        <th style="${headCell}text-align:right;">Diskon</th>
                        <th style="${headCell}text-align:right;">Uang Muka</th>
                        <th style="${headCell}text-align:right;">Sisa Bayar</th>
                        <th style="${headCell}text-align:left;">Metode</th>
                        <th style="${headCell}text-align:left;">Status</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
                <tfoot>
                    <tr style="font-weight:bold;background:#f1f5f9;">
                        <td style="${headCell}" colspan="6">Total ${rows.length} transaksi</td>
                        <td style="${headCell}text-align:right;">${formatRupiah(grandTotal)}</td>
                        <td style="${headCell}text-align:right;">${formatRupiah(grandDiscount)}</td>
                        <td style="${headCell}text-align:right;">${formatRupiah(grandDownpayment)}</td>
                        <td style="${headCell}text-align:right;">${formatRupiah(grandSisa)}</td>
                        <td style="${headCell}" colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            <div style="margin-top:10px;display:flex;justify-content:space-between;">
                <span>${escHtml(footerText)}</span>
                <span>Dicetak: ${escHtml(printedAt)}</span>
            </div>
        </div>
    `;
        }

        async function waitForPrintAssets(container) {
            if (!container) return;
            const imgs = Array.from(container.querySelectorAll('img'));
            if (!imgs.length) return;

            await Promise.all(
                imgs.map((img) => new Promise((resolve) => {
                    if (img.complete) {
                        resolve();
                        return;
                    }
                    img.addEventListener('load', () => resolve(), { once: true });
                    img.addEventListener('error', () => resolve(), { once: true });
                }))
            );

            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        }

        window.printTxNota = async function(id) {
            try {
                const res = await fetch(`checkout_actions.php?action=detail&id=${id}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal ambil detail transaksi');
                const tx = data.transaction || {};
                const items = data.items || [];
                printArea.innerHTML = buildPrintableNote(tx, items);
                await waitForPrintAssets(printArea);
                window.print();
                await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'mark_printed',
                        transaction_id: id
                    }),
                });
                await loadTransactions();
            } catch (err) {
                showToast(err.message || 'Gagal mencetak nota', 'error');
            }
        };
        window.printTxKwitansi = async function(id) {
            try {
                const res = await fetch(`checkout_actions.php?action=detail&id=${id}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal ambil detail transaksi');
                const tx = data.transaction || {};
                const items = data.items || [];
                printArea.innerHTML = buildPrintableKwitansi(tx, items);
                await waitForPrintAssets(printArea);
                window.print();
                await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'mark_printed',
                        transaction_id: id
                    }),
                });
                await loadTransactions();
            } catch (err) {
                showToast(err.message || 'Gagal mencetak kwitansi', 'error');
            }
        };
        window.printTxList = async function() {
            const original = btnPrintList.innerHTML;
            btnPrintList.disabled = true;
            btnPrintList.textContent = 'Menyiapkan...';
            try {
                const rows = await fetchAllFilteredTransactions();
                if (!rows.length) {
                    showToast('Tidak ada transaksi pada filter ini.', 'error');
                    return;
                }
                printArea.innerHTML = buildPrintableList(rows, getListFilterMeta());
                await waitForPrintAssets(printArea);
                window.print();
            } catch (err) {
                showToast(err.message || 'Gagal mencetak daftar transaksi', 'error');
            } finally {
                btnPrintList.disabled = false;
                btnPrintList.innerHTML = original;
                if (window.feather) feather.replace();
            }
        };
        window.deleteTx = async function(id) {
            const proceed = await askConfirm(
                'Hapus Transaksi',
                'Hapus transaksi ini? Hanya boleh untuk transaksi belum bayar dan belum cetak.',
                'Hapus Transaksi'
            );
            if (!proceed) return;
            try {
                const res = await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'delete_pending',
                        transaction_id: id
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Gagal menghapus transaksi');
                await loadTransactions();
                showToast('Transaksi berhasil dihapus.', 'success');
            } catch (err) {
                showToast(err.message, 'error');
            }
        };
        async function submitCancelPaid() {
            if (!activeCancelPaidId) return;
            const password = (cancelPaidPassword.value || '').trim();
            if (!password) {
                setCancelValidation('Password login wajib diisi.');
                return;
            }
            const original = confirmCancelPaidBtn.textContent;
            confirmCancelPaidBtn.disabled = true;
            confirmCancelPaidBtn.textContent = 'Memproses...';
            try {
                const res = await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'cancel_paid',
                        transaction_id: activeCancelPaidId,
                        password
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Gagal membatalkan status bayar');
                closeCancelPaidModal();
                await loadTransactions();
                showToast('Status transaksi berhasil diubah menjadi belum bayar.', 'success');
            } catch (err) {
                setCancelValidation(err.message || 'Gagal membatalkan pembayaran.');
            } finally {
                confirmCancelPaidBtn.disabled = false;
                confirmCancelPaidBtn.textContent = original;
            }
        }
        function reloadFromFirstPage() {
            page = 1;
            loadTransactions();
        }

        // ============================================================
        // Label transaksi: drag & drop, CRUD label, papan dua panel
        // ============================================================

        // Baris yang ikut terbawa saat drag: kalau baris sumber ada di dalam
        // pilihan centang, seluruh pilihan ikut; kalau tidak, hanya baris itu.
        function dragIdsFromRow(txId) {
            const id = Number(txId || 0);
            if (!id) return [];
            if (selectedTxIds.size > 1 && selectedTxIds.has(id)) {
                return Array.from(selectedTxIds);
            }
            return [id];
        }

        function markDropHover(el, on) {
            if (el) el.classList.toggle('drop-hover', !!on);
        }

        // ---- Sumber drag: baris tabel transaksi ----
        txTbody.addEventListener('dragstart', (e) => {
            const row = e.target.closest('tr[data-tx-id]');
            if (!row) return;
            dragPayloadIds = dragIdsFromRow(row.dataset.txId);
            if (!dragPayloadIds.length) return;
            row.classList.add('tx-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try {
                e.dataTransfer.setData('text/plain', JSON.stringify(dragPayloadIds));
            } catch (err) { /* sebagian browser lama menolak setData non-teks */ }
        });

        txTbody.addEventListener('dragend', (e) => {
            const row = e.target.closest('tr[data-tx-id]');
            if (row) row.classList.remove('tx-dragging');
            document.querySelectorAll('.drop-hover').forEach((el) => el.classList.remove('drop-hover'));
            dragPayloadIds = [];
        });

        // ---- Target drop: kotak label di dock ----
        if (txGroupChips) {
            txGroupChips.addEventListener('dragover', (e) => {
                const chip = e.target.closest('.tx-chip');
                if (!chip || !dragPayloadIds.length) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                markDropHover(chip, true);
            });
            txGroupChips.addEventListener('dragleave', (e) => {
                const chip = e.target.closest('.tx-chip');
                if (chip && !chip.contains(e.relatedTarget)) markDropHover(chip, false);
            });
            txGroupChips.addEventListener('drop', async (e) => {
                const chip = e.target.closest('.tx-chip');
                if (!chip) return;
                e.preventDefault();
                markDropHover(chip, false);
                const ids = dragPayloadIds.slice();
                dragPayloadIds = [];
                if (!ids.length) return;
                await assignTransactionsToGroup(ids, chip.dataset.groupId || '');
            });
            // Klik kotak = filter cepat ke label tersebut.
            txGroupChips.addEventListener('click', (e) => {
                const chip = e.target.closest('.tx-chip');
                if (!chip || !txGroup) return;
                const value = chip.dataset.groupId === '' ? 'none' : chip.dataset.groupId;
                txGroup.value = txGroup.value === value ? '' : value;
                reloadFromFirstPage();
            });
        }

        // ---- Target drop: baris judul grup saat mode "Tampilkan per: Label" ----
        txTbody.addEventListener('dragover', (e) => {
            const head = e.target.closest('tr.tx-group-row[data-drop-group]');
            if (!head || !dragPayloadIds.length) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            markDropHover(head, true);
        });
        txTbody.addEventListener('dragleave', (e) => {
            const head = e.target.closest('tr.tx-group-row[data-drop-group]');
            if (head && !head.contains(e.relatedTarget)) markDropHover(head, false);
        });
        txTbody.addEventListener('drop', async (e) => {
            const head = e.target.closest('tr.tx-group-row[data-drop-group]');
            if (!head) return;
            e.preventDefault();
            markDropHover(head, false);
            const ids = dragPayloadIds.slice();
            dragPayloadIds = [];
            if (!ids.length) return;
            await assignTransactionsToGroup(ids, head.dataset.dropGroup || '');
        });

        // ---- Pilih baris lewat centang ----
        txTbody.addEventListener('change', (e) => {
            const box = e.target.closest('.tx-row-check');
            if (!box) return;
            const id = Number(box.dataset.txId || 0);
            if (!id) return;
            if (box.checked) selectedTxIds.add(id);
            else selectedTxIds.delete(id);
            const row = box.closest('tr');
            if (row) row.classList.toggle('tx-row-selected', box.checked);
            updateBulkBar();
        });

        if (txCheckAll) {
            txCheckAll.addEventListener('change', () => {
                const check = txCheckAll.checked;
                document.querySelectorAll('.tx-row-check').forEach((box) => {
                    box.checked = check;
                    const id = Number(box.dataset.txId || 0);
                    if (!id) return;
                    if (check) selectedTxIds.add(id);
                    else selectedTxIds.delete(id);
                    const row = box.closest('tr');
                    if (row) row.classList.toggle('tx-row-selected', check);
                });
                updateBulkBar();
            });
        }

        // ---- Aksi massal ----
        if (btnBulkAssign) {
            btnBulkAssign.addEventListener('click', async () => {
                const target = txBulkGroup ? (txBulkGroup.value || '') : '';
                if (!target) {
                    showToast('Pilih label tujuan terlebih dahulu.', 'error');
                    return;
                }
                await assignTransactionsToGroup(Array.from(selectedTxIds), target);
            });
        }
        if (btnBulkRemove) {
            btnBulkRemove.addEventListener('click', async () => {
                await assignTransactionsToGroup(Array.from(selectedTxIds), '');
            });
        }
        if (btnBulkClear) {
            btnBulkClear.addEventListener('click', () => clearSelection());
        }

        // Tandai asal pembelian untuk seluruh baris yang dicentang.
        // Sebelumnya tag ini hanya bisa diisi satu per satu dari modal Detail.
        async function assignTransactionsToPurchase(ids, purchaseId) {
            const payloadIds = (ids || []).map(Number).filter((v) => v > 0);
            if (!payloadIds.length) {
                showToast('Pilih minimal satu transaksi.', 'error');
                return false;
            }
            try {
                const res = await fetch('checkout_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'assign_purchase',
                        transaction_ids: payloadIds,
                        purchase_id: (purchaseId === '' || purchaseId === null || purchaseId === undefined)
                            ? ''
                            : Number(purchaseId),
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'Gagal menandai asal pembelian');
                showToast(data.message || 'Asal pembelian tersimpan.', 'success');
                clearSelection();
                await loadTransactions();
                return true;
            } catch (err) {
                showToast(err.message || 'Gagal menandai asal pembelian', 'error');
                return false;
            }
        }

        if (btnBulkPurchase) {
            btnBulkPurchase.addEventListener('click', async () => {
                const target = txBulkPurchase ? (txBulkPurchase.value || '') : '';
                if (!target) {
                    showToast('Pilih pembelian tujuan terlebih dahulu.', 'error');
                    return;
                }
                await assignTransactionsToPurchase(Array.from(selectedTxIds), target);
            });
        }
        if (btnBulkPurchaseClear) {
            btnBulkPurchaseClear.addEventListener('click', async () => {
                await assignTransactionsToPurchase(Array.from(selectedTxIds), '');
            });
        }

        // ---- Modal konfirmasi: pengganti window.confirm() bawaan browser ----
        let confirmResolver = null;

        function askConfirm(title, message, okText = 'Hapus') {
            return new Promise((resolve) => {
                confirmResolver = resolve;
                confirmModalTitle.textContent = title;
                confirmModalMessage.textContent = message;
                confirmModalOkBtn.textContent = okText;
                confirmModal.classList.add('active');
                setTimeout(() => confirmModalOkBtn.focus(), 60);
            });
        }

        window.resolveConfirm = function(value) {
            confirmModal.classList.remove('active');
            const resolver = confirmResolver;
            confirmResolver = null;
            if (resolver) resolver(!!value);
        };

        if (confirmModal) {
            confirmModal.addEventListener('click', (e) => {
                if (e.target === confirmModal) window.resolveConfirm(false);
            });
        }

        // ---- Modal buat label: pengganti prompt() bawaan browser ----
        function setNewLabelValidation(message = '') {
            newLabelValidation.textContent = message;
            newLabelValidation.style.display = message ? 'block' : 'none';
        }

        window.openNewLabelModal = function() {
            newLabelNameInput.value = '';
            newLabelNoteInput.value = '';
            setNewLabelValidation('');
            const picked = selectedTxIds.size;
            newLabelHint.textContent = picked
                ? `${picked} transaksi yang dicentang akan langsung dimasukkan ke label ini.`
                : 'Setelah dibuat, label langsung muncul sebagai kotak di atas daftar dan siap menerima tarikan baris transaksi.';
            newLabelModal.classList.add('active');
            setTimeout(() => newLabelNameInput.focus(), 60);
        };

        window.closeNewLabelModal = function() {
            newLabelModal.classList.remove('active');
            setNewLabelValidation('');
        };

        async function submitNewLabel() {
            const name = (newLabelNameInput.value || '').trim();
            if (!name) {
                setNewLabelValidation('Nama label wajib diisi.');
                newLabelNameInput.focus();
                return;
            }
            const original = submitNewLabelBtn.textContent;
            submitNewLabelBtn.disabled = true;
            submitNewLabelBtn.textContent = 'Menyimpan...';
            try {
                const data = await postGroupAction({
                    action: 'save_group',
                    group_id: 0,
                    group_name: name,
                    group_note: (newLabelNoteInput.value || '').trim(),
                }, 'Gagal membuat label');
                showToast(data.message || 'Label dibuat.', 'success');
                await loadGroups();
                window.closeNewLabelModal();
                // Kalau ada baris tercentang, langsung masukkan ke label baru.
                if (selectedTxIds.size) {
                    await assignTransactionsToGroup(Array.from(selectedTxIds), data.group_id);
                }
            } catch (err) {
                setNewLabelValidation(err.message || 'Gagal membuat label.');
            } finally {
                submitNewLabelBtn.disabled = false;
                submitNewLabelBtn.textContent = original;
            }
        }

        if (submitNewLabelBtn) {
            submitNewLabelBtn.addEventListener('click', submitNewLabel);
        }
        [newLabelNameInput, newLabelNoteInput].forEach((input) => {
            if (!input) return;
            input.addEventListener('input', () => setNewLabelValidation(''));
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitNewLabel();
                }
            });
        });
        if (newLabelModal) {
            newLabelModal.addEventListener('click', (e) => {
                if (e.target === newLabelModal) window.closeNewLabelModal();
            });
        }

        // Esc menutup modal label / konfirmasi yang sedang terbuka.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if (confirmModal.classList.contains('active')) {
                window.resolveConfirm(false);
                return;
            }
            if (newLabelModal.classList.contains('active')) {
                window.closeNewLabelModal();
            }
        });

        // ---- CRUD label ----
        async function postGroupAction(body, fallbackMessage) {
            const res = await fetch('checkout_actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.message || fallbackMessage);
            return data;
        }

        function renderGroupManageList() {
            if (!groupManageList) return;
            if (!transactionGroups.length) {
                groupManageList.innerHTML = '<div class="tx-group-empty">Belum ada label tersimpan.</div>';
                return;
            }
            groupManageList.innerHTML = transactionGroups.map((g) => `
        <div class="tx-group-manage-row" data-group-id="${Number(g.id)}">
            <input type="text" class="tx-input group-edit-name" style="flex:1;min-width:150px;" value="${escHtml(g.group_name)}">
            <input type="text" class="tx-input group-edit-note" style="flex:1;min-width:150px;" value="${escHtml(g.group_note || '')}" placeholder="Catatan (opsional)">
            <span class="tx-chip-meta" style="min-width:120px;">${Number(g.tx_count || 0)} transaksi &bull; ${formatRupiah(g.tx_total || 0)}</span>
            <button class="btn-link btn-detail group-save-btn" type="button">Simpan</button>
            <button class="btn-link btn-danger-lite group-delete-btn" type="button">Hapus</button>
        </div>`).join('');
        }

        if (groupManageList) {
            groupManageList.addEventListener('click', async (e) => {
                const row = e.target.closest('.tx-group-manage-row');
                if (!row) return;
                const groupId = Number(row.dataset.groupId || 0);
                if (!groupId) return;

                if (e.target.closest('.group-save-btn')) {
                    const name = (row.querySelector('.group-edit-name').value || '').trim();
                    const note = (row.querySelector('.group-edit-note').value || '').trim();
                    try {
                        const data = await postGroupAction({
                            action: 'save_group',
                            group_id: groupId,
                            group_name: name,
                            group_note: note,
                        }, 'Gagal menyimpan label');
                        showToast(data.message || 'Label tersimpan.', 'success');
                        await loadGroups();
                        await loadTransactions();
                        await refreshBoard();
                    } catch (err) {
                        showToast(err.message || 'Gagal menyimpan label', 'error');
                    }
                    return;
                }

                if (e.target.closest('.group-delete-btn')) {
                    const name = (row.querySelector('.group-edit-name').value || '').trim();
                    const confirmed = await askConfirm(
                        'Hapus Label',
                        `Hapus label "${name}"? Transaksi di dalamnya tidak dihapus, hanya dilepas dari label.`,
                        'Hapus Label'
                    );
                    if (!confirmed) return;
                    try {
                        const data = await postGroupAction({
                            action: 'delete_group',
                            group_id: groupId,
                        }, 'Gagal menghapus label');
                        showToast(data.message || 'Label dihapus.', 'success');
                        await loadGroups();
                        await loadTransactions();
                        await refreshBoard();
                    } catch (err) {
                        showToast(err.message || 'Gagal menghapus label', 'error');
                    }
                }
            });
        }

        async function createGroupFromInputs(nameValue, noteValue) {
            const name = (nameValue || '').trim();
            if (!name) {
                showToast('Nama label wajib diisi.', 'error');
                return null;
            }
            try {
                const data = await postGroupAction({
                    action: 'save_group',
                    group_id: 0,
                    group_name: name,
                    group_note: (noteValue || '').trim(),
                }, 'Gagal membuat label');
                showToast(data.message || 'Label dibuat.', 'success');
                await loadGroups();
                return data;
            } catch (err) {
                showToast(err.message || 'Gagal membuat label', 'error');
                return null;
            }
        }

        if (btnCreateGroup) {
            btnCreateGroup.addEventListener('click', async () => {
                const created = await createGroupFromInputs(newGroupName.value, newGroupNote.value);
                if (!created) return;
                newGroupName.value = '';
                newGroupNote.value = '';
                if (boardGroupSelect) {
                    boardGroupSelect.value = String(created.group_id);
                    await refreshBoard();
                }
            });
        }

        if (btnNewGroup) {
            btnNewGroup.addEventListener('click', () => window.openNewLabelModal());
        }

        // ---- Papan dua panel di modal Kelola Label ----
        function boardItemHtml(row) {
            return `
        <div class="tx-board-item" draggable="true" data-tx-id="${Number(row.id)}">
            <div class="tx-board-item-main">
                <div class="tx-board-item-name">${escHtml(row.customer_name || 'Pelanggan Umum')}</div>
                <div class="tx-board-item-sub">${escHtml(row.invoice_no || '-')} &bull; ${escHtml(row.transaction_at || '-')}</div>
            </div>
            <div style="font-weight:700;white-space:nowrap;">${formatRupiah((row.subtotal || 0) - (row.discount || 0))}</div>
        </div>`;
        }

        async function fetchBoardRows(groupFilter) {
            const params = new URLSearchParams({
                action: 'list_today',
                page: '1',
                page_size: '100',
                start_date: '',
                end_date: '',
                status: 'all',
                purchase_id: '',
                group_id: groupFilter,
                group_by: '',
                search: boardSearch ? (boardSearch.value || '').trim() : '',
            });
            const res = await fetch(`checkout_actions.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Gagal memuat transaksi');
            return data.transactions || [];
        }

        async function refreshBoard() {
            if (!groupModal || !groupModal.classList.contains('active')) return;
            const groupId = boardGroupSelect ? (boardGroupSelect.value || '') : '';
            boardAvailableList.innerHTML = '<div class="tx-board-empty">Memuat...</div>';
            boardMembersList.innerHTML = '<div class="tx-board-empty">Memuat...</div>';
            if (boardMembersTitle) {
                const selected = boardGroupSelect && boardGroupSelect.selectedIndex > 0
                    ? boardGroupSelect.options[boardGroupSelect.selectedIndex].text
                    : 'Isi Label';
                boardMembersTitle.textContent = groupId ? `Isi: ${selected}` : 'Isi Label';
            }
            if (!groupId) {
                boardAvailableList.innerHTML = '<div class="tx-board-empty">Pilih label dulu untuk mulai menarik transaksi.</div>';
                boardMembersList.innerHTML = '<div class="tx-board-empty">Belum ada label dipilih.</div>';
                if (boardAvailableCount) boardAvailableCount.textContent = '';
                if (boardMembersCount) boardMembersCount.textContent = '';
                return;
            }
            try {
                const [available, members] = await Promise.all([
                    fetchBoardRows('none'),
                    fetchBoardRows(groupId),
                ]);
                boardAvailableList.innerHTML = available.length
                    ? available.map(boardItemHtml).join('')
                    : '<div class="tx-board-empty">Semua transaksi sudah punya label.</div>';
                boardMembersList.innerHTML = members.length
                    ? members.map(boardItemHtml).join('')
                    : '<div class="tx-board-empty">Tarik transaksi dari panel kiri ke sini.</div>';
                if (boardAvailableCount) boardAvailableCount.textContent = `${available.length} transaksi`;
                if (boardMembersCount) boardMembersCount.textContent = `${members.length} transaksi`;
            } catch (err) {
                boardAvailableList.innerHTML = '<div class="tx-board-empty">Gagal memuat data.</div>';
                boardMembersList.innerHTML = '<div class="tx-board-empty">Gagal memuat data.</div>';
            }
        }

        function wireBoardPane(pane) {
            if (!pane) return;
            pane.addEventListener('dragstart', (e) => {
                const item = e.target.closest('.tx-board-item');
                if (!item) return;
                dragPayloadIds = [Number(item.dataset.txId || 0)].filter((v) => v > 0);
                item.classList.add('tx-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', JSON.stringify(dragPayloadIds));
                } catch (err) { /* diabaikan */ }
            });
            pane.addEventListener('dragend', (e) => {
                const item = e.target.closest('.tx-board-item');
                if (item) item.classList.remove('tx-dragging');
                markDropHover(pane, false);
                dragPayloadIds = [];
            });
            pane.addEventListener('dragover', (e) => {
                if (!dragPayloadIds.length) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                markDropHover(pane, true);
            });
            pane.addEventListener('dragleave', (e) => {
                if (!pane.contains(e.relatedTarget)) markDropHover(pane, false);
            });
            pane.addEventListener('drop', async (e) => {
                e.preventDefault();
                markDropHover(pane, false);
                const ids = dragPayloadIds.slice();
                dragPayloadIds = [];
                if (!ids.length) return;
                const groupId = boardGroupSelect ? (boardGroupSelect.value || '') : '';
                if (pane.dataset.boardTarget === 'group') {
                    if (!groupId) {
                        showToast('Pilih label tujuan dulu.', 'error');
                        return;
                    }
                    await assignTransactionsToGroup(ids, groupId);
                } else {
                    await assignTransactionsToGroup(ids, '');
                }
            });
        }

        wireBoardPane(boardPaneAvailable);
        wireBoardPane(boardPaneMembers);

        if (boardGroupSelect) {
            boardGroupSelect.addEventListener('change', () => refreshBoard());
        }
        if (boardSearch) {
            boardSearch.addEventListener('input', () => {
                if (boardSearchTimer) clearTimeout(boardSearchTimer);
                boardSearchTimer = setTimeout(() => refreshBoard(), 300);
            });
        }

        window.openGroupModal = async function() {
            groupModal.classList.add('active');
            await loadGroups();
            if (boardGroupSelect && !boardGroupSelect.value && transactionGroups.length) {
                boardGroupSelect.value = String(transactionGroups[0].id);
            }
            await refreshBoard();
        };

        window.closeGroupModal = function() {
            groupModal.classList.remove('active');
        };

        if (btnManageGroup) {
            btnManageGroup.addEventListener('click', () => window.openGroupModal());
        }
        if (groupModal) {
            groupModal.addEventListener('click', (e) => {
                if (e.target === groupModal) closeGroupModal();
            });
        }

        btnSearch.addEventListener('click', () => {
            reloadFromFirstPage();
        });
        btnPrintList.addEventListener('click', () => {
            window.printTxList();
        });
        txDetailPrintNota.addEventListener('click', () => {
            if (activeDetailId) window.printTxNota(activeDetailId);
        });
        txDetailPrintKwitansi.addEventListener('click', () => {
            if (activeDetailId) window.printTxKwitansi(activeDetailId);
        });
        txPrev.addEventListener('click', () => {
            if (page > 1) {
                page -= 1;
                loadTransactions();
            }
        });
        txNext.addEventListener('click', () => {
            if (page < totalPages) {
                page += 1;
                loadTransactions();
            }
        });
        txPurchase.addEventListener('change', () => {
            reloadFromFirstPage();
        });
        txGroupBy.addEventListener('change', () => {
            reloadFromFirstPage();
        });
        if (txGroup) {
            txGroup.addEventListener('change', () => {
                reloadFromFirstPage();
            });
        }
        txStatus.addEventListener('change', () => {
            reloadFromFirstPage();
        });
        txStartDate.addEventListener('change', () => {
            reloadFromFirstPage();
        });
        txEndDate.addEventListener('change', () => {
            reloadFromFirstPage();
        });
        txSearch.addEventListener('input', () => {
            if (searchDebounceTimer) {
                clearTimeout(searchDebounceTimer);
            }
            searchDebounceTimer = setTimeout(() => {
                reloadFromFirstPage();
            }, 300);
        });
        txSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                if (searchDebounceTimer) {
                    clearTimeout(searchDebounceTimer);
                }
                reloadFromFirstPage();
            }
        });
        if (confirmCancelPaidBtn) {
            confirmCancelPaidBtn.addEventListener('click', submitCancelPaid);
        }
        if (cancelPaidPassword) {
            cancelPaidPassword.addEventListener('input', () => {
                setCancelValidation('');
            });
            cancelPaidPassword.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitCancelPaid();
                }
            });
        }
        if (cancelPaidModal) {
            cancelPaidModal.addEventListener('click', (e) => {
                if (e.target === cancelPaidModal) {
                    closeCancelPaidModal();
                }
            });
        }
        setToday();
        loadGroups();
        loadTransactions();
    </script>
</body>

</html>
