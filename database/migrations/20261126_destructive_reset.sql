-- DESTRUCTIVE RESET for the normalized schema.
-- Permanently drops all Nomcha tables and every row in them.
-- Confirm that a restorable backup exists before running this in phpMyAdmin.
-- This script contains no seed or demo records.
-- After it completes, import database/schema.sql, then visit setup.php.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS
    audit_logs,
    auth_otp_challenges,
    auth_security_logs,
    branch_staff,
    branches,
    careers_applications,
    contact_submissions,
    customer_order_item_options,
    customer_order_items,
    customer_orders,
    email_subscriptions,
    login_attempts,
    menu_item_option_choices,
    menu_item_option_groups,
    menu_options,
    menu_option_groups,
    menu_items,
    menu_categories,
    promotion_faqs,
    promotion_eligible_items,
    promotion_redemptions,
    promotion_claims,
    promotions,
    psgc_barangays,
    psgc_localities,
    psgc_provinces,
    psgc_regions,
    staff_order_actions,
    team_inbox_reads,
    team_inbox_events,
    user_addresses,
    user_credentials,
    user_info,
    billing_addresses;

SET FOREIGN_KEY_CHECKS = 1;
