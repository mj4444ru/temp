<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Cache\CacheInterface;
use Yiisoft\Cache\Dependency\Dependency;
use Yiisoft\Db\Command\PDOCommand;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidCallException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Db\Query\QueryCacheProxy;
use Yiisoft\Db\Schema\Schema;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Profiler\ProfilerInterface;

use function array_pop;
use function end;

abstract class BaseConnection implements ConnectionInterface
{
    public bool $enableLogging = false;
    public bool $enableProfiling = false;
    public bool $enableQueryCache = true;
    public bool $enableSchemaCache = true;
    public string $id = '';
    public ?CacheInterface $queryCache = null;
    public int $queryCacheDuration = 60;
    public ?CacheInterface $schemaCache = null;
    public string $tablePrefix = '';

    protected bool $enableTransaction = true;
    protected LoggerInterface $logger;
    protected ?ProfilerInterface $profiler;

    private QueryBuilder $builder;
    private ?string $charset = null;
    private array $queryCacheStack = [];
    private Schema $schema;
    private TransactionInterface $transaction;

    public function __construct(LoggerInterface $logger, ?ProfilerInterface $profiler = null, CacheInterface $cache = null)
    {
        $this->logger = $logger;
        $this->profiler = $profiler;
        $this->schemaCache = $cache;
        $this->queryCache = $cache;
    }

    public function __clone()
    {
        unset($this->builder);
        $this->queryCacheStack = [];
        unset($this->schema);
        unset($this->transaction);
    }

    public function __serialize(): array
    {
        $fields = (array)$this;
        unset(
            $fields["\000" . __CLASS__ . "\000" . 'builder'],
            $fields["\000" . __CLASS__ . "\000" . 'queryCacheStack'],
            $fields["\000" . __CLASS__ . "\000" . 'schema'],
            $fields["\000" . __CLASS__ . "\000" . 'transaction'],
        );

        return $fields;
    }

    /**
     * @param null $isolationLevel
     * @return TransactionInterface
     * @throws InvalidCallException
     * @throws NotSupportedException
     * @throws Exception
     */
    final public function beginTransaction($isolationLevel = null): TransactionInterface
    {
        if (!$this->isTransactionEnabled()) {
            throw new InvalidCallException('Transactions disabled.');
        }

        $transaction = $this->transaction ?? ($this->transaction = $this->createTransactionInstance());
        $transaction->begin($isolationLevel);

        return $transaction;
    }

    final public function cache(callable $callable, int $duration = null, Dependency $dependency = null, array $params = [])
    {
        if (!$this->isQueryCacheEnabled()) {
            return $callable($this, ...$params);
        }

        $this->queryCacheStack[] = new QueryCacheProxy($this->queryCache, $duration ?? $this->queryCacheDuration, $dependency);
        try {
            return $callable($this, ...$params);
        } finally {
            array_pop($this->queryCacheStack);
        }
    }

    public function close(): void
    {
        unset($this->builder);
        $this->queryCacheStack = [];
        unset($this->schema);
        unset($this->transaction);
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function getId(): string
    {
        return $this->id;
    }

    final public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    final public function getProfiler(): ?ProfilerInterface
    {
        return $this->profiler;
    }

    final public function getQueryBuilder(): QueryBuilder
    {
        return $this->builder ?? ($this->builder = $this->getSchema()->getQueryBuilder());
    }

    /**
     * Returns the current query cache proxy.
     *
     * This method is used internally by {@see PDOCommand}.
     *
     * @return QueryCacheProxy|null the QueryCacheProxy for current query cache information, or null if query cache is not enabled.
     */
    final public function getQueryCacheProxy(): ?QueryCacheProxy
    {
        return empty($this->queryCacheStack) ? null : end($this->queryCacheStack);
    }

    public function getRole(): string
    {
        return self::ROLE_MASTER;
    }

    final public function getSchema(): Schema
    {
        return $this->schema ?? ($this->schema = $this->createSchemaInstance());
    }

    final public function getSchemaCache(): ?CacheInterface
    {
        return $this->isSchemaCacheEnabled() ? $this->schemaCache : null;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    final public function getTransaction(): ?TransactionInterface
    {
        return isset($this->transaction) && $this->transaction->isActive() ? $this->transaction : null;
    }

    public function isLoggingEnabled(): bool
    {
        return $this->enableLogging && isset($this->logger);
    }

    public function isProfilingEnabled(): bool
    {
        return $this->enableProfiling && isset($this->profiler);
    }

    public function isQueryCacheEnabled(): bool
    {
        return $this->enableQueryCache && isset($this->queryCache);
    }

    public function isSavepointEnabled(): bool
    {
        return false;
    }

    public function isSchemaCacheEnabled(): bool
    {
        return $this->enableSchemaCache && isset($this->schemaCache);
    }

    public function isTransactionEnabled(): bool
    {
        return $this->enableTransaction;
    }

    public function master(callable $callback, ...$params)
    {
        return $callback($this, ...$params);
    }

    final public function noCache(callable $callable, ...$params)
    {
        if (empty($this->queryCacheStack) || end($this->queryCacheStack) === false) {
            return $callable($this, ...$params);
        }

        $this->queryCacheStack[] = false;
        try {
            return $callable($this, ...$params);
        } finally {
            array_pop($this->queryCacheStack);
        }
    }

    public function setCharset(?string $charset): void
    {
        $this->charset = $charset;
    }

    public function slave(callable $callback, ...$params)
    {
        return $callback($this, ...$params);
    }

    public function useAuto(): ConnectionInterface
    {
        return $this;
    }

    public function useMaster(): ConnectionInterface
    {
        return $this;
    }

    public function useSlave(): ConnectionInterface
    {
        return $this;
    }

    protected function createSchemaInstance(): Schema
    {
        return new Schema($this);
    }

    abstract protected function createTransactionInstance(): TransactionInterface;

    protected function logException(Throwable $e): void
    {
        if ($this->isLoggingEnabled()) {
            $this->logger->error($e->getMessage(), [__METHOD__, $e]);
        }
    }
}
