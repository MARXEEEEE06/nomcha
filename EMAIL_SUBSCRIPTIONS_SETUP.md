# Nomcha email subscriptions

## Database

Back up `nomcha_db` and apply the migration files in date order if they have not already been applied. The new `20261116_email_subscriptions.sql` migration creates the consent and unsubscribe records. The existing delivery eligibility migration adds `user_credentials.created_at`; older accounts remain `NULL` and do not qualify for the new-account delivery offer.

## Make and email delivery

The site sends JSON to the existing Make custom webhook configured in `includes/local_config.php`. Set `make_webhook_url`, an optional `make_webhook_token`, the provider verified sender address/name, and `site_base_url` to the site's public HTTPS origin. Keep the real `local_config.php` private; do not commit it.

In Make, accept the `notification` route and map `to`, `from_email`, `from_name`, `subject`, and `html` (or `text`) into the email provider's send-email action. Accept at least these purposes: `subscription_welcome`, `promo_notification`, and `menu_event_notification`. Preserve HTML links in the `html` field so the unsubscribe action is clickable. Keep the existing `auth` route for OTP mail. Restrict webhook access with the configured API key where available, then send a test subscription to an address you control.

Authenticate the sending domain at the email provider by publishing the SPF and DKIM DNS records it supplies. Publish DMARC for the domain as well; start with the provider's recommended monitoring policy and tighten it after confirming legitimate mail passes. A verified sender and domain authentication are required for reliable delivery.

## Behavior and operations

The footer checkbox records explicit marketing consent. New or re-subscribing addresses get a welcome email confirming signup and containing an unsubscribe link. Promotional, voucher, and menu availability emails go only to active email subscriptions. Each such email carries its own unsubscribe link. Account and OTP messages remain transactional.

Notifications are sent synchronously when an admin activates an offer or makes a menu item available. No cron job or queue is required for a small list. For a larger list, move delivery into a durable queue/worker so slow provider calls do not hold up admin requests and failed sends can be retried. Check the PHP error log for provider failures.

The free-delivery benefit is automatic at checkout for delivery orders with an item subtotal of at least ₱400 when the customer account is less than one calendar month old. No voucher claim is needed. Accounts with unknown creation dates do not qualify.
