<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use PDO;
use Throwable;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Command\PDOCommand;
use Yiisoft\Db\Exception\InvalidCallException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Transaction\PDOTransaction;
use Yiisoft\Db\Transaction\TransactionInterface;

use function get_class;

abstract class PDOConnection extends BaseConnection
{
    /**
     * Default PDO attributes (name => value) that should be set when calling {@see open()} to establish a DB connection.
     * Please refer to the [PHP manual](http://php.net/manual/en/pdo.setattribute.php) for details about available
     * attributes.
     *
     * @var array
     */
    public array $pdoDefaultOptions = [];

    protected ?string $dsn = null;

    private PDO $pdo;
    private string $serverVersion;

    public function __destruct()
    {
        $this->close();
    }

    public function __clone()
    {
        unset($this->dsn);
        unset($this->pdo);

        parent::__clone();
    }

    public function __serialize(): array
    {
        $fields = parent::__serialize();
        unset(
            $fields['*dsn'],
            $fields["\000" . __CLASS__ . "\000" . 'pdo'],
        );

        return $fields;
    }

    public function close(): void
    {
        if ($this->pdo !== null) {
            if ($this->enableLogging) {
                $this->logger->debug("Closing DB connection with ID: {$this->id}, DSN: {$this->dsn}.", [get_class($this)]);
            }

            $this->dsn = null;
            unset($this->pdo);

            if (($trans = $this->getTransaction()) !== null && $trans instanceof PDOTransaction) {
                $trans->connectionClosed();
            }
        }

        parent::close();
    }

    public function createCommand(string $sql, array $params = []): CommandInterface
    {
        $command = new PDOCommand($this, $sql);
        $command->setParams($params);

        return $command;
    }

    final public function getDriverName(): string
    {
        return 'PDO:' . $this->getPDODriverName();
    }

    /**
     * Please refer to the [PHP manual](https://secure.php.net/manual/en/pdo.construct.php) on the format of the DSN
     * string
     *
     * For [SQLite](https://secure.php.net/manual/en/ref.pdo-sqlite.connection.php) you may use a
     * [path alias](guide:concept-aliases) for specifying the database path, e.g. `sqlite:@app/data/db.sql`.
     *
     * {@see charset}
     *
     * @return string the Data Source Name, or DSN, contains the information required to connect to the database.
     */
    public function getDsn(): ?string
    {
        return $this->dsn;
    }

    public function getLastInsertID(string $sequenceName = null): string
    {
        if (empty($this->pdo)) {
            throw new InvalidCallException('DB Connection is not active.');
        }
        return $this->getPDO()->lastInsertId($sequenceName ? $this->getSchema()->quoteTableName($sequenceName) : null);
    }

    public function getMasterServerVersion(): string
    {
        return $this->getServerVersion();
    }

    public function getPDO(): PDO
    {
        return $this->pdo ?? ($this->pdo = $this->createPDOInstance());
    }

    abstract public function getPDODriverName(): string;

    public function getServerVersion(): string
    {
        return $this->serverVersion ?? ($this->serverVersion = (string)$this->getSlavePDO()->getAttribute(PDO::ATTR_SERVER_VERSION));
    }

    public function getSlavePDO(): PDO
    {
        return $this->pdo ?? ($this->pdo = $this->createPDOInstance());
    }

    public function isActive(): bool
    {
        return isset($this->pdo);
    }

    /**
     * Creates the PDO instance.
     *
     * This method is called by {@see open} to establish a DB connection. The default implementation will create a PHP
     * PDO instance. You may override this method if the default PDO needs to be adapted for certain DBMS.
     *
     * @return PDO the pdo instance
     */
    abstract protected function createPDOInstance(): PDO;

    /**
     * Creates the PDO instance from config array and initializes the DB connection.
     *
     * This method is called by {@see open} to establish a DB connection. The default implementation will create a PHP
     * PDO instance. You may override this method if the default PDO needs to be adapted for certain DBMS.
     *
     * @param array $config
     *
     * @return PDO the pdo instance
     */
    protected function createPDOInstanceFromConfig(array $config): PDO
    {
        $options = $config['options'] ?? $config['attributes'] ?? $this->pdoDefaultOptions;
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        return new PDO(
            $config['dsn'],
            $config['user'] ?? $config['username'] ?? null,
            $config['pass'] ?? $config['password'] ?? null,
            $options
        );
    }

    protected function createTransactionInstance(): TransactionInterface
    {
        return new PDOTransaction($this);
    }

    /**
     * Creates the PDO instance from config array (for ServerPoolTrait).
     *
     * @param array $config
     *
     * @return PDO the pdo instance
     *
     * @throws InvalidConfigException
     * @throws Throwable
     */
    protected function getConnectFromConfig(array $config): PDO
    {
        if (empty($config['dsn'])) {
            throw new InvalidConfigException('The required parameter "dsn" is not specified in the server configuration.');
        }

        $token = "Opening DB connection: {$this->id} {$config['dsn']}";

        if ($this->isLoggingEnabled()) {
            $this->logger->info($token, [__CLASS__]);
        }

        if ($this->isProfilingEnabled()) {
            $this->profiler->begin($token, [__CLASS__]);
        }

        try {
            return $this->createPDOInstanceFromConfig($config);
        } catch (Throwable $e) {
            if ($this->isLoggingEnabled()) {
                $this->logger->alert("Connection ({$config['dsn']}) failed: " . $e->getMessage(), [__CLASS__]);
            }
            throw $e;
        } finally {
            if ($this->isProfilingEnabled()) {
                $this->profiler->end($token, [__CLASS__]);
            }
        }
    }
}
