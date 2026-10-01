<?php
require_once 'auth.php';
require_login();
require_once 'config/database.php';

$pdo = get_db();

function ensure_backup_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'backup_exports';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function save_backup_file(string $filename, string $content): void
{
    $dir = ensure_backup_dir();
    file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $content);
}

function ensure_transaction_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(40) NOT NULL UNIQUE,
            transaction_at DATETIME NOT NULL,
            customer_name VARCHAR(150) NULL,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            total DECIMAL(15,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            change_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_transaction_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            product_code VARCHAR(60) NULL,
            product_name VARCHAR(200) NOT NULL,
            variant_id INT UNSIGNED NULL,
            variant_name VARCHAR(120) NULL,
            price DECIMAL(15,2) NOT NULL DEFAULT 0,
            qty INT NOT NULL DEFAULT 1,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_transaction_id (transaction_id),
            CONSTRAINT fk_sales_items_transaction
                FOREIGN KEY (transaction_id) REFERENCES sales_transactions(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function sql_value(PDO $pdo, $value): string
{
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $pdo->quote((string)$value);
}

function table_exists(PDO $pdo, string $tableName): bool
{
    // SHOW TABLES tidak menerima placeholder pada native prepared statement (EMULATE_PREPARES = false).
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function export_table_sql(PDO $pdo, string $tableName): string
{
    $out = "-- Table: `{$tableName}`\n";
    $createStmt = $pdo->query("SHOW CREATE TABLE `{$tableName}`")->fetch(PDO::FETCH_ASSOC);
    if (!$createStmt) return $out . "-- skipped\n\n";
    $createSql = $createStmt['Create Table'] ?? '';
    $out .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
    $out .= $createSql . ";\n\n";

    $rows = $pdo->query("SELECT * FROM `{$tableName}`")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        $out .= "-- no data\n\n";
        return $out;
    }

    $columns = array_keys($rows[0]);
    $columnsSql = '`' . implode('`,`', $columns) . '`';
    $out .= "INSERT INTO `{$tableName}` ({$columnsSql}) VALUES\n";
    $chunks = [];
    foreach ($rows as $row) {
        $vals = [];
        foreach ($columns as $col) {
            $vals[] = sql_value($pdo, $row[$col]);
        }
        $chunks[] = '(' . implode(',', $vals) . ')';
    }
    $out .= implode(",\n", $chunks) . ";\n\n";
    return $out;
}

$type = $_GET['type'] ?? 'full_sql';

ensure_transaction_tables($pdo);

if ($type === 'full_sql') {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $sql = "-- POS Backup SQL\n-- Generated at: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        $tableName = $t[0];
        $sql .= export_table_sql($pdo, $tableName);
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'backup_full_' . date('Ymd_His') . '.sql';
    save_backup_file($filename, $sql);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $sql;
    exit;
}

if ($type === 'transactions_sql') {
    $sql = "-- POS Transactions Backup SQL\n-- Generated at: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (['sales_transaction_items', 'sales_transactions'] as $table) {
        if (table_exists($pdo, $table)) {
            $sql .= export_table_sql($pdo, $table);
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'backup_transactions_' . date('Ymd_His') . '.sql';
    save_backup_file($filename, $sql);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $sql;
    exit;
}

if ($type === 'transactions_csv') {
    $sql = 'SELECT
                t.invoice_no,
                t.transaction_at,
                t.customer_name,
                i.product_code,
                i.product_name,
                i.variant_name,
                i.price,
                i.qty,
                i.subtotal,
                t.total,
                t.paid_amount,
                t.change_amount
            FROM sales_transactions t
            LEFT JOIN sales_transaction_items i ON i.transaction_id = t.id
            ORDER BY t.id DESC, i.id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'backup_transactions_' . date('Ymd_His') . '.csv';
    $tmp = fopen('php://temp', 'w+');
    $fp = $tmp;
    fputcsv($fp, [
        'invoice_no', 'transaction_at', 'customer_name', 'product_code',
        'product_name', 'variant_name', 'price', 'qty', 'subtotal', 'total',
        'paid_amount', 'change_amount'
    ]);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    rewind($tmp);
    $csvContent = stream_get_contents($tmp);
    fclose($tmp);

    if ($csvContent === false) {
        $csvContent = '';
    }
    save_backup_file($filename, $csvContent);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csvContent;
    exit;
}

header('Location: settings.php?msg=invalid_export');
exit;
