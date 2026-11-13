# Connect Nomcha OTP emails to Make

Nomcha generates and checks the six-digit codes. Make only receives the email content and passes it to your email-sending module. Codes expire after five minutes.

## 1. Prepare the database

In phpMyAdmin, select `nomcha_db`, open the **Import** tab, and apply the OTP migrations in date order, including `database/migrations/20261116_login_otp.sql` to enable OTP login.

## 2. Create the Make scenario

1. Create a scenario and add **Webhooks > Custom webhook** as its first module.
2. Create the webhook. In its settings, add an API key if available; the app sends it in the `x-make-apikey` header.
3. Copy the webhook URL.
4. Add one Gmail module after the webhook and filter the scenario for `route` equal to `auth`. Use this same module for registration, account deletion, email changes, password reset, and login codes.
5. Map the webhook values into the email module:
   - Recipient: `to`
   - Subject: `subject`
   - Email content: use `html` for HTML content, or `text` for plain text
   - Sender: configure a sender address accepted by your connected email account
6. In the scenario settings, enable **Keep data confidential**. OTPs and recipient addresses are included in webhook data. Make says confidential mode hides processed payloads from execution logs; webhook data is still briefly stored in its queue until processing finishes.
7. Run the scenario once to capture its fields, then turn it on.

The webhook's immediate `200 Accepted` response means Make accepted or queued the request. It does not confirm that the email module delivered the message. Watch the scenario run history for delivery errors. Keep confidential mode enabled and avoid leaving failed runs containing OTP payloads available for manual replay.

## 3. Configure Nomcha

1. Copy `includes/local_config.example.php` to `includes/local_config.php`.
2. Put the copied webhook URL in `make_webhook_url`.
3. If you enabled webhook API key authentication, put the same value in `make_webhook_token`.
4. Set `mail_from_email` and `mail_from_name` to a sender identity accepted by your Make email connection.
5. Replace the example `otp_pepper` with a long random secret and keep it private and unchanged. This secret is used to hash codes before they are stored.

`includes/local_config.php` is excluded from Git so the webhook URL and secret stay local. Do not commit it or paste credentials into chat.

## Payload fields

OTP webhook requests use `purpose: otp` and `route: auth`; the payload retains `purpose` for compatibility, but Make routing should use `route`. The webhook receives `to`, `from_email`, `from_name`, `subject`, `text`, `html`, and `purpose`. It does not receive the OTP hash or account password. The plain OTP is included only because the email scenario must deliver it to the recipient.

Nomcha records challenge requests, Make acceptance/failure, verification outcomes, and password changes in `auth_security_logs`. It does not log the plain OTP.
