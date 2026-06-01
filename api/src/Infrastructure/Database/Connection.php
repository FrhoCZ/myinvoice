<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use MyInvoice\Infrastructure\Config\Config;
use PDO;

final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $host    = $this->config->get('db.host', '127.0.0.1');
            $port    = (int) $this->config->get('db.port', 3306);
            $name    = $this->config->get('db.name');
            $user    = $this->config->get('db.user');
            $pass    = $this->config->get('db.pass', '');
            $charset = $this->config->get('db.charset', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            $sslCa = (string) $this->config->get('db.ssl_ca', '');
            if ($sslCa !== '') {
                $options[PDO::MYSQL_ATTR_SSL_CA]     = $sslCa;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }

            $this->pdo = new PDO($dsn, $user, $pass, $options);

            $this->pdo->exec("SET time_zone = '+01:00'");
        }

        return $this->pdo;
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
