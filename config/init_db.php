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
            module_projects INTEGER DEFAULT 0,
            tax_number VARCHAR(100) NULL,
            cr_number VARCHAR(100) NULL,
            address TEXT NULL,
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
            permissions TEXT NULL,
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
            sign_token VARCHAR(64) NULL,
            recipient_signature TEXT NULL,
            signature_status VARCHAR(30) DEFAULT 'pending_signature',
            signed_at DATETIME NULL,
            signed_ip VARCHAR(45) NULL,
            signed_by_name VARCHAR(255) NULL,
            issuer_signature TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_transaction_id) REFERENCES safe_transactions(id) ON DELETE SET NULL
        )
    ");

    // Projects table (Real Estate / Construction module)
    $db->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) NULL,
            capital DECIMAL(15, 2) DEFAULT 0.00,
            currency VARCHAR(20) DEFAULT 'ر.س',
            safe_id INTEGER NULL,
            location VARCHAR(255) NULL,
            status VARCHAR(50) DEFAULT 'active', -- 'active', 'completed', 'paused'
            project_type VARCHAR(50) DEFAULT 'with_capital', -- 'with_capital', 'without_capital'
            client_name VARCHAR(255) NULL,
            client_phone VARCHAR(50) NULL,
            client_fee_percentage DECIMAL(5, 2) DEFAULT 0.00,
            description TEXT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            share_token VARCHAR(64) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (safe_id) REFERENCES safes(id) ON DELETE SET NULL
        )
    ");

    // Project Daily Progress Reports (تقارير الإنجاز اليومية والميدانية للمشاريع)
    $db->exec("
        CREATE TABLE IF NOT EXISTS project_daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            report_date DATE NOT NULL,
            period_range VARCHAR(255) NULL, -- e.g. 'من السبت للسبت' أو 'من الخميس للخميس'
            title VARCHAR(255) NOT NULL,
            stage VARCHAR(100) NULL, -- e.g. 'سباكة', 'عظم', 'كهرباء', 'تشطيبات'
            completion_percentage INTEGER DEFAULT 0,
            description TEXT NULL,
            video_url TEXT NULL,
            video_file VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Project Report Media (صور وفيديوهات الموقع المرفقة بكل تقرير)
    $db->exec("
        CREATE TABLE IF NOT EXISTS project_report_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(20) DEFAULT 'image', -- 'image' or 'video'
            caption VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES project_daily_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    // Auto-migrate: check if columns exist in companies
    try {
        $cols = $db->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('logo', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN logo VARCHAR(255) NULL;");
        }
        if (!in_array('module_projects', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN module_projects INTEGER DEFAULT 0;");
        }
        if (!in_array('tax_number', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN tax_number VARCHAR(100) NULL;");
        }
        if (!in_array('cr_number', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN cr_number VARCHAR(100) NULL;");
        }
        if (!in_array('address', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN address TEXT NULL;");
        }
        if (!in_array('module_social_media', $cols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN module_social_media INTEGER DEFAULT 1;");
            $db->exec("UPDATE companies SET module_social_media = 1 WHERE module_social_media IS NULL;");
        }
    } catch (Exception $e) {
        // In case of MySQL or if already added
    }

    // Auto-migrate: safes table (project_id, type)
    try {
        $safeCols = $db->query("PRAGMA table_info(safes)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('project_id', $safeCols)) {
            $db->exec("ALTER TABLE safes ADD COLUMN project_id INTEGER NULL;");
        }
        if (!in_array('type', $safeCols)) {
            $db->exec("ALTER TABLE safes ADD COLUMN type VARCHAR(50) DEFAULT 'main';");
        }
    } catch (Exception $e) {}

    // Auto-migrate: expenses table (project_id, attachment, tax, beneficiary, voucher)
    try {
        $expCols = $db->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('project_id', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN project_id INTEGER NULL;");
        }
        if (!in_array('invoice_attachment', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN invoice_attachment VARCHAR(255) NULL;");
        }
        if (!in_array('tax_rate', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN tax_rate DECIMAL(5, 2) DEFAULT 0.00;");
        }
        if (!in_array('tax_amount', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN tax_amount DECIMAL(15, 2) DEFAULT 0.00;");
        }
        if (!in_array('beneficiary_name', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN beneficiary_name VARCHAR(255) NULL;");
        }
        if (!in_array('beneficiary_id_number', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN beneficiary_id_number VARCHAR(100) NULL;");
        }
        if (!in_array('beneficiary_phone', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN beneficiary_phone VARCHAR(50) NULL;");
        }
        if (!in_array('voucher_number', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN voucher_number VARCHAR(50) NULL;");
        }
        if (!in_array('status', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN status VARCHAR(30) DEFAULT 'deducted';");
        }
        if (!in_array('payment_method', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(50) DEFAULT 'نقداً كاش';");
        }
        if (!in_array('sign_token', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN sign_token VARCHAR(64) NULL;");
        }
        if (!in_array('recipient_signature', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN recipient_signature TEXT NULL;");
        }
        if (!in_array('signature_status', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN signature_status VARCHAR(30) DEFAULT 'pending_signature';");
        }
        if (!in_array('signed_at', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN signed_at DATETIME NULL;");
        }
        if (!in_array('signed_ip', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN signed_ip VARCHAR(45) NULL;");
        }
        if (!in_array('signed_by_name', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN signed_by_name VARCHAR(255) NULL;");
        }
        if (!in_array('issuer_signature', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN issuer_signature TEXT NULL;");
        }
        $db->exec("UPDATE expenses SET status = 'deducted' WHERE status IS NULL OR status = '';");
        $db->exec("UPDATE expenses SET sign_token = lower(hex(randomblob(16))) WHERE sign_token IS NULL OR sign_token = '';");
    } catch (Exception $e) {}

    // Auto-migrate: safe_transactions table (project_id)
    try {
        $stCols = $db->query("PRAGMA table_info(safe_transactions)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('project_id', $stCols)) {
            $db->exec("ALTER TABLE safe_transactions ADD COLUMN project_id INTEGER NULL;");
        }
    } catch (Exception $e) {}

    // Auto-migrate: projects table (share_token)
    try {
        $pCols = $db->query("PRAGMA table_info(projects)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('share_token', $pCols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN share_token VARCHAR(64) NULL;");
        }
        $emptyTokens = $db->query("SELECT id FROM projects WHERE share_token IS NULL OR share_token = ''")->fetchAll();
        foreach ($emptyTokens as $et) {
            $tok = bin2hex(random_bytes(16));
            $db->prepare("UPDATE projects SET share_token = :tok WHERE id = :id")->execute([':tok' => $tok, ':id' => $et['id']]);
        }

        // Auto-migrate: projects table (project_type, client_name, client_phone, client_fee_percentage)
        if (!in_array('project_type', $pCols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN project_type VARCHAR(50) DEFAULT 'with_capital';");
        }
        if (!in_array('client_name', $pCols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN client_name VARCHAR(255) NULL;");
        }
        if (!in_array('client_phone', $pCols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN client_phone VARCHAR(50) NULL;");
        }
        if (!in_array('client_fee_percentage', $pCols)) {
            $db->exec("ALTER TABLE projects ADD COLUMN client_fee_percentage DECIMAL(5, 2) DEFAULT 0.00;");
        }
    } catch (Exception $e) {}

    // Auto-migrate: receipts table (project_id & electronic signatures)
    try {
        $recCols = $db->query("PRAGMA table_info(receipts)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('project_id', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN project_id INTEGER NULL;");
        }
        if (!in_array('sign_token', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN sign_token VARCHAR(64) NULL;");
        }
        if (!in_array('recipient_signature', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN recipient_signature TEXT NULL;");
        }
        if (!in_array('signature_status', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN signature_status VARCHAR(30) DEFAULT 'pending_signature';");
        }
        if (!in_array('signed_at', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN signed_at DATETIME NULL;");
        }
        if (!in_array('signed_ip', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN signed_ip VARCHAR(45) NULL;");
        }
        if (!in_array('signed_by_name', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN signed_by_name VARCHAR(255) NULL;");
        }
        if (!in_array('issuer_signature', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN issuer_signature TEXT NULL;");
        }
        // Ensure every existing receipt has a unique sign_token
        $db->exec("UPDATE receipts SET sign_token = lower(hex(randomblob(16))) WHERE sign_token IS NULL OR sign_token = '';");
    } catch (Exception $e) {}

    // Auto-migrate: users table (electronic signature for issuer)
    try {
        $userCols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('signature', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN signature TEXT NULL;");
        }
    } catch (Exception $e) {}

    // Auto-migrate: companies table (signature and stamp)
    try {
        $cCols = $db->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('signature', $cCols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN signature TEXT NULL;");
        }
        if (!in_array('stamp', $cCols)) {
            $db->exec("ALTER TABLE companies ADD COLUMN stamp TEXT NULL;");
        }
    } catch (Exception $e) {}

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

    try {
        $payCols = $db->query("PRAGMA table_info(payrolls)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('currency', $payCols)) {
            $db->exec("ALTER TABLE payrolls ADD COLUMN currency VARCHAR(20) DEFAULT 'ج.م';");
        }
    } catch (Exception $e) {}

    // Auto-migrate: National Address fields for companies
    try {
        $compCols = $db->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $addrFields = [
            'building_no' => 'VARCHAR(50) NULL',
            'street_name' => 'VARCHAR(255) NULL',
            'district' => 'VARCHAR(150) NULL',
            'city' => 'VARCHAR(100) NULL',
            'postal_code' => 'VARCHAR(50) NULL',
            'additional_no' => 'VARCHAR(50) NULL',
            'country' => 'VARCHAR(50) NULL DEFAULT "SA"',
            'email' => 'VARCHAR(150) NULL',
            'location_url' => 'TEXT NULL',
            'primary_color' => 'VARCHAR(30) DEFAULT "#b91c1c"',
            'dashboard_color' => 'VARCHAR(30) DEFAULT "#4f46e5"'
        ];
        foreach ($addrFields as $fld => $fldType) {
            if (!in_array($fld, $compCols)) {
                $db->exec("ALTER TABLE companies ADD COLUMN {$fld} {$fldType};");
            }
        }
    } catch (Exception $e) {}

    // Auto-migrate: Cheque and bank fields for receipts and expenses
    try {
        $userCols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('permissions', $userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL;");
        }
    } catch (Exception $e) {}

    try {
        $recCols = $db->query("PRAGMA table_info(receipts)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('cheque_number', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN cheque_number VARCHAR(100) NULL;");
        }
        if (!in_array('bank_name', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN bank_name VARCHAR(150) NULL;");
        }
        if (!in_array('cheque_date', $recCols)) {
            $db->exec("ALTER TABLE receipts ADD COLUMN cheque_date DATE NULL;");
        }
    } catch (Exception $e) {}

    try {
        $expCols = $db->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('cheque_number', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN cheque_number VARCHAR(100) NULL;");
        }
        if (!in_array('bank_name', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN bank_name VARCHAR(150) NULL;");
        }
        if (!in_array('cheque_date', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN cheque_date DATE NULL;");
        }
        if (!in_array('approved_by', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN approved_by INTEGER NULL;");
        }
        if (!in_array('approved_at', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN approved_at DATETIME NULL;");
        }
        if (!in_array('rejected_by', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN rejected_by INTEGER NULL;");
        }
        if (!in_array('rejected_at', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN rejected_at DATETIME NULL;");
        }
        if (!in_array('rejection_reason', $expCols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN rejection_reason TEXT NULL;");
        }
    } catch (Exception $e) {}

    // Auto-migrate: Create invoices and invoice_items tables (Simplified Tax Invoices)
    $db->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            user_id INTEGER,
            invoice_number VARCHAR(50) NOT NULL,
            invoice_date DATE NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NULL,
            customer_tax_number VARCHAR(100) NULL,
            payment_method VARCHAR(50) DEFAULT 'نقداً',
            discount_days INTEGER DEFAULT 0,
            subtotal DECIMAL(15,2) DEFAULT 0,
            tax_rate DECIMAL(5,2) DEFAULT 15.00,
            tax_amount DECIMAL(15,2) DEFAULT 0,
            discount_amount DECIMAL(15,2) DEFAULT 0,
            total_amount DECIMAL(15,2) DEFAULT 0,
            currency VARCHAR(20) DEFAULT 'ج.م',
            warehouse VARCHAR(100) NULL,
            seller_name VARCHAR(100) NULL,
            notes TEXT NULL,
            qr_data TEXT NULL,
            status VARCHAR(30) DEFAULT 'issued',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            item_code VARCHAR(50) NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(15,2) DEFAULT 1,
            unit VARCHAR(50) DEFAULT 'طن',
            unit_price DECIMAL(15,2) DEFAULT 0,
            discount_amount DECIMAL(15,2) DEFAULT 0,
            tax_rate DECIMAL(5,2) DEFAULT 15.00,
            tax_amount DECIMAL(15,2) DEFAULT 0,
            total_amount DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        )
    ");

    // ==========================================
    // SOCIAL MEDIA MANAGEMENT MODULE TABLES
    // (Multi-Tenant SaaS - Complete Isolation)
    // ==========================================

    // 1. Connected Social Accounts
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            platform VARCHAR(50) NOT NULL, -- 'facebook', 'instagram', 'tiktok', 'snapchat'
            account_id VARCHAR(150) NOT NULL, -- External ID (Page ID, IG User ID, TikTok open_id, etc.)
            account_name VARCHAR(255) NOT NULL,
            account_username VARCHAR(150) NULL,
            avatar_url TEXT NULL,
            access_token TEXT NOT NULL, -- AES-256 Encrypted at rest
            refresh_token TEXT NULL,    -- AES-256 Encrypted at rest
            token_expires_at DATETIME NULL,
            status VARCHAR(50) DEFAULT 'active', -- 'active', 'disconnected', 'expired', 'error'
            error_details TEXT NULL,
            metadata TEXT NULL, -- JSON extra data (permissions, page category, followers, etc.)
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES companies(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_sa_tenant ON social_accounts(tenant_id);
        CREATE INDEX IF NOT EXISTS idx_sa_tenant_platform ON social_accounts(tenant_id, platform);
        CREATE INDEX IF NOT EXISTS idx_sa_status ON social_accounts(status);
    ");

    // 2. Social Posts (Drafts, Scheduled, Published)
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            caption TEXT NOT NULL,
            platform_captions TEXT NULL, -- JSON custom caption overrides per platform
            status VARCHAR(50) DEFAULT 'draft', -- 'draft', 'scheduled', 'processing', 'published', 'partially_published', 'failed', 'cancelled'
            scheduled_at DATETIME NULL,
            published_at DATETIME NULL,
            timezone VARCHAR(50) DEFAULT 'UTC',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_sp_tenant ON social_posts(tenant_id);
        CREATE INDEX IF NOT EXISTS idx_sp_status ON social_posts(status);
        CREATE INDEX IF NOT EXISTS idx_sp_scheduled ON social_posts(status, scheduled_at);
        CREATE INDEX IF NOT EXISTS idx_sp_created ON social_posts(created_at);
    ");

    // 3. Social Post Target Platforms (Per-account publishing status & external IDs)
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_post_platforms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            social_account_id INTEGER NOT NULL,
            platform VARCHAR(50) NOT NULL,
            status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'processing', 'published', 'failed', 'cancelled'
            external_post_id VARCHAR(255) NULL,
            external_post_url TEXT NULL,
            error_message TEXT NULL,
            retry_count INTEGER DEFAULT 0,
            last_attempt_at DATETIME NULL,
            published_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
            FOREIGN KEY (tenant_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_spp_post ON social_post_platforms(post_id);
        CREATE INDEX IF NOT EXISTS idx_spp_tenant_platform ON social_post_platforms(tenant_id, platform);
        CREATE INDEX IF NOT EXISTS idx_spp_status ON social_post_platforms(status);
    ");

    // 4. Social Media Files (Uploaded images and videos)
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            post_id INTEGER NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(20) DEFAULT 'image', -- 'image' or 'video'
            file_name VARCHAR(255) NOT NULL,
            file_size INTEGER DEFAULT 0,
            mime_type VARCHAR(100) NULL,
            dimensions VARCHAR(50) NULL,
            duration DECIMAL(10,2) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_smf_tenant ON social_media_files(tenant_id);
        CREATE INDEX IF NOT EXISTS idx_smf_post ON social_media_files(post_id);
    ");

    // 5. Social Publishing & Audit Logs
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_publish_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            post_id INTEGER NULL,
            social_account_id INTEGER NULL,
            platform VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL, -- 'publish_attempt', 'publish_success', 'publish_failure', 'token_refresh', 'cron_batch', 'disconnect'
            status_code INTEGER NULL,
            response_payload TEXT NULL, -- Sanitized API payload (secrets redacted)
            error_message TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES companies(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_spl_tenant ON social_publish_logs(tenant_id);
        CREATE INDEX IF NOT EXISTS idx_spl_post ON social_publish_logs(post_id);
        CREATE INDEX IF NOT EXISTS idx_spl_created ON social_publish_logs(created_at);
    ");

    // 6. Social Settings (API credentials and cron state)
    $db->exec("
        CREATE TABLE IF NOT EXISTS social_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NULL, -- NULL for global default credentials, or specific tenant override
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            is_secret INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_ss_tenant_key ON social_settings(tenant_id, setting_key);
    ");

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
