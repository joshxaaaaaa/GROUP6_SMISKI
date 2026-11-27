USE ecommerce_activity_db;

CREATE TABLE IF NOT EXISTS discounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    discount_type VARCHAR(20) NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    min_purchase DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_completed_orders INT NOT NULL DEFAULT 0,
    usage_limit INT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_discount_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    moderated_by INT NULL,
    moderated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_review_product_customer (product_id, customer_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders MODIFY discount_type VARCHAR(100) DEFAULT NULL;

SET @has_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'discount_id');
SET @sql := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN discount_id INT NULL AFTER discount_type', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_discount');
SET @sql := IF(@has_fk = 0, 'ALTER TABLE orders ADD CONSTRAINT fk_orders_discount FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO discounts (name, description, discount_type, discount_value, min_purchase, min_completed_orders, usage_limit, start_date, end_date, is_active)
SELECT 'Loyal Customer Discount',
       CONCAT('Automatic reward for customers with at least ', COALESCE((SELECT setting_value FROM system_settings WHERE setting_key = 'loyalty_min_purchases'), '5'), ' completed (delivered) orders.'),
       'percentage',
       COALESCE((SELECT setting_value FROM system_settings WHERE setting_key = 'loyalty_discount_rate'), '5'),
       0,
       COALESCE((SELECT setting_value FROM system_settings WHERE setting_key = 'loyalty_min_purchases'), '5'),
       NULL, NULL, NULL, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM discounts);

INSERT INTO system_settings (setting_key, setting_value) VALUES ('store_schema_version', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';
