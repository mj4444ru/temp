<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Cache\Dependency\Dependency;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidCallException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Db\Schema\Schema;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Profiler\ProfilerInterface;

interface ConnectionInterface
{
    public const ROLE_AUTO = 'auto';
    public const ROLE_MASTER = 'master';
    public const ROLE_SLAVE = 'slave';

    /**
     * Starts a transaction.
     *
     * @param string|null $isolationLevel The isolation level to use for this transaction.
     * @return TransactionInterface the transaction initiated
     * @throws InvalidCallException
     * @throws NotSupportedException
     * @throws Exception
     * {@see TransactionInterface::begin()} for details.
     */
    public function beginTransaction($isolationLevel = null): TransactionInterface;

    /**
     * Uses query cache for the queries performed with the callable.
     *
     * When query caching is enabled ({@see enableQueryCache} is true and {@see queryCache} refers to a valid cache),
     * queries performed within the callable will be cached and their results will be fetched from cache if available.
     *
     * For example,
     *
     * ```php
     * // The customer will be fetched from cache if available.
     * // If not, the query will be made against DB and cached for use next time.
     * $customer = $db->cache(function (ConnectionInterface $db) {
     *     return $db->createCommand('SELECT * FROM customer WHERE id=1')->queryOne();
     * });
     * ```
     *
     * Note that query cache is only meaningful for queries that return results. For queries performed with
     * {@see PDOCommand::execute()}, query cache will not be used.
     *
     * @param callable $callable a PHP callable that contains DB queries which will make use of query cache.
     * The signature of the callable is `function (Connection $db)`.
     * @param int|null $duration the number of seconds that query results can remain valid in the cache. If this is not
     * set, the value of {@see queryCacheDuration} will be used instead. Use 0 to indicate that the cached data will
     * never expire.
     * @param Dependency|null $dependency the cache dependency associated with the cached query results.
     * @param array $params
     * @return mixed the return result of the callable
     * @throws Throwable if there is any exception during query
     * {@see setEnableQueryCache()}
     * {@see queryCache}
     * {@see noCache()}
     */
    public function cache(callable $callable, int $duration = null, Dependency $dependency = null, array $params = []);

    /**
     * Closes the currently active DB connection.
     *
     * It does nothing if the connection is already closed.
     */
    public function close(): void;

    public function getCharset(): ?string;

    /**
     * Creates a command for execution.
     *
     * @param string|null $sql the SQL statement to be executed
     * @param array $params the parameters to be bound to the SQL statement
     * @return CommandInterface the DB command
     */
    public function createCommand(string $sql, array $params = []): CommandInterface;

    /**
     * Returns the name of the DB driver.
     *
     * @return string name of the DB driver
     */
    public function getDriverName(): string;

    public function getId(): string;

    /**
     * Returns the ID of the last inserted row or sequence value.
     *
     * @param string|null $sequenceName name of the sequence object (required by some DBMS)
     * @return string the row ID of the last row inserted, or the last value retrieved from the sequence object
     * @throws InvalidCallException if the DB connection is not active
     * @see http://www.php.net/manual/en/function.PDO-lastInsertId.php
     */
    public function getLastInsertID(string $sequenceName = null): string;

    public function getLogger(): LoggerInterface;


    /**
     * Returns a master server version as a string comparable by {@see \version_compare()}.
     *
     * @return string server version as a string.
     * @throws NotSupportedException
     */
    public function getMasterServerVersion(): string;

    public function getProfiler(): ?ProfilerInterface;

    /**
     * Returns the query builder for the current DB connection.
     *
     * @return QueryBuilder the query builder for the current DB connection.
     */
    public function getQueryBuilder(): QueryBuilder;

    public function getRole(): string;

    /**
     * Returns the schema information for the database opened by this connection.
     *
     * @return Schema the schema information for the database opened by this connection.
     */
    public function getSchema(): Schema;

    /**
     * Returns a slave server version as a string comparable by {@see \version_compare()}.
     *
     * @return string server version as a string.
     * @throws NotSupportedException
     */
    public function getServerVersion(): string;

    public function getTablePrefix(): string;

    /**
     * Returns the currently active transaction.
     *
     * @return TransactionInterface|null the currently active transaction. Null if no active transaction.
     */
    public function getTransaction(): ?TransactionInterface;

    /**
     * Returns a value indicating whether the DB connection is established.
     *
     * @return bool whether the DB connection is established
     */
    public function isActive(): bool;

    public function isLoggingEnabled(): bool;

    public function isProfilingEnabled(): bool;

    public function isQueryCacheEnabled(): bool;

    public function isSavepointEnabled(): bool;

    public function isSchemaCacheEnabled(): bool;

    public function isTransactionEnabled(): bool;

    public function master(callable $callback, ...$params);

    /**
     * Disables query cache temporarily.
     *
     * Queries performed within the callable will not use query cache at all. For example,
     *
     * ```php
     * $db->cache(function (ConnectionInterface $db) {
     *
     *     // ... queries that use query cache ...
     *
     *     return $db->noCache(function (Connection $db) {
     *         // this query will not use query cache
     *         return $db->createCommand('SELECT * FROM customer WHERE id=1')->queryOne();
     *     });
     * });
     * ```
     *
     * @param callable $callable a PHP callable that contains DB queries which should not use query cache. The signature
     * of the callable is `function (Connection $db)`.
     * @param mixed ...$params
     * @return mixed the return result of the callable
     * @throws Throwable if there is any exception during query
     * {@see enableQueryCache}
     * {@see queryCache}
     * {@see cache()}
     */
    public function noCache(callable $callable, ...$params);

    public function setCharset(?string $charset): void;

    public function slave(callable $callback, ...$params);

    public function useAuto(): self;

    public function useMaster(): self;

    public function useSlave(): self;
}
