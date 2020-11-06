<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use Psr\Log\LoggerInterface;
use Yiisoft\Cache\Dependency\Dependency;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Exception\InvalidCallException;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Db\Schema\Schema;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Profiler\ProfilerInterface;

final class ProxySPDOConnection implements ConnectionInterface
{
    private SPDOConnection $parent;
    private string $role;

    public function __construct(SPDOConnection $parent, string $role)
    {
        $this->parent = $parent;
        $this->role = $role;
    }

    public function beginTransaction($isolationLevel = null): TransactionInterface
    {
        if ($this->role === self::ROLE_SLAVE) {
            throw new InvalidCallException('Transactions in Slave mode is prohibited.');
        }
        return $this->parent->beginTransaction($isolationLevel);
    }

    public function cache(callable $callable, int $duration = null, Dependency $dependency = null, array $params = [])
    {
        return $this->parent->cache($callable, $duration, $dependency, $params);
    }

    public function close(): void
    {
        $this->parent->close();
    }

    public function createCommand(string $sql, array $params = []): CommandInterface
    {
        if ($this->role == self::ROLE_MASTER) {
            return $this->parent->master(fn (SPDOConnection $conn) => $conn->createCommand($sql, $params));
        } elseif ($this->role == self::ROLE_SLAVE) {
            return $this->parent->slave(fn (SPDOConnection $conn) => $conn->createCommand($sql, $params));
        }
        return $this->parent->createCommand($sql, $params);
    }

    public function getCharset(): ?string
    {
        return $this->parent->getCharset();
    }

    public function getDriverName(): string
    {
        return $this->parent->getDriverName();
    }

    public function getId(): string
    {
        return $this->parent->getId();
    }

    public function getLastInsertID(string $sequenceName = null): string
    {
        if ($this->role === self::ROLE_SLAVE) {
            throw new InvalidCallException('Write in Slave mode is prohibited.');
        }
        return $this->parent->getLastInsertID($sequenceName);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->parent->getLogger();
    }

    public function getMasterServerVersion(): string
    {
        return $this->parent->getMasterServerVersion();
    }

    public function getProfiler(): ?ProfilerInterface
    {
        return $this->parent->getProfiler();
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->parent->getQueryBuilder();
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getSchema(): Schema
    {
        return $this->parent->getSchema();
    }

    public function getServerVersion(): string
    {
        return $this->parent->getServerVersion();
    }

    public function getTablePrefix(): string
    {
        return $this->parent->getTablePrefix();
    }

    public function getTransaction(): ?TransactionInterface
    {
        if ($this->role === self::ROLE_SLAVE) {
            return null;
        }
        return $this->parent->getTransaction();
    }

    public function isActive(): bool
    {
        return $this->parent->isActive();
    }

    public function isLoggingEnabled(): bool
    {
        return $this->parent->isLoggingEnabled();
    }

    public function isProfilingEnabled(): bool
    {
        return $this->parent->isProfilingEnabled();
    }

    public function isQueryCacheEnabled(): bool
    {
        return $this->parent->isQueryCacheEnabled();
    }

    public function isSavepointEnabled(): bool
    {
        return $this->parent->isSavepointEnabled();
    }

    public function isSchemaCacheEnabled(): bool
    {
        return $this->parent->isSchemaCacheEnabled();
    }

    public function isTransactionEnabled(): bool
    {
        return $this->parent->isTransactionEnabled();
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

    public function noCache(callable $callable, ...$params)
    {
        return $this->parent->noCache($callable, ...$params);
    }

    public function setCharset(?string $charset): void
    {
        $this->parent->setCharset($charset);
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
        return $this->withRole(self::ROLE_AUTO);
    }

    public function useMaster(): ConnectionInterface
    {
        return $this->withRole(self::ROLE_MASTER);
    }

    public function useSlave(): ConnectionInterface
    {
        return $this->withRole(self::ROLE_SLAVE);
    }

    protected function withRole(string $role): self
    {
        if ($this->role === $role) {
            return $this;
        }

        $new = clone $this;
        $new->role = $role;
        return $new;
    }
}
