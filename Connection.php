<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use PDO;
use Psr\Log\LoggerInterface;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Db\Command\PDOCommand;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Transaction\PDOTransaction;
use Yiisoft\Profiler\ProfilerInterface;

use function count;
use function explode;
use function strtolower;

/**
 * Connection represents a connection to a database via [PDO](http://php.net/manual/en/book.pdo.php).
 *
 * Connection works together with {@see PDOCommand}, {@see DataReader} and {@see PDOTransaction} to provide data access to
 * various DBMS in a common set of APIs. They are a thin wrapper of the
 * [PDO PHP extension](http://php.net/manual/en/book.pdo.php).
 */
class Connection extends PDOConnection
{
    protected ?array $options;
    protected ?string $password;
    protected ?string $username;

    private ?string $driverName;
    private bool $enableSavepoint;

    /**
     * Connection constructor.
     *
     * @param LoggerInterface $logger
     * @param ProfilerInterface|null $profiler
     * @param CacheInterface|null $cache
     * @param string|null $dsn
     * @param string|null $username
     * @param string|null $password
     * @param array|null $options
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        LoggerInterface $logger,
        ?ProfilerInterface $profiler = null,
        ?CacheInterface $cache = null,
        string $dsn = null,
        string $username = null,
        string $password = null,
        array $options = null
    ) {
        parent::__construct($logger, $profiler, $cache);

        $this->setDsn($dsn);
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
    }

    /**
     * Reset the connection after cloning.
     */
    public function __clone()
    {
        if ($this->dsn !== 'sqlite::memory:') {
            /** reset PDO connection, unless its sqlite in-memory, which can only have one connection */
            parent::__clone();
        }
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * @return string
     *
     * @throws Exception
     */
    public function getPDODriverName(): string
    {
        if (empty($this->driverName)) {
            throw new Exception('The driver cannot be determined until the DNS property is set.');
        }
        return $this->driverName;
    }

    public function isSavepointEnabled(): bool
    {
        return $this->enableSavepoint;
    }

    /**
     * @param string|null $dsn
     *
     * @throws InvalidArgumentException
     */
    public function setDsn(?string $dsn): void
    {
        if ($dsn === null) {
            $this->driverName = null;
        } else {
            $splitDsn = explode(':', $dsn, 2);
            if (count($splitDsn) !== 2 || empty($splitDsn[0]) || empty($splitDsn[1])) {
                throw new InvalidArgumentException('Invalid DSN string format.');
            }
            $this->driverName = strtolower($splitDsn[0]);
        }
        $this->dsn = $dsn;
    }

    public function setEnableSavepoint(bool $enableSavepoint): void
    {
        $this->enableSavepoint = $enableSavepoint;
    }

    public function setEnableTransaction(bool $enableTransaction): void
    {
        $this->enableTransaction = $enableTransaction;
    }

    /**
     * @param array|null $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    protected function createPDOInstance(): PDO
    {
        return $this->createPDOInstanceFromConfig([
            'dsn' => $this->dsn,
            'username' => $this->username,
            'password' => $this->password,
            'options' => $this->options,
        ]);
    }
}
