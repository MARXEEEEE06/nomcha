# Database and hosting configuration

## Local XAMPP

Copy `.env.example` to `.env` and set local database values. `127.0.0.1` works for requests from this computer. To open the site from another device on the same network, use this computer's LAN IP in the browser (for example `http://192.168.1.20/nomcha`) and allow Apache through the firewall. The browser address does not change the PHP database host: PHP still connects to the local MySQL service at `127.0.0.1`.

## Aiven MySQL

For local or other hosting, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` from the Aiven service connection details. Set `DB_SSL_CA` to the path of Aiven's CA certificate. Keep the `.env` file and certificate private; do not commit either. The app also accepts the CA certificate PEM contents directly in `DB_SSL_CA` and writes them to a temporary file for PDO.

## Aiven application deployment

The repository includes a `Dockerfile` for the PHP application. In Aiven, select the repository and branch containing this file and deploy it as a container. The image runs Apache on port `8080` and includes PHP 8.2 with `pdo_mysql` enabled. Configure the application port as HTTP `8080`. In **Connected services**, connect the MySQL service; Aiven provides its `DATABASE_URL`, which the app can read directly. The app parses its host, port, database, username, and password. Explicit `DB_*` values take precedence when present. For connections using certificate verification, set `DB_SSL_CA` to the CA PEM contents or to a certificate path inside the container. Do not put credentials in the Dockerfile.

The app writes career resumes under `uploads/resumes`. Container filesystems may be replaced during redeploys, so attach persistent storage at `/var/www/html/uploads` if uploaded resumes must survive deployments. Menu images stored in the database do not need this volume.

## Netlify

The app reads the same settings from Netlify environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSL_CA`, `SITE_BASE_URL`, `OTP_PEPPER`, and optional mail/webhook settings). Add them in the Netlify site's environment variable settings; do not commit secrets or put them in `netlify.toml`.

Netlify does not run PHP files as a web application at request time. This repository uses PHP for page rendering and server-side features, so configuring environment variables alone does not make the complete app deployable to Netlify. Netlify can host a static export, but the PHP app needs a PHP-capable web host unless its server-side features are rewritten for a supported runtime.
