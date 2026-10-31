<?php
/**
 * Auto-initialization and seeding of the database.
 */

function ensureDatabaseTablesExist(): void {
    $db = getDBConnection();

    // Companies table
    $db->exec("
        CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) UNIQUE NOT NULL,
            phone VARCHAR(50) NULL,
            currency VARCHAR(20) DEFAULT 'ج.م',
            is_active INTEGER DEFAULT 1,
            module_treasury INTEGER DEFAULT 1,
            module_expenses INTEGER DEFAULT 1,
            module_hr INTEGER DEFAULT 0,
            module_reports INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NULL,
            name VARCHAR(150) NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL, -- 'super_admin', 'company_admin', 'data_entry'
            phone VARCHAR(50) NULL,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    // Safes / Treasuries table
    $db->exec("
        CREATE TABLE IF NOT EXISTS safes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            name VARCHAR(150) NOT NULL DEFAULT 'الخزنة الرئيسية',
            balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    // Safe transactions (ledger)
    $db->exec("
        CREATE TABLE IF NOT EXISTS safe_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            safe_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            type VARCHAR(20) NOT NULL, -- 'deposit', 'expense', 'salary', 'initial'
            category VARCHAR(150) NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            currency VARCHAR(20) DEFAULT 'ج.م',
            balance_before DECIMAL(15, 2) NOT NULL,
            balance_after DECIMAL(15, 2) NOT NULL,
            description TEXT NULL,
            transaction_date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_id) REFERENCES safes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Expense Categories
    $db->exec("
        CREATE TABLE IF NOT EXISTS expense_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            name VARCHAR(150) NOT NULL,
            icon VARCHAR(50) DEFAULT 'fa-folder',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    // Expenses
    $db->exec("
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            category_id INTEGER NULL,
            safe_id INTEGER NOT NULL,
            safe_transaction_id INTEGER NULL,
            user_id INTEGER NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            currency VARCHAR(20) DEFAULT 'ج.م',
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            expense_date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
            FOREIGN KEY (safe_id) REFERENCES safes(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_transaction_id) REFERENCES safe_transactions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Employees (HR module)
    $db->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            name VARCHAR(150) NOT NULL,
            job_title VARCHAR(150) NULL,
            phone VARCHAR(50) NULL,
            base_salary DECIMAL(15, 2) DEFAULT 0.00,
            hire_date DATE NULL,
            status VARCHAR(20) DEFAULT 'active', -- 'active', 'inactive'
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    // Payrolls (HR module)
    $db->exec("
        CREATE TABLE IF NOT EXISTS payrolls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            month_year VARCHAR(10) NOT NULL, -- e.g. 2026-10
            base_salary DECIMAL(15, 2) NOT NULL,
            bonus DECIMAL(15, 2) DEFAULT 0.00,
            deductions DECIMAL(15, 2) DEFAULT 0.00,
            net_salary DECIMAL(15, 2) NOT NULL,
            status VARCHAR(20) DEFAULT 'paid', -- 'paid', 'pending'
            paid_date DATE NULL,
            safe_transaction_id INTEGER NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_transaction_id) REFERENCES safe_transactions(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Receipts (سندات / إيصالات استلام النقدية)
    $db->exec("
        CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_number VARCHAR(50) UNIQUE NOT NULL,
            company_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            safe_transaction_id INTEGER NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NULL,
            amount DECIMAL(15, 2) NOT NULL,
            currency VARCHAR(20) DEFAULT 'ج.م',
            amount_words VARCHAR(255) NULL,
            payment_method VARCHAR(50) DEFAULT 'نقداً كاش',
            purpose TEXT NOT NULL,
            receipt_date DATE NOT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_transaction_id) REFERENCES safe_transactions(id) ON DELETE SET NULL
        )
    ");

    // Auto-migrate: check if logo column exists in companies
    try {
        $cols = $db->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('logo', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN logo VARCHAR(255) NULL;");
        }
    } catch (Exception $e) {
        // In case of MySQL or if already added
    }

    // Auto-migrate: add currency column to receipts, safe_transactions, expenses if missing
    try {
        $recCols = $db->query("PRAGMA table_info(receipts)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('currency', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN currency VARCHAR(20) DEFAULT 'ج.م';");
        }
    } catch (Exception $e) {}

    try {
        $stCols = $db->query("PRAGMA table_info(safe_transactions)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('currency', $stCols)) {
            $db->exec("ALTER TABLE safe_transactions ADD COLUMN currency VARCHAR(20) DEFAULT 'ج.م';");
        }
    } catch (Exception $e) {}

    try {
        $expCols = $db->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('currency', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN currency VARCHAR(20) DEFAULT 'ج.م';");
        }
    } catch (Exception $e) {}

    // Seed data if no users exist
    $checkUser = $db->query("SELECT COUNT(*) as cnt FROM users")->fetch();
    if ($checkUser && (int)$checkUser['cnt'] === 0) {
        seedInitialData($db);
    }
}

function seedInitialData(PDO $db): void {
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // 1. Super Admin
    $superAdminStmt = $db->prepare("
        INSERT INTO users (company_id, name, username, password, role, phone, is_active, created_at)
        VALUES (NULL, 'سوبر أدمن المنصة', 'admin', :pass, 'super_admin', '01000000000', 1, :now)
    ");
    $superAdminStmt->execute([
        ':pass' => password_hash('admin123', PASSWORD_DEFAULT),
        ':now' => $now
    ]);
    $superAdminId = (int)$db->lastInsertId();

    // 2. Company 1: كوكب الصين (requested by user: HR disabled, Treasury & Expenses enabled)
    $coStmt = $db->prepare("
        INSERT INTO companies (name, code, phone, currency, is_active, module_treasury, module_expenses, module_hr, module_reports, created_at)
        VALUES (:name, :code, :phone, :currency, 1, :mt, :me, :mhr, :mr, :now)
    ");
    $coStmt->execute([
        ':name' => 'شركة كوكب الصين',
        ':code' => 'planet-china',
        ':phone' => '01012345678',
        ':currency' => 'ج.م',
        ':mt' => 1,
        ':me' => 1,
        ':mhr' => 0,
        ':mr' => 1,
        ':now' => $now
    ]);
    $chinaCoId = (int)$db->lastInsertId();

    // Create Main Safe for كوكب الصين with 0.00 initial balance
    $safeStmt = $db->prepare("
        INSERT INTO safes (company_id, name, balance, created_at)
        VALUES (:cid, 'الخزنة الرئيسية', 0.00, :now)
    ");
    $safeStmt->execute([':cid' => $chinaCoId, ':now' => $now]);
    $chinaSafeId = (int)$db->lastInsertId();

    // Users for كوكب الصين
    $uStmt = $db->prepare("
        INSERT INTO users (company_id, name, username, password, role, phone, is_active, created_at)
        VALUES (:cid, :name, :username, :pass, :role, :phone, 1, :now)
    ");

    // A: Company Admin (معاذ)
    $uStmt->execute([
        ':cid' => $chinaCoId,
        ':name' => 'معاذ (مدير كوكب الصين)',
        ':username' => 'china_admin',
        ':pass' => password_hash('123456', PASSWORD_DEFAULT),
        ':role' => 'company_admin',
        ':phone' => '01011112222',
        ':now' => $now
    ]);
    $chinaAdminId = (int)$db->lastInsertId();

    // B: Data Entry User for كوكب الصين
    $uStmt->execute([
        ':cid' => $chinaCoId,
        ':name' => 'مدخل بيانات - كوكب الصين',
        ':username' => 'china_entry',
        ':pass' => password_hash('123456', PASSWORD_DEFAULT),
        ':role' => 'data_entry',
        ':phone' => '01022223333',
        ':now' => $now
    ]);
    $chinaDataEntryId = (int)$db->lastInsertId();

    // Expense Categories for كوكب الصين
    $categories = [
        ['إيجار المقر', 'fa-building'],
        ['فواتير ومرافق (كهرباء وإنترنت)', 'fa-bolt'],
        ['شحن ونقل وبضاعة', 'fa-truck-fast'],
        ['ضيافة وبوفيه ونثريات', 'fa-mug-hot'],
        ['أدوات مكتبية وصيانة', 'fa-wrench'],
        ['تسويق وإعلانات', 'fa-bullhorn']
    ];
    $catStmt = $db->prepare("
        INSERT INTO expense_categories (company_id, name, icon, is_active, created_at)
        VALUES (:cid, :name, :icon, 1, :now)
    ");
    foreach ($categories as $cat) {
        $catStmt->execute([':cid' => $chinaCoId, ':name' => $cat[0], ':icon' => $cat[1], ':now' => $now]);
    }
}
