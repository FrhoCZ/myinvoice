<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for cfg.azure.php — the Azure Functions / App Service configuration
 * file that drives all app settings from environment variables.
 *
 * Strategy: for each test, set the required (and any relevant optional) env
 * vars via putenv(), require the file to get the config array, then assert on
 * the returned values.  setUp/tearDown cleans up all MYINVOICE_* vars to
 * prevent cross-test contamination.
 */
final class AzureConfigTest extends TestCase
{
    /** Absolute path to cfg.azure.php (repo root, two levels above api/). */
    private string $cfgFile;

    /** Env var names touched during tests — reset in tearDown. */
    private const ENV_VARS = [
        'MYINVOICE_APP_URL',
        'MYINVOICE_APP_PEPPER',
        'MYINVOICE_APP_ENV',
        'MYINVOICE_APP_SECRET_KEY',
        'MYINVOICE_TIMEZONE',
        'MYINVOICE_LOCALE',
        'MYINVOICE_DB_HOST',
        'MYINVOICE_DB_NAME',
        'MYINVOICE_DB_USER',
        'MYINVOICE_DB_PASS',
        'MYINVOICE_DB_PORT',
        'MYINVOICE_DB_SSL_CA',
        'MYINVOICE_REDIS_HOST',
        'MYINVOICE_REDIS_PORT',
        'MYINVOICE_REDIS_AUTH',
        'MYINVOICE_SMTP_HOST',
        'MYINVOICE_SMTP_PORT',
        'MYINVOICE_SMTP_ENCRYPTION',
        'MYINVOICE_SMTP_AUTH_TYPE',
        'MYINVOICE_SMTP_USER',
        'MYINVOICE_SMTP_PASS',
        'MYINVOICE_SMTP_FROM',
        'MYINVOICE_SMTP_FROM_NAME',
        'MYINVOICE_STORAGE_INVOICES',
        'MYINVOICE_STORAGE_UPLOADS',
        'MYINVOICE_STORAGE_BACKUP',
        'MYINVOICE_STORAGE_SESSIONS',
        'MYINVOICE_STORAGE_CACHE',
        'MYINVOICE_LOG_PATH',
        'MYINVOICE_LOG_LEVEL',
        'MYINVOICE_TURNSTILE_SITE_KEY',
        'MYINVOICE_TURNSTILE_SECRET_KEY',
        'MYINVOICE_SESSION_COOKIE_NAME',
    ];

    /** Saved originals so we can restore them in tearDown. */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        // cfg.azure.php lives in the repository root (two levels above api/)
        $this->cfgFile = dirname(__DIR__, 5) . '/cfg.azure.php';
        self::assertFileExists($this->cfgFile, 'cfg.azure.php must exist in the repo root');

        // Snapshot current values (false = not set)
        foreach (self::ENV_VARS as $var) {
            $this->originalEnv[$var] = getenv($var);
        }

        // Clear all known vars to start from a clean slate
        foreach (self::ENV_VARS as $var) {
            putenv($var); // unsets the var
        }
    }

    protected function tearDown(): void
    {
        // Restore original env state
        foreach ($this->originalEnv as $var => $original) {
            if ($original === false) {
                putenv($var); // unset
            } else {
                putenv("{$var}={$original}");
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Set every required env var to a safe test value. */
    private function setRequiredVars(array $overrides = []): void
    {
        $defaults = [
            'MYINVOICE_APP_URL'    => 'https://test.azurewebsites.net',
            'MYINVOICE_APP_PEPPER' => base64_encode(str_repeat('x', 32)),
            'MYINVOICE_DB_HOST'    => 'myinvoice-db.mysql.database.azure.com',
            'MYINVOICE_DB_NAME'    => 'myinvoice',
            'MYINVOICE_DB_USER'    => 'myinvoiceadmin',
            'MYINVOICE_DB_PASS'    => 'TestPassword123!',
            'MYINVOICE_SMTP_HOST'  => 'smtp.example.com',
            'MYINVOICE_SMTP_USER'  => 'noreply@example.com',
            'MYINVOICE_SMTP_PASS'  => 'smtppassword',
            'MYINVOICE_SMTP_FROM'  => 'noreply@example.com',
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    /** Require cfg.azure.php and return the config array. */
    private function loadConfig(): array
    {
        $config = require $this->cfgFile;
        self::assertIsArray($config, 'cfg.azure.php must return an array');
        return $config;
    }

    // ── required variable validation ──────────────────────────────────────────

    /** @dataProvider requiredVarProvider */
    public function testMissingRequiredVarThrowsRuntimeException(string $varName): void
    {
        $this->setRequiredVars();
        putenv($varName); // unset one required var

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/'{$varName}'/");

        $this->loadConfig();
    }

    /** @return iterable<string, array{string}> */
    public static function requiredVarProvider(): iterable
    {
        yield 'MYINVOICE_APP_URL'    => ['MYINVOICE_APP_URL'];
        yield 'MYINVOICE_APP_PEPPER' => ['MYINVOICE_APP_PEPPER'];
        yield 'MYINVOICE_DB_HOST'    => ['MYINVOICE_DB_HOST'];
        yield 'MYINVOICE_DB_NAME'    => ['MYINVOICE_DB_NAME'];
        yield 'MYINVOICE_DB_USER'    => ['MYINVOICE_DB_USER'];
        yield 'MYINVOICE_DB_PASS'    => ['MYINVOICE_DB_PASS'];
        yield 'MYINVOICE_SMTP_HOST'  => ['MYINVOICE_SMTP_HOST'];
        yield 'MYINVOICE_SMTP_USER'  => ['MYINVOICE_SMTP_USER'];
        yield 'MYINVOICE_SMTP_PASS'  => ['MYINVOICE_SMTP_PASS'];
        yield 'MYINVOICE_SMTP_FROM'  => ['MYINVOICE_SMTP_FROM'];
    }

    public function testEmptyRequiredVarThrowsRuntimeException(): void
    {
        $this->setRequiredVars(['MYINVOICE_APP_URL' => '']);
        // putenv with empty string still counts as "set" by the OS but the
        // $require closure treats empty string as missing
        putenv('MYINVOICE_APP_URL='); // set to empty

        $this->expectException(RuntimeException::class);
        $this->loadConfig();
    }

    // ── required var values are reflected in the array ────────────────────────

    public function testRequiredVarsAreReflectedInReturnedArray(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('https://test.azurewebsites.net', $cfg['app']['url']);
        self::assertSame('myinvoice-db.mysql.database.azure.com', $cfg['db']['host']);
        self::assertSame('myinvoice', $cfg['db']['name']);
        self::assertSame('myinvoiceadmin', $cfg['db']['user']);
        self::assertSame('TestPassword123!', $cfg['db']['pass']);
        self::assertSame('smtp.example.com', $cfg['smtp']['host']);
        self::assertSame('noreply@example.com', $cfg['smtp']['user']);
        self::assertSame('noreply@example.com', $cfg['smtp']['from_email']);
    }

    // ── optional var defaults ─────────────────────────────────────────────────

    public function testDefaultsWhenOptionalVarsAreAbsent(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        // app
        self::assertSame('production', $cfg['app']['env']);
        self::assertFalse($cfg['app']['debug']);
        self::assertSame('', $cfg['app']['secret_encryption_key']);
        self::assertSame('Europe/Prague', $cfg['app']['timezone']);
        self::assertSame('cs', $cfg['app']['locale_default']);

        // db
        self::assertSame(3306, $cfg['db']['port']);
        self::assertSame('utf8mb4', $cfg['db']['charset']);
        self::assertSame('', $cfg['db']['ssl_ca']);

        // redis
        self::assertFalse($cfg['redis']['enabled']);
        self::assertSame('', $cfg['redis']['host']);
        self::assertSame(6380, $cfg['redis']['port']);
        self::assertNull($cfg['redis']['auth']);
        self::assertTrue($cfg['redis']['tls']); // port 6380 → tls=true by default

        // session
        self::assertSame('db', $cfg['session']['driver']); // no Redis → db
        self::assertSame('__Host-myinvoice_session', $cfg['session']['cookie_name']);
        self::assertTrue($cfg['session']['cookie_secure']);

        // smtp
        self::assertSame(587, $cfg['smtp']['port']);
        self::assertSame('tls', $cfg['smtp']['encryption']);
        self::assertSame('LOGIN', $cfg['smtp']['auth_type']);
        self::assertSame('MyInvoice', $cfg['smtp']['from_name']);

        // logging
        self::assertSame('info', $cfg['logging']['level']);
        self::assertSame('/tmp/myinvoice/log/app.log', $cfg['logging']['path']);

        // storage
        self::assertSame('/tmp/myinvoice/invoices', $cfg['storage']['invoices_dir']);
        self::assertSame('/tmp/myinvoice/uploads',  $cfg['storage']['uploads_dir']);
        self::assertSame('/tmp/myinvoice/backup',   $cfg['storage']['backup_dir']);
        self::assertSame('/tmp/myinvoice/sessions', $cfg['storage']['sessions_dir']);
        self::assertSame('/tmp/myinvoice/cache',    $cfg['storage']['cache_dir']);

        // captcha — no keys set → provider = 'none'
        self::assertSame('none', $cfg['captcha']['provider']);
        self::assertSame('', $cfg['captcha']['site_key']);
        self::assertSame('', $cfg['captcha']['secret_key']);
    }

    // ── environment override tests ────────────────────────────────────────────

    public function testDevelopmentEnvEnablesDebug(): void
    {
        $this->setRequiredVars(['MYINVOICE_APP_ENV' => 'development']);
        $cfg = $this->loadConfig();

        self::assertSame('development', $cfg['app']['env']);
        self::assertTrue($cfg['app']['debug']);
    }

    public function testProductionEnvDisablesDebug(): void
    {
        $this->setRequiredVars(['MYINVOICE_APP_ENV' => 'production']);
        $cfg = $this->loadConfig();

        self::assertFalse($cfg['app']['debug']);
    }

    public function testCustomTimezoneIsApplied(): void
    {
        $this->setRequiredVars(['MYINVOICE_TIMEZONE' => 'UTC']);
        $cfg = $this->loadConfig();

        self::assertSame('UTC', $cfg['app']['timezone']);
    }

    public function testCustomLocaleIsApplied(): void
    {
        $this->setRequiredVars(['MYINVOICE_LOCALE' => 'en']);
        $cfg = $this->loadConfig();

        self::assertSame('en', $cfg['app']['locale_default']);
    }

    // ── SSL CA configuration ──────────────────────────────────────────────────

    public function testSslCaIsPassedThroughToDbConfig(): void
    {
        $caPath = '/home/site/wwwroot/DigiCertGlobalRootG2.crt.pem';
        $this->setRequiredVars(['MYINVOICE_DB_SSL_CA' => $caPath]);
        $cfg = $this->loadConfig();

        self::assertSame($caPath, $cfg['db']['ssl_ca']);
    }

    public function testSslCaDefaultsToEmptyString(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('', $cfg['db']['ssl_ca']);
    }

    public function testDbPortCanBeOverridden(): void
    {
        $this->setRequiredVars(['MYINVOICE_DB_PORT' => '3307']);
        $cfg = $this->loadConfig();

        self::assertSame(3307, $cfg['db']['port']);
        self::assertIsInt($cfg['db']['port']);
    }

    // ── Redis configuration ───────────────────────────────────────────────────

    public function testRedisEnabledWhenHostIsSet(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_REDIS_HOST' => 'myinvoice-redis.redis.cache.windows.net',
            'MYINVOICE_REDIS_AUTH' => 'some-access-key',
        ]);
        $cfg = $this->loadConfig();

        self::assertTrue($cfg['redis']['enabled']);
        self::assertSame('myinvoice-redis.redis.cache.windows.net', $cfg['redis']['host']);
        self::assertSame('some-access-key', $cfg['redis']['auth']);
    }

    public function testRedisDisabledWhenHostIsAbsent(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertFalse($cfg['redis']['enabled']);
        self::assertSame('', $cfg['redis']['host']);
    }

    public function testRedisTlsEnabledOnDefaultPort6380(): void
    {
        $this->setRequiredVars(['MYINVOICE_REDIS_HOST' => 'redis.example.com']);
        $cfg = $this->loadConfig();

        self::assertSame(6380, $cfg['redis']['port']);
        self::assertTrue($cfg['redis']['tls']);
    }

    public function testRedisTlsDisabledOnNonTlsPort(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_REDIS_HOST' => 'redis.example.com',
            'MYINVOICE_REDIS_PORT' => '6379',
        ]);
        $cfg = $this->loadConfig();

        self::assertSame(6379, $cfg['redis']['port']);
        self::assertFalse($cfg['redis']['tls']);
    }

    // ── Session driver follows Redis availability ─────────────────────────────

    public function testSessionDriverIsRedisWhenRedisEnabled(): void
    {
        $this->setRequiredVars(['MYINVOICE_REDIS_HOST' => 'redis.example.com']);
        $cfg = $this->loadConfig();

        self::assertSame('redis', $cfg['session']['driver']);
    }

    public function testSessionDriverIsDbWhenRedisDisabled(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('db', $cfg['session']['driver']);
    }

    public function testCustomSessionCookieName(): void
    {
        $this->setRequiredVars(['MYINVOICE_SESSION_COOKIE_NAME' => '__Secure-custom_session']);
        $cfg = $this->loadConfig();

        self::assertSame('__Secure-custom_session', $cfg['session']['cookie_name']);
    }

    // ── Captcha / Turnstile ───────────────────────────────────────────────────

    public function testCaptchaProviderIsTurnstileWhenSiteKeyIsSet(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_TURNSTILE_SITE_KEY'   => '0x4AAAAAAA_test_site_key',
            'MYINVOICE_TURNSTILE_SECRET_KEY' => '0x4AAAAAAA_test_secret_key',
        ]);
        $cfg = $this->loadConfig();

        self::assertSame('turnstile', $cfg['captcha']['provider']);
        self::assertSame('0x4AAAAAAA_test_site_key', $cfg['captcha']['site_key']);
        self::assertSame('0x4AAAAAAA_test_secret_key', $cfg['captcha']['secret_key']);
    }

    public function testCaptchaProviderIsNoneWhenNoSiteKey(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('none', $cfg['captcha']['provider']);
    }

    // ── Storage directory overrides ───────────────────────────────────────────

    public function testStorageDirectoriesCanBeOverridden(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_STORAGE_INVOICES' => '/custom/invoices',
            'MYINVOICE_STORAGE_UPLOADS'  => '/custom/uploads',
            'MYINVOICE_STORAGE_BACKUP'   => '/custom/backup',
            'MYINVOICE_STORAGE_SESSIONS' => '/custom/sessions',
            'MYINVOICE_STORAGE_CACHE'    => '/custom/cache',
        ]);
        $cfg = $this->loadConfig();

        self::assertSame('/custom/invoices', $cfg['storage']['invoices_dir']);
        self::assertSame('/custom/uploads',  $cfg['storage']['uploads_dir']);
        self::assertSame('/custom/backup',   $cfg['storage']['backup_dir']);
        self::assertSame('/custom/sessions', $cfg['storage']['sessions_dir']);
        self::assertSame('/custom/cache',    $cfg['storage']['cache_dir']);
    }

    // ── Logging overrides ─────────────────────────────────────────────────────

    public function testLoggingLevelAndPathCanBeOverridden(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_LOG_LEVEL' => 'debug',
            'MYINVOICE_LOG_PATH'  => '/var/log/myinvoice/app.log',
        ]);
        $cfg = $this->loadConfig();

        self::assertSame('debug', $cfg['logging']['level']);
        self::assertSame('/var/log/myinvoice/app.log', $cfg['logging']['path']);
    }

    // ── SMTP overrides ────────────────────────────────────────────────────────

    public function testSmtpPortAndEncryptionCanBeOverridden(): void
    {
        $this->setRequiredVars([
            'MYINVOICE_SMTP_PORT'       => '465',
            'MYINVOICE_SMTP_ENCRYPTION' => 'ssl',
        ]);
        $cfg = $this->loadConfig();

        self::assertSame(465, $cfg['smtp']['port']);
        self::assertSame('ssl', $cfg['smtp']['encryption']);
    }

    public function testSmtpFromNameDefault(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('MyInvoice', $cfg['smtp']['from_name']);
    }

    public function testSmtpFromNameCanBeOverridden(): void
    {
        $this->setRequiredVars(['MYINVOICE_SMTP_FROM_NAME' => 'My Company Invoices']);
        $cfg = $this->loadConfig();

        self::assertSame('My Company Invoices', $cfg['smtp']['from_name']);
    }

    // ── Config top-level structure ────────────────────────────────────────────

    public function testReturnedArrayHasAllExpectedTopLevelKeys(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        $expectedKeys = [
            'app', 'db', 'redis', 'session', 'smtp', 'ares', 'vies',
            'logging', 'storage', 'qr', 'pagination', 'varsymbol',
            'rate_limits', 'brute_force', 'captcha', 'approval',
            'ip_allowlist', 'bank_import', 'cron',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $cfg, "Top-level key '{$key}' must exist");
        }
    }

    // ── Pepper and secret key ─────────────────────────────────────────────────

    public function testPepperIsPassedThrough(): void
    {
        $pepper = base64_encode(random_bytes(32));
        $this->setRequiredVars(['MYINVOICE_APP_PEPPER' => $pepper]);
        $cfg = $this->loadConfig();

        self::assertSame($pepper, $cfg['app']['pepper']);
    }

    public function testSecretEncryptionKeyDefaultsToEmptyString(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        self::assertSame('', $cfg['app']['secret_encryption_key']);
    }

    public function testSecretEncryptionKeyCanBeSet(): void
    {
        $key = base64_encode(random_bytes(32));
        $this->setRequiredVars(['MYINVOICE_APP_SECRET_KEY' => $key]);
        $cfg = $this->loadConfig();

        self::assertSame($key, $cfg['app']['secret_encryption_key']);
    }

    // ── Static / hard-coded config values ────────────────────────────────────

    public function testStaticConfigValues(): void
    {
        $this->setRequiredVars();
        $cfg = $this->loadConfig();

        // QR
        self::assertSame('0308', $cfg['qr']['czk_constant_symbol']);

        // Pagination defaults
        self::assertSame(50, $cfg['pagination']['invoices_per_page']);
        self::assertSame(50, $cfg['pagination']['clients_per_page']);
        self::assertSame(50, $cfg['pagination']['projects_per_page']);

        // Varsymbol templates
        self::assertSame('{YY}{MM}{CCC}',   $cfg['varsymbol']['templates']['invoice']);
        self::assertSame('9{YY}{MM}{CCC}',  $cfg['varsymbol']['templates']['proforma']);
        self::assertSame('7{YY}{MM}{CCC}',  $cfg['varsymbol']['templates']['credit_note']);

        // IP allowlist disabled by default
        self::assertFalse($cfg['ip_allowlist']['enabled']);

        // SMTP security defaults
        self::assertTrue($cfg['smtp']['verify_peer']);
        self::assertFalse($cfg['smtp']['allow_self_signed']);

        // DB charset fixed
        self::assertSame('utf8mb4', $cfg['db']['charset']);
    }
}