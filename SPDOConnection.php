<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use PDO;

use function get_class;

abstract class SPDOConnection extends PDOConnection
{
    public bool $allowSlaveServer = true;

    protected ?string $dsnSlave = null;

    private string $masterServerVersion;
    private PDO $pdoSlave;
    private string $role = self::ROLE_AUTO;

    public function __clone()
    {
        $this->dsnSlave = null;
        unset($this->pdoSlave);

        parent::__clone();
    }

    public function __serialize(): array
    {
        $fields = parent::__serialize();
        unset(
            $fields['*dsnSlave'],
            $fields["\000" . __CLASS__ . "\000" . 'pdoSlave'],
        );

        return $fields;
    }

    public function close(): void
    {
        if ($this->pdoSlave !== null) {
            if ($this->enableLogging) {
                $this->logger->debug("Closing SlaveDB connection with ID: {$this->id}, DSN: {$this->dsnSlave}.", [get_class($this)]);
            }

            unset($this->pdoSlave);
        }

        parent::close();
    }

    public function getMasterServerVersion(): string
    {
        return $this->masterServerVersion ??
            ($this->masterServerVersion = (string)$this->getPDO()->getAttribute(PDO::ATTR_SERVER_VERSION));
    }

    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Please refer to the [PHP manual](https://secure.php.net/manual/en/pdo.construct.php) on the format of the DSN
     * string for slave connection.
     *
     * For [SQLite](https://secure.php.net/manual/en/ref.pdo-sqlite.connection.php) you may use a
     * [path alias](guide:concept-aliases) for specifying the database path, e.g. `sqlite:@app/data/db.sql`.
     *
     * {@see charset}
     *
     * @return string the Data Source Name, or DSN, contains the information required to connect to the database.
     */
    public function getSlaveDsn(): ?string
    {
        return $this->dsnSlave;
    }

    public function getSlavePDO(): PDO
    {
        return $this->pdoSlave ??
            ($this->pdoSlave = ($this->allowSlaveServer ? $this->createSlavePDOInstance() : $this->getPDO()));
    }

    public function master(callable $callback, ...$params)
    {
        $role = $this->role;
        $this->role = self::ROLE_MASTER;
        try {
            return $callback($this, ...$params);
        } finally {
            $this->role = $role;
        }
    }

    public function slave(callable $callback, ...$params)
    {
        $role = $this->role;
        $this->role = self::ROLE_SLAVE;
        try {
            return $callback($this, ...$params);
        } finally {
            $this->role = $role;
        }
    }

    public function useAuto(): ConnectionInterface
    {
        return $this;
    }

    public function useMaster(): ConnectionInterface
    {
        return new ProxySPDOConnection($this, self::ROLE_MASTER);
    }

    public function useSlave(): ConnectionInterface
    {
        return new ProxySPDOConnection($this, self::ROLE_SLAVE);
    }

    /**
     * Creates the SlavePDO instance.
     *
     * This method is called by {@see open} to establish a DB connection. The default implementation will create a PHP
     * PDO instance. You may override this method if the default PDO needs to be adapted for certain DBMS.
     *
     * @return PDO the pdo instance
     */
    abstract protected function createSlavePDOInstance(): PDO;
}
