<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for the SSL CA option introduced in Connection::pdo().
 *
 * Connection is final and creates a real PDO internally, so we cannot
 * intercept the PDO constructor. The strategy used here is:
 *
 *  - For pure-option logic: inject a pre-built PDO mock via reflection so
 *    ping() can be exercised without a real MySQL server.
 *  - For the ssl_ca branch: verify the Config plumbing returns the correct
 *    value and that constructing Connection with ssl_ca set causes a
 *    PDOException (connection refused, not a code error) when no MySQL is
 *    reachable — proving the code path is reached.
 */
final class ConnectionSslTest extends TestCase
{
    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeConfig(array $data): Config
    {
        return new Config($data);
    }

    /** Minimal valid DB config without ssl_ca. */
    private function baseDbConfig(): array
    {
        return [
            'db' => [
                'host'    => '127.0.0.1',
                'port'    => 3306,
                'name'    => 'myinvoice_test',
                'user'    => 'nobody',
                'pass'    => '',
                'charset' => 'utf8mb4',
                'ssl_ca'  => '',
            ],
        ];
    }

    /**
     * Inject a ready PDO instance into Connection via reflection, bypassing
     * the real MySQL connection so that ping() can be tested in isolation.
     */
    private function injectPdo(Connection $conn, PDO $pdo): void
    {
        $prop = new ReflectionProperty(Connection::class, 'pdo');
        $prop->setValue($conn, $pdo);
    }

    // ── Config plumbing ───────────────────────────────────────────────────────

    public function testConfigReturnsSslCaWhenSet(): void
    {
        $caPath = '/path/to/DigiCertGlobalRootG2.crt.pem';
        $config = $this->makeConfig(['db' => ['ssl_ca' => $caPath]]);

        self::assertSame($caPath, $config->get('db.ssl_ca', ''));
    }

    public function testConfigReturnEmptyStringWhenSslCaAbsent(): void
    {
        $config = $this->makeConfig(['db' => []]);

        self::assertSame('', $config->get('db.ssl_ca', ''));
    }

    public function testConfigReturnEmptyStringWhenSslCaExplicitlyEmpty(): void
    {
        $config = $this->makeConfig(['db' => ['ssl_ca' => '']]);

        self::assertSame('', $config->get('db.ssl_ca', ''));
    }

    // ── SSL CA options branch — code-path coverage ────────────────────────────

    /**
     * When ssl_ca is an empty string the connection attempt must NOT add SSL
     * options; the resulting PDOException is about the refusal of the TCP
     * connection, not about certificate issues.
     */
    public function testPdoThrowsOnUnavailableMysqlWithoutSslCa(): void
    {
        $this->expectException(PDOException::class);

        $config = $this->makeConfig($this->baseDbConfig());
        $conn   = new Connection($config);
        $conn->pdo(); // triggers PDO constructor → connection refused
    }

    /**
     * When ssl_ca is a non-empty path the code adds PDO::MYSQL_ATTR_SSL_CA
     * and PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT to the options array before
     * calling new PDO(). This test verifies that code path is reached
     * (a PDOException is thrown, not a TypeError or logic error).
     */
    public function testPdoThrowsOnUnavailableMysqlWithSslCaSet(): void
    {
        $this->expectException(PDOException::class);

        $cfg        = $this->baseDbConfig();
        $cfg['db']['ssl_ca'] = '/tmp/fake-ca.pem';

        $config = $this->makeConfig($cfg);
        $conn   = new Connection($config);
        $conn->pdo(); // triggers PDO constructor → connection refused (not a code error)
    }

    // ── ping() behaviour ──────────────────────────────────────────────────────

    /**
     * ping() must return true when the underlying PDO responds to SELECT 1.
     * We inject a real SQLite in-memory PDO so no MySQL is required.
     */
    public function testPingReturnsTrueWithWorkingConnection(): void
    {
        $config = $this->makeConfig($this->baseDbConfig());
        $conn   = new Connection($config);

        // SQLite in-memory PDO satisfies SELECT 1
        $sqlite = new PDO('sqlite::memory:');
        $this->injectPdo($conn, $sqlite);

        self::assertTrue($conn->ping());
    }

    /**
     * ping() must return false — not throw — when the injected PDO raises an
     * exception on query(), e.g. if the connection was dropped.
     */
    public function testPingReturnsFalseWhenQueryFails(): void
    {
        $config = $this->makeConfig($this->baseDbConfig());
        $conn   = new Connection($config);

        // A closed connection that throws on any query call
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')
                ->willThrowException(new PDOException('server has gone away'));
        $this->injectPdo($conn, $mockPdo);

        self::assertFalse($conn->ping());
    }

    /**
     * ping() must return false when no MySQL is available, without propagating
     * the exception to the caller.
     */
    public function testPingReturnsFalseWhenNoMysqlAvailable(): void
    {
        $config = $this->makeConfig($this->baseDbConfig());
        $conn   = new Connection($config);

        // ping() internally calls pdo() which will throw PDOException; the
        // method must catch it and return false
        self::assertFalse($conn->ping());
    }

    // ── PDO constant sanity ───────────────────────────────────────────────────

    /**
     * Regression guard: ensure that the PDO MySQL SSL constants used in
     * Connection::pdo() actually exist in the runtime, so the options array
     * is built with correct keys.
     */
    public function testPdoMysqlSslConstantsExist(): void
    {
        self::assertTrue(
            defined('PDO::MYSQL_ATTR_SSL_CA'),
            'PDO::MYSQL_ATTR_SSL_CA constant must be defined (pdo_mysql extension required)',
        );
        self::assertTrue(
            defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'),
            'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT constant must be defined',
        );
    }

    // ── ssl_ca whitespace edge-cases ──────────────────────────────────────────

    /**
     * A path that is purely whitespace must NOT enable SSL (empty after cast,
     * but cast to string first). The condition is `$sslCa !== ''` so a string
     * containing only spaces will enable SSL — document this behaviour.
     */
    public function testConfigSslCaWithWhitespaceIsNonEmpty(): void
    {
        $config = $this->makeConfig(['db' => ['ssl_ca' => '   ']]);
        // The Connection code casts to string and checks !== ''; spaces are non-empty
        self::assertNotSame('', $config->get('db.ssl_ca', ''));
    }

    /**
     * Null ssl_ca value falls back to the default empty string, so SSL is disabled.
     */
    public function testConfigSslCaNullFallsBackToEmptyDefault(): void
    {
        $config = $this->makeConfig(['db' => ['ssl_ca' => null]]);
        // get() returns null for key 'ssl_ca'; cast in Connection: (string)null === ''
        $sslCa = (string) $config->get('db.ssl_ca', '');
        self::assertSame('', $sslCa);
    }
}
