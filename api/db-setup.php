<?php
// ONE-TIME SELF-DELETING DB SETUP SCRIPT
// Visit this URL once to create the 4 tables, then it auto-deletes.
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmts = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            plan ENUM('free','premium') DEFAULT 'free',
            stripe_customer_id VARCHAR(255) DEFAULT NULL,
            stripe_subscription_id VARCHAR(255) DEFAULT NULL,
            plan_expires_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_stripe_customer (stripe_customer_id),
            INDEX idx_stripe_sub (stripe_subscription_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS usage_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            guest_ip VARCHAR(45) DEFAULT NULL,
            log_date DATE NOT NULL,
            rewrites_today INT DEFAULT 0,
            UNIQUE KEY uq_user_date (user_id, log_date),
            UNIQUE KEY uq_ip_date (guest_ip, log_date),
            INDEX idx_user (user_id),
            INDEX idx_ip (guest_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS rewrite_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            original_text TEXT,
            v1 TEXT, v2 TEXT, v3 TEXT,
            platform VARCHAR(50) DEFAULT 'Email',
            tone VARCHAR(50) DEFAULT 'Professional',
            language VARCHAR(50) DEFAULT 'English',
            mode VARCHAR(20) DEFAULT 'rewrite',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_time (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS user_voices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            sample_messages TEXT,
            style_summary TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    $created = [];
    foreach ($stmts as $i => $sql) {
        $pdo->exec($sql);
        $names = ['users', 'usage_log', 'rewrite_history', 'user_voices'];
        $created[] = $names[$i];
    }

    // Verify
    $check = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    // Self-delete
    unlink(__FILE__);

    echo "SUCCESS\n";
    echo "Tables created: " . implode(', ', $created) . "\n";
    echo "Tables in DB: " . implode(', ', $check) . "\n";
    echo "Script self-deleted.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
