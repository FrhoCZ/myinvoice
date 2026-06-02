<?php
/**
 * MyInvoice.cz — Azure Functions / App Service configuration.
 *
 * All values are read from environment variables so that no secrets are stored
 * in source code. Configure them as Application Settings in the Azure portal
 * (or via `az functionapp config appsettings set`).
 *
 * This file is auto-linked as cfg.php by azure-start.sh when cfg.php is absent.
 * You can also deploy it as cfg.php directly.
 *
 * Required environment variables
 * ───────────────────────────────
 *   MYINVOICE_APP_URL       — public HTTPS URL (e.g. https://my-app.azurewebsites.net)
 *   MYINVOICE_APP_PEPPER    — 32-byte base64 pepper (openssl rand -base64 32)
 *   MYINVOICE_DB_HOST       — Azure Database for MySQL / MariaDB hostname
 *   MYINVOICE_DB_NAME       — database name
 *   MYINVOICE_DB_USER       — database user  (format: user@servername on Azure)
 *   MYINVOICE_DB_PASS       — database password
 *   MYINVOICE_SMTP_HOST     — SMTP relay host
 *   MYINVOICE_SMTP_USER     — SMTP username
 *   MYINVOICE_SMTP_PASS     — SMTP password
 *   MYINVOICE_SMTP_FROM     — sender email address
 *
 * Optional environment variables (sensible defaults provided)
 * ────────────────────────────────────────────────────────────
 *   MYINVOICE_APP_ENV               — 'production' (default) | 'development'
 *   MYINVOICE_APP_SECRET_KEY        — 32-byte base64 AES-256-GCM key for TOTP
 *   MYINVOICE_DB_PORT               — database port (default: 3306)
 *   MYINVOICE_DB_SSL_CA             — path to SSL CA bundle for Azure MySQL
 *                                     (download DigiCertGlobalRootG2.crt.pem)
 *   MYINVOICE_REDIS_HOST            — Azure Cache for Redis hostname
 *   MYINVOICE_REDIS_PORT            — Redis port (default: 6380 for Azure TLS)
 *   MYINVOICE_REDIS_AUTH            — Redis access key
 *   MYINVOICE_SMTP_PORT             — SMTP port (default: 587)
 *   MYINVOICE_SMTP_FROM_NAME        — sender display name (default: MyInvoice)
 *   MYINVOICE_STORAGE_INVOICES      — PDF output dir   (default: /tmp/myinvoice/invoices)
 *   MYINVOICE_STORAGE_UPLOADS       — upload dir       (default: /tmp/myinvoice/uploads)
 *   MYINVOICE_STORAGE_BACKUP        — backup dir       (default: /tmp/myinvoice/backup)
 *   MYINVOICE_STORAGE_SESSIONS      — sessions dir     (default: /tmp/myinvoice/sessions)
 *   MYINVOICE_STORAGE_CACHE         — cache dir        (default: /tmp/myinvoice/cache)
 *   MYINVOICE_LOG_PATH              — log file path    (default: /tmp/myinvoice/log/app.log)
 *   MYINVOICE_TURNSTILE_SITE_KEY    — Cloudflare Turnstile public key
 *   MYINVOICE_TURNSTILE_SECRET_KEY  — Cloudflare Turnstile server key
 */

declare(strict_types=1);

/** Read a required env var; throw a clear error if missing. */
$require = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new \RuntimeException("Required environment variable '{$name}' is not set.");
    }
    return $value;
};

/** Read an optional env var with a typed default. */
$opt = static function (string $name, mixed $default = null): mixed {
    $value = getenv($name);
    return ($value !== false && $value !== '') ? $value : $default;
};

$env = $opt('MYINVOICE_APP_ENV', 'production');

// Redis is enabled automatically when MYINVOICE_REDIS_HOST is set.
$redisHost = (string) $opt('MYINVOICE_REDIS_HOST', '');
$redisEnabled = $redisHost !== '';

return [
    'app' => [
        'env'                  => $env,
        'debug'                => $env === 'development',
        'url'                  => $require('MYINVOICE_APP_URL'),
        'pepper'               => $require('MYINVOICE_APP_PEPPER'),
        'secret_encryption_key'=> $opt('MYINVOICE_APP_SECRET_KEY', ''),
        'timezone'             => $opt('MYINVOICE_TIMEZONE', 'Europe/Prague'),
        'locale_default'       => $opt('MYINVOICE_LOCALE', 'cs'),
    ],
    'db' => [
        'host'    => $require('MYINVOICE_DB_HOST'),
        'port'    => (int) $opt('MYINVOICE_DB_PORT', 3306),
        'name'    => $require('MYINVOICE_DB_NAME'),
        'user'    => $require('MYINVOICE_DB_USER'),
        'pass'    => $require('MYINVOICE_DB_PASS'),
        'charset' => 'utf8mb4',
        'socket'  => null,
        // Azure Database for MySQL enforces SSL. Provide the path to the
        // DigiCert root CA bundle (DigiCertGlobalRootG2.crt.pem).
        'ssl_ca'                => $opt('MYINVOICE_DB_SSL_CA', ''),
        'dump_tool'             => '',
        'backup_skip_routines'  => true,
    ],
    'redis' => [
        'enabled' => $redisEnabled,
        'host'    => $redisHost,
        'port'    => (int) $opt('MYINVOICE_REDIS_PORT', 6380),
        'auth'    => $opt('MYINVOICE_REDIS_AUTH', null),
        'db'      => 0,
        'prefix'  => 'myinvoice:prod:',
        // Azure Cache for Redis uses TLS on port 6380 by default.
        'tls'     => ((int) $opt('MYINVOICE_REDIS_PORT', 6380)) === 6380,
    ],
    'session' => [
        'driver'         => $redisEnabled ? 'redis' : 'db',
        'lifetime_days'  => 30,
        'cookie_name'    => $opt('MYINVOICE_SESSION_COOKIE_NAME', '__Host-myinvoice_session'),
        'cookie_secure'  => true,
        'cookie_samesite'=> 'Lax',
    ],
    'smtp' => [
        'host'             => $require('MYINVOICE_SMTP_HOST'),
        'port'             => (int) $opt('MYINVOICE_SMTP_PORT', 587),
        'encryption'       => $opt('MYINVOICE_SMTP_ENCRYPTION', 'tls'),
        'auth_enabled'     => true,
        'auth_type'        => $opt('MYINVOICE_SMTP_AUTH_TYPE', 'LOGIN'),
        'user'             => $require('MYINVOICE_SMTP_USER'),
        'pass'             => $require('MYINVOICE_SMTP_PASS'),
        'oauth' => [
            'provider'      => null,
            'client_id'     => '',
            'client_secret' => '',
            'refresh_token' => '',
        ],
        'from_email'       => $require('MYINVOICE_SMTP_FROM'),
        'from_name'        => $opt('MYINVOICE_SMTP_FROM_NAME', 'MyInvoice'),
        'reply_to_email'   => '',
        'reply_to_name'    => '',
        'cc_supplier_on_send'     => false,
        'cc_supplier_on_reminder' => false,
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'allow_self_signed'=> false,
        'timeout'          => 30,
        'keepalive'        => false,
        'charset'          => 'UTF-8',
        'encoding'         => '8bit',
        'wordwrap'         => 78,
        'dkim'             => ['enabled' => false],
        'debug_level'      => 0,
        'debug_log_file'   => '',
        'max_retries'      => 3,
        'retry_delay_s'    => 60,
    ],
    'ares' => [
        'api'       => 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty',
        'cache_ttl' => 86400,
        'timeout'   => 5,
    ],
    'vies' => [
        'rest_api'  => 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms',
        'wsdl'      => 'http://ec.europa.eu/taxation_customs/vies/services/checkVatService.wsdl',
        'cache_ttl' => 10800,
        'timeout'   => 8,
    ],
    'logging' => [
        'level'     => $opt('MYINVOICE_LOG_LEVEL', 'info'),
        'path'      => $opt('MYINVOICE_LOG_PATH', '/tmp/myinvoice/log/app.log'),
        'max_files' => 7,
    ],
    'storage' => [
        'invoices_dir' => $opt('MYINVOICE_STORAGE_INVOICES', '/tmp/myinvoice/invoices'),
        'uploads_dir'  => $opt('MYINVOICE_STORAGE_UPLOADS',  '/tmp/myinvoice/uploads'),
        'backup_dir'   => $opt('MYINVOICE_STORAGE_BACKUP',   '/tmp/myinvoice/backup'),
        'sessions_dir' => $opt('MYINVOICE_STORAGE_SESSIONS', '/tmp/myinvoice/sessions'),
        'cache_dir'    => $opt('MYINVOICE_STORAGE_CACHE',    '/tmp/myinvoice/cache'),
    ],
    'qr' => [
        'czk_constant_symbol' => '0308',
    ],
    'pagination' => [
        'invoices_per_page' => 50,
        'clients_per_page'  => 50,
        'projects_per_page' => 50,
    ],
    'varsymbol' => [
        'templates' => [
            'invoice'     => '{YY}{MM}{CCC}',
            'proforma'    => '9{YY}{MM}{CCC}',
            'credit_note' => '7{YY}{MM}{CCC}',
        ],
    ],
    'rate_limits' => [
        'login_per_min_per_ip'      => 10,
        'forgot_per_hour_per_email' => 3,
        'mutation_per_min_per_user' => 60,
        'read_per_min_per_user'     => 300,
        'ares_per_min_per_user'     => 30,
        'setup_per_hour_per_ip'     => 5,
    ],
    'brute_force' => [
        'captcha_after'  => 5,
        'lockout_15m_at' => 10,
        'lockout_24h_at' => 30,
        'window_seconds' => [300, 900, 3600],
    ],
    'captcha' => [
        'provider'    => $opt('MYINVOICE_TURNSTILE_SITE_KEY', '') !== '' ? 'turnstile' : 'none',
        'site_key'    => $opt('MYINVOICE_TURNSTILE_SITE_KEY', ''),
        'secret_key'  => $opt('MYINVOICE_TURNSTILE_SECRET_KEY', ''),
        'verify_url'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'script_url'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'timeout'     => 5,
        'fail_open'   => true,
        'action'      => 'login',
    ],
    'approval' => [
        'token_ttl_days'                   => 30,
        'reminder_after_days'              => 5,
        'max_reminders'                    => 3,
        'cc_supplier_on_approval'          => true,
        'cc_supplier_on_approval_reminder' => true,
    ],
    'ip_allowlist' => [
        'enabled'        => false,
        'mode'           => 'block',
        'allow'          => [],
        'apply_to'       => 'all',
        'trusted_proxies'=> [],
        'header'         => 'X-Forwarded-For',
    ],
    'bank_import' => [
        'scan_root'                => '',
        'allowed_exts'            => ['gpc', 'txt'],
        'auto_match'              => true,
        'partial_match_tolerance' => 1.00,
    ],
    'cron' => [
        'cleanup' => [
            'login_attempts_hours' => 24,
            'password_resets_days' => 7,
            'cache_ttl_days'       => 30,
            'pdf_cache_days'       => 90,
        ],
        'backup' => [
            'daily_retention_days'   => 7,
            'monthly_retention_days' => 90,
            'output_dir'             => '/tmp/myinvoice/backup',
        ],
    ],
];
