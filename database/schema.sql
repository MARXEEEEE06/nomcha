-- Nomcha database schema for MariaDB 10.11.
-- This file defines structure only. It contains no account, menu, promotion, or demo rows.
-- PSGC reference rows are imported from database/psgc_reference.json by setup.php using PDO.
-- Select the nomcha_db database before importing this file.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE user_info (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(13) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_info_phone (phone),
    CONSTRAINT chk_user_info_phone CHECK (phone IS NULL OR phone REGEXP '^(09[0-9]{9}|\\+639[0-9]{9})$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_credentials (
    user_cred_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    first_login_activation TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_cred_id),
    UNIQUE KEY uq_user_credentials_user (user_id),
    UNIQUE KEY uq_user_credentials_email (email),
    CONSTRAINT fk_user_credentials_info FOREIGN KEY (user_id)
        REFERENCES user_info (user_id) ON DELETE CASCADE,
    CONSTRAINT chk_user_credentials_active CHECK (is_active IN (0,1)),
    CONSTRAINT chk_user_credentials_activation CHECK (first_login_activation IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE psgc_regions (
    psgc_code CHAR(10) NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (psgc_code),
    UNIQUE KEY uq_psgc_regions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE psgc_provinces (
    psgc_code CHAR(10) NOT NULL,
    region_code CHAR(10) NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (psgc_code),
    UNIQUE KEY uq_psgc_provinces_region_name (region_code, name),
    KEY ix_psgc_provinces_region (region_code),
    CONSTRAINT fk_psgc_provinces_region FOREIGN KEY (region_code)
        REFERENCES psgc_regions (psgc_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE psgc_localities (
    psgc_code CHAR(10) NOT NULL,
    region_code CHAR(10) DEFAULT NULL,
    province_code CHAR(10) DEFAULT NULL,
    name VARCHAR(120) NOT NULL,
    locality_type ENUM('city','municipality') NOT NULL,
    PRIMARY KEY (psgc_code),
    UNIQUE KEY uq_psgc_localities_region_name (region_code, name),
    UNIQUE KEY uq_psgc_localities_province_name (province_code, name),
    KEY ix_psgc_localities_region (region_code),
    KEY ix_psgc_localities_province (province_code),
    CONSTRAINT fk_psgc_localities_region FOREIGN KEY (region_code)
        REFERENCES psgc_regions (psgc_code) ON DELETE RESTRICT,
    CONSTRAINT fk_psgc_localities_province FOREIGN KEY (province_code)
        REFERENCES psgc_provinces (psgc_code) ON DELETE RESTRICT,
    CONSTRAINT chk_psgc_localities_one_parent CHECK (
        (region_code IS NULL AND province_code IS NOT NULL)
        OR (region_code IS NOT NULL AND province_code IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE psgc_barangays (
    psgc_code CHAR(10) NOT NULL,
    locality_code CHAR(10) NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (psgc_code),
    UNIQUE KEY uq_psgc_barangays_locality_name (locality_code, name),
    KEY ix_psgc_barangays_locality (locality_code),
    CONSTRAINT fk_psgc_barangays_locality FOREIGN KEY (locality_code)
        REFERENCES psgc_localities (psgc_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_addresses (
    address_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    recipient_name VARCHAR(120) NOT NULL,
    recipient_phone VARCHAR(13) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    barangay_code CHAR(10) NOT NULL,
    zip_code CHAR(4) NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (address_id),
    KEY ix_user_addresses_default (user_id, is_default),
    KEY ix_user_addresses_barangay (barangay_code),
    CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id)
        REFERENCES user_info (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_user_addresses_barangay FOREIGN KEY (barangay_code)
        REFERENCES psgc_barangays (psgc_code) ON DELETE RESTRICT,
    CONSTRAINT chk_user_addresses_phone CHECK (recipient_phone REGEXP '^(09[0-9]{9}|\\+639[0-9]{9})$'),
    CONSTRAINT chk_user_addresses_zip CHECK (zip_code REGEXP '^[0-9]{4}$'),
    CONSTRAINT chk_user_addresses_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_user_addresses_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_user_addresses_default CHECK (is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
    branch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    barangay_code CHAR(10) NOT NULL,
    zip_code CHAR(4) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    schedule VARCHAR(180) NOT NULL,
    supports_delivery TINYINT(1) NOT NULL DEFAULT 1,
    supports_pickup TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_id),
    KEY ix_branches_status_name (status, name),
    KEY ix_branches_barangay (barangay_code),
    KEY ix_branches_created_by (created_by),
    CONSTRAINT fk_branches_barangay FOREIGN KEY (barangay_code)
        REFERENCES psgc_barangays (psgc_code) ON DELETE RESTRICT,
    CONSTRAINT fk_branches_created_by FOREIGN KEY (created_by)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL,
    CONSTRAINT chk_branches_latitude CHECK (latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_branches_longitude CHECK (longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_branches_zip CHECK (zip_code REGEXP '^[0-9]{4}$'),
    CONSTRAINT chk_branches_phone CHECK (phone REGEXP '^[0-9]{7,15}$'),
    CONSTRAINT chk_branches_delivery CHECK (supports_delivery IN (0,1)),
    CONSTRAINT chk_branches_pickup CHECK (supports_pickup IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branch_staff (
    branch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_id, user_id),
    KEY ix_branch_staff_user (user_id),
    CONSTRAINT fk_branch_staff_branch FOREIGN KEY (branch_id)
        REFERENCES branches (branch_id) ON DELETE CASCADE,
    CONSTRAINT fk_branch_staff_user FOREIGN KEY (user_id)
        REFERENCES user_credentials (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_menu_categories_parent_name (parent_id, name),
    KEY ix_menu_categories_parent (parent_id, sort_order),
    CONSTRAINT fk_menu_categories_parent FOREIGN KEY (parent_id)
        REFERENCES menu_categories (category_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
    item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL,
    unit_cost DECIMAL(10,2) DEFAULT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    image_data MEDIUMBLOB DEFAULT NULL,
    image_mime VARCHAR(40) DEFAULT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 0,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    KEY ix_menu_items_category_sort (category_id, sort_order),
    KEY ix_menu_items_availability (is_available, publish_at),
    CONSTRAINT fk_menu_items_category FOREIGN KEY (category_id)
        REFERENCES menu_categories (category_id) ON DELETE RESTRICT,
    CONSTRAINT chk_menu_items_price CHECK (price >= 0),
    CONSTRAINT chk_menu_items_unit_cost CHECK (unit_cost IS NULL OR unit_cost >= 0),
    CONSTRAINT chk_menu_items_available CHECK (is_available IN (0,1)),
    CONSTRAINT chk_menu_items_image_mime CHECK (image_mime IS NULL OR image_mime IN ('image/jpeg','image/png','image/webp')),
    CONSTRAINT chk_menu_items_image_pair CHECK ((image_data IS NULL) = (image_mime IS NULL)),
    CONSTRAINT chk_menu_items_image_source CHECK (image_data IS NULL OR image_url IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_option_groups (
    option_group_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (option_group_id),
    UNIQUE KEY uq_menu_option_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_options (
    option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    option_group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    source_item_id BIGINT UNSIGNED DEFAULT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (option_id),
    UNIQUE KEY uq_menu_options_group_name (option_group_id, name),
    UNIQUE KEY uq_menu_options_group_source (option_group_id, source_item_id),
    KEY ix_menu_options_source_item (source_item_id),
    CONSTRAINT fk_menu_options_group FOREIGN KEY (option_group_id)
        REFERENCES menu_option_groups (option_group_id) ON DELETE RESTRICT,
    CONSTRAINT fk_menu_options_source_item FOREIGN KEY (source_item_id)
        REFERENCES menu_items (item_id) ON DELETE RESTRICT,
    CONSTRAINT chk_menu_options_name_source CHECK (
        (name IS NOT NULL AND source_item_id IS NULL)
        OR (name IS NULL AND source_item_id IS NOT NULL)
    ),
    CONSTRAINT chk_menu_options_available CHECK (is_available IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_option_groups (
    item_option_group_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id BIGINT UNSIGNED NOT NULL,
    option_group_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    min_selections SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_selections SMALLINT UNSIGNED DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (item_option_group_id),
    UNIQUE KEY uq_menu_item_option_groups_item_label (item_id, label),
    CONSTRAINT fk_menu_item_option_groups_item FOREIGN KEY (item_id)
        REFERENCES menu_items (item_id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_item_option_groups_group FOREIGN KEY (option_group_id)
        REFERENCES menu_option_groups (option_group_id) ON DELETE RESTRICT,
    CONSTRAINT chk_menu_item_option_groups_limits CHECK (
        max_selections IS NULL OR max_selections >= min_selections
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_option_choices (
    item_option_group_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (item_option_group_id, option_id),
    KEY ix_menu_item_option_choices_option (option_id),
    CONSTRAINT fk_menu_item_option_choices_assignment FOREIGN KEY (item_option_group_id)
        REFERENCES menu_item_option_groups (item_option_group_id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_item_option_choices_option FOREIGN KEY (option_id)
        REFERENCES menu_options (option_id) ON DELETE RESTRICT,
    CONSTRAINT chk_menu_item_option_choices_price CHECK (price_adjustment >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotions (
    promo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    mechanics TEXT DEFAULT NULL,
    promo_code VARCHAR(60) DEFAULT NULL,
    is_platform_voucher TINYINT(1) NOT NULL DEFAULT 0,
    eligibility_scope ENUM('all','exclusive') NOT NULL DEFAULT 'all',
    discount_type ENUM('percent','fixed','free_item') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_discount DECIMAL(10,2) DEFAULT NULL,
    minimum_spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    channel VARCHAR(100) DEFAULT NULL,
    usage_limit INT UNSIGNED DEFAULT NULL,
    status ENUM('draft','scheduled','active','paused','ended') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED DEFAULT NULL,
    promo_image VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (promo_id),
    UNIQUE KEY uq_promotions_code (promo_code),
    KEY ix_promotions_schedule (status, starts_at, ends_at),
    KEY ix_promotions_created_by (created_by),
    CONSTRAINT fk_promotions_created_by FOREIGN KEY (created_by)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL,
    CONSTRAINT chk_promotions_voucher CHECK (is_platform_voucher IN (0,1)),
    CONSTRAINT chk_promotions_discount_value CHECK (discount_value >= 0),
    CONSTRAINT chk_promotions_max_discount CHECK (max_discount IS NULL OR max_discount >= 0),
    CONSTRAINT chk_promotions_minimum_spend CHECK (minimum_spend >= 0),
    CONSTRAINT chk_promotions_usage_limit CHECK (usage_limit IS NULL OR usage_limit > 0),
    CONSTRAINT chk_promotions_dates CHECK (ends_at > starts_at),
    CONSTRAINT chk_promotions_percent CHECK (discount_type <> 'percent' OR discount_value <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotion_faqs (
    faq_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promo_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (faq_id),
    KEY ix_promotion_faqs_promo_sort (promo_id, sort_order),
    CONSTRAINT fk_promotion_faqs_promo FOREIGN KEY (promo_id)
        REFERENCES promotions (promo_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotion_claims (
    claim_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promo_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (claim_id),
    UNIQUE KEY uq_promotion_claim_user (promo_id, user_id),
    KEY ix_promotion_claim_user (user_id, claimed_at),
    CONSTRAINT fk_promotion_claims_promo FOREIGN KEY (promo_id)
        REFERENCES promotions (promo_id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_claims_user FOREIGN KEY (user_id)
        REFERENCES user_info (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotion_eligible_items (
    promo_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (promo_id, item_id),
    KEY ix_promotion_eligible_items_item (item_id),
    CONSTRAINT fk_promotion_eligible_items_promo FOREIGN KEY (promo_id)
        REFERENCES promotions (promo_id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_eligible_items_item FOREIGN KEY (item_id)
        REFERENCES menu_items (item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(24) NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    fulfillment ENUM('delivery','pickup') NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    phone VARCHAR(13) DEFAULT NULL,
    delivery_address VARCHAR(750) DEFAULT NULL,
    delivery_instructions VARCHAR(500) DEFAULT NULL,
    scheduled_for DATETIME DEFAULT NULL,
    payment_method ENUM('cash','gcash','maya','card_pickup') NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    delivery_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    delivery_distance_km DECIMAL(8,2) DEFAULT NULL,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled','delivered','rejected','failed') NOT NULL DEFAULT 'pending',
    payment_status ENUM('awaiting','paid','cancelled') NOT NULL DEFAULT 'awaiting',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_customer_orders_number (order_number),
    KEY ix_customer_orders_user (user_id),
    KEY ix_customer_orders_branch (branch_id),
    KEY ix_customer_orders_status_created (status, created_at),
    KEY ix_customer_orders_created (created_at),
    CONSTRAINT fk_customer_orders_user FOREIGN KEY (user_id)
        REFERENCES user_info (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_customer_orders_branch FOREIGN KEY (branch_id)
        REFERENCES branches (branch_id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_orders_phone CHECK (phone IS NULL OR phone REGEXP '^(09[0-9]{9}|\\+639[0-9]{9})$'),
    CONSTRAINT chk_customer_orders_subtotal CHECK (subtotal >= 0),
    CONSTRAINT chk_customer_orders_delivery_fee CHECK (delivery_fee >= 0),
    CONSTRAINT chk_customer_orders_distance CHECK (delivery_distance_km IS NULL OR delivery_distance_km >= 0),
    CONSTRAINT chk_customer_orders_total CHECK (total >= 0),
    CONSTRAINT chk_customer_orders_delivery_address CHECK (fulfillment <> 'delivery' OR delivery_address IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_order_items (
    order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED DEFAULT NULL,
    item_name VARCHAR(255) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    special_request VARCHAR(500) DEFAULT NULL,
    line_total DECIMAL(12,2) AS (unit_price * quantity) PERSISTENT,
    PRIMARY KEY (order_item_id),
    KEY ix_customer_order_items_order (order_id),
    KEY ix_customer_order_items_item (item_id),
    CONSTRAINT fk_customer_order_items_order FOREIGN KEY (order_id)
        REFERENCES customer_orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_order_items_item FOREIGN KEY (item_id)
        REFERENCES menu_items (item_id) ON DELETE SET NULL,
    CONSTRAINT chk_customer_order_items_price CHECK (unit_price >= 0),
    CONSTRAINT chk_customer_order_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_customer_order_items_name CHECK (CHAR_LENGTH(TRIM(item_name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_order_item_options (
    order_item_option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED DEFAULT NULL,
    group_name_snapshot VARCHAR(100) NOT NULL,
    option_name_snapshot VARCHAR(255) NOT NULL,
    unit_price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    line_total DECIMAL(12,2) AS (unit_price_adjustment * quantity) PERSISTENT,
    PRIMARY KEY (order_item_option_id),
    KEY ix_customer_order_item_options_order_item (order_item_id),
    KEY ix_customer_order_item_options_option (option_id),
    CONSTRAINT fk_customer_order_item_options_order_item FOREIGN KEY (order_item_id)
        REFERENCES customer_order_items (order_item_id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_order_item_options_option FOREIGN KEY (option_id)
        REFERENCES menu_options (option_id) ON DELETE SET NULL,
    CONSTRAINT chk_customer_order_item_options_price CHECK (unit_price_adjustment >= 0),
    CONSTRAINT chk_customer_order_item_options_quantity CHECK (quantity > 0),
    CONSTRAINT chk_customer_order_item_options_name CHECK (CHAR_LENGTH(TRIM(option_name_snapshot)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotion_redemptions (
    redemption_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promo_id BIGINT UNSIGNED DEFAULT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    claim_id BIGINT UNSIGNED DEFAULT NULL,
    code_used VARCHAR(60) DEFAULT NULL,
    promo_name_snapshot VARCHAR(180) NOT NULL,
    promo_terms_snapshot LONGTEXT NOT NULL CHECK (JSON_VALID(promo_terms_snapshot)),
    discount_amount DECIMAL(10,2) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (redemption_id),
    UNIQUE KEY uq_promotion_redemptions_order (order_id),
    UNIQUE KEY uq_promotion_redemptions_claim (claim_id),
    KEY ix_promotion_redemptions_promo_date (promo_id, redeemed_at),
    KEY ix_promotion_redemptions_user (user_id, redeemed_at),
    CONSTRAINT fk_promotion_redemptions_promo FOREIGN KEY (promo_id)
        REFERENCES promotions (promo_id) ON DELETE SET NULL,
    CONSTRAINT fk_promotion_redemptions_order FOREIGN KEY (order_id)
        REFERENCES customer_orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_redemptions_user FOREIGN KEY (user_id)
        REFERENCES user_info (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_promotion_redemptions_claim FOREIGN KEY (claim_id)
        REFERENCES promotion_claims (claim_id) ON DELETE SET NULL,
    CONSTRAINT chk_promotion_redemptions_discount CHECK (discount_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_order_actions (
    action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('status_changed','cancelled') NOT NULL,
    from_status ENUM('pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled','delivered','rejected','failed') NOT NULL,
    to_status ENUM('pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled','delivered','rejected','failed') NOT NULL,
    cancellation_reason ENUM('customer_request','item_unavailable','payment_issue','duplicate_order','delivery_issue','other') DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_id),
    KEY ix_staff_order_actions_order (order_id, created_at),
    KEY ix_staff_order_actions_user (user_id, created_at),
    CONSTRAINT fk_staff_order_actions_order FOREIGN KEY (order_id)
        REFERENCES customer_orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_order_actions_user FOREIGN KEY (user_id)
        REFERENCES user_credentials (user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_staff_order_actions_reason CHECK (
        (event_type = 'cancelled' AND cancellation_reason IS NOT NULL)
        OR (event_type = 'status_changed' AND cancellation_reason IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_otp_challenges (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    purpose ENUM('registration','password_reset','account_deletion','email_change','login','password_change') NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    otp_hash CHAR(64) NOT NULL,
    registration_data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (JSON_VALID(registration_data)),
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (challenge_id),
    KEY ix_auth_otp_email_purpose_created (email, purpose, created_at),
    KEY ix_auth_otp_expiry (expires_at),
    KEY ix_auth_otp_user (user_id),
    CONSTRAINT fk_auth_otp_user FOREIGN KEY (user_id)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL,
    CONSTRAINT chk_auth_otp_attempts CHECK (attempts <= 10),
    CONSTRAINT chk_auth_otp_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_security_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    event_name ENUM('login','password_reset','email_change','registration','otp_request','otp_delivery','otp_verify','admin_password_reset','account_deletion') NOT NULL,
    purpose ENUM('registration','password_reset','account_deletion','email_change','login','password_change','password') DEFAULT NULL,
    outcome ENUM('success','completed','verification_started','rate_limited','cooldown','created','accepted_by_make','failed','expired','locked','invalid','password_changed','kept_existing_password') NOT NULL,
    details LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (JSON_VALID(details)),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY ix_auth_security_logs_created (created_at),
    KEY ix_auth_security_logs_user_created (user_id, created_at),
    CONSTRAINT fk_auth_security_logs_user FOREIGN KEY (user_id)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    email VARCHAR(255) NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    CONSTRAINT chk_login_attempts_count CHECK (attempt_count <= 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_subscriptions (
    subscription_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    consented_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at DATETIME DEFAULT NULL,
    unsubscribe_token_hash CHAR(64) NOT NULL,
    PRIMARY KEY (subscription_id),
    UNIQUE KEY uq_email_subscriptions_email (email),
    UNIQUE KEY uq_email_subscriptions_token (unsubscribe_token_hash),
    KEY ix_email_subscriptions_active (unsubscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE careers_applications (
    application_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purpose VARCHAR(80) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    position_applied VARCHAR(160) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    barangay_code CHAR(10) NOT NULL,
    zip_code CHAR(4) NOT NULL,
    email VARCHAR(255) NOT NULL,
    resume_path VARCHAR(255) NOT NULL,
    status ENUM('new','reviewing','interview','offered','hired','rejected','withdrawn') NOT NULL DEFAULT 'new',
    assigned_to BIGINT UNSIGNED DEFAULT NULL,
    staff_notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (application_id),
    KEY ix_careers_status_created (status, created_at),
    KEY ix_careers_assignee (assigned_to),
    KEY ix_careers_barangay (barangay_code),
    CONSTRAINT fk_careers_barangay FOREIGN KEY (barangay_code)
        REFERENCES psgc_barangays (psgc_code) ON DELETE RESTRICT,
    CONSTRAINT chk_careers_zip CHECK (zip_code REGEXP '^[0-9]{4}$'),
    CONSTRAINT fk_careers_assigned_to FOREIGN KEY (assigned_to)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_submissions (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    subject VARCHAR(80) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new','open','waiting','resolved','closed') NOT NULL DEFAULT 'new',
    priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
    assigned_to BIGINT UNSIGNED DEFAULT NULL,
    staff_notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (contact_id),
    KEY ix_contact_status_created (status, created_at),
    KEY ix_contact_assignee (assigned_to),
    CONSTRAINT fk_contact_assigned_to FOREIGN KEY (assigned_to)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_inbox_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type ENUM('branch_added','career_application','customer_feedback','promo_created','promo_updated','product_created','product_updated') NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    audience ENUM('all','staff','admin') NOT NULL DEFAULT 'all',
    entity_type VARCHAR(40) DEFAULT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    KEY ix_team_events_created (created_at),
    KEY ix_team_events_audience_created (audience, created_at),
    KEY ix_team_events_creator (created_by),
    CONSTRAINT fk_team_inbox_events_creator FOREIGN KEY (created_by)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_inbox_reads (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    KEY ix_team_inbox_reads_user (user_id),
    CONSTRAINT fk_team_inbox_reads_event FOREIGN KEY (event_id)
        REFERENCES team_inbox_events (event_id) ON DELETE CASCADE,
    CONSTRAINT fk_team_inbox_reads_user FOREIGN KEY (user_id)
        REFERENCES user_credentials (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    before_data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (JSON_VALID(before_data)),
    after_data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (JSON_VALID(after_data)),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_audit_entity (entity_type, entity_id, created_at),
    KEY ix_audit_actor (actor_user_id, created_at),
    KEY ix_audit_created (created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id)
        REFERENCES user_credentials (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER menu_items_inbox_insert AFTER INSERT ON menu_items FOR EACH ROW
BEGIN
    INSERT INTO team_inbox_events (event_type, title, message, audience, entity_type, entity_id)
    VALUES ('product_created', CONCAT('New menu item: ', NEW.name), CONCAT(NEW.name, ' was added to the menu.'), 'all', 'menu_item', NEW.item_id);
END$$

CREATE TRIGGER menu_items_inbox_update AFTER UPDATE ON menu_items FOR EACH ROW
BEGIN
    IF NOT (OLD.name <=> NEW.name)
        OR NOT (OLD.description <=> NEW.description)
        OR NOT (OLD.price <=> NEW.price)
        OR NOT (OLD.is_available <=> NEW.is_available)
        OR NOT (OLD.publish_at <=> NEW.publish_at) THEN
        INSERT INTO team_inbox_events (event_type, title, message, audience, entity_type, entity_id)
        VALUES ('product_updated', CONCAT('Menu updated: ', NEW.name), CONCAT(NEW.name, ' details or availability were updated.'), 'all', 'menu_item', NEW.item_id);
    END IF;
END$$
DELIMITER ;
