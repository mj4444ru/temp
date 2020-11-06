<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Profiler\ProfilerInterface;

class MysqlConnection extends SPDOConnection
{
    use ServerPoolTrait;

    public function __construct(
        LoggerInterface $logger,
        ?ProfilerInterface $profiler = null,
        ?CacheInterface $cache = null,
        array $config = null
    ) {
        parent::__construct($logger, $profiler, $cache);

        $this->setPoolConfig($config ?? []);
    }

    public function getPDODriverName(): string
    {
        return 'mysql';
    }

    /**
     * @return PDO
     *
     * @throws CacheInvalidArgumentException
     */
    protected function createPDOInstance(): PDO
    {
        $pdo = $this->getConnectFromMasterServerPool();

        if (isset($this->lastSuccessfulConfiguration['dsn'])) {
            $this->dsn = $this->lastSuccessfulConfiguration['dsn'];
        }

        return $pdo;
    }

    /**
     * @return PDO
     *
     * @throws CacheInvalidArgumentException
     */
    protected function createSlavePDOInstance(): PDO
    {
        $pdo = $this->getConnectFromSlaveServerPool();

        if (isset($this->lastSuccessfulConfiguration['dsn'])) {
            $this->dsnSlave = $this->lastSuccessfulConfiguration['dsn'];
        }

        return $pdo;
    }

    protected function prepareConfig(array $config): array
    {
        if (empty($config['dsn']) && (!empty($config['host']) || !empty($config['unix_socket']))) {
            $config['dsn'] = "mysql:";
            if (empty($config['host'])) {
                $config['dsn'] .= "unix_socket={$config['unix_socket']}";
            } else {
                $config['dsn'] .= "host={$config['host']}";
                if (!empty($config['port'])) {
                    $config['dsn'] .= ";port={$config['port']}";
                }
            }
            if (!empty($config['dbname'])) {
                $config['dsn'] .= ";dbname={$config['dbname']}";
            }
            if (!empty($config['charset'])) {
                $config['dsn'] .= ";charset={$config['charset']}";
            } elseif (!empty($charset = $this->getCharset())) {
                $config['dsn'] .= ";charset={$charset}";
            }
        }
        return $config;
    }
}
