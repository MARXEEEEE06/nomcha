<?php
// Copy this file to local_config.php and replace the values.
return [
    // Local-only convenience for testing. Keep false on any public deployment.
    'allow_test_admin_login' => false,
    // Paste the Custom Webhook URL from your Make scenario.
    'make_webhook_url' => '',
    // Optional: set the same API key in Make's webhook authentication settings.
    'make_webhook_token' => '',
    'mail_from_email' => 'no-reply@example.com',
    'mail_from_name' => 'Nomcha',
    // Public origin for links in customer emails, with no trailing slash (for example https://nomcha.example).
    'site_base_url' => '',
    // Use a long random secret. Keep this private and stable after OTPs are issued.
    'otp_pepper' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',
];
