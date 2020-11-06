<?php

declare(strict_types=1);

namespace Yiisoft\Db\Connection;

use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Cache\CacheInterface;

use function count;
use function md5;
use function serialize;

trait ServerPoolTrait
{
    public int $numberAttemptsBeforeSeverDisable = 3;
    public bool $shufflePoolConfigs = true;
    public int $timeoutBeforeRetryingConnect = 60;

    protected array $defaultConfig = [];
    protected array $lastSuccessfulConfiguration;
    protected array $masterPoolConfigs = [];
    protected array $slavePoolConfigs = [];

    abstract public function getSchemaCache(): ?CacheInterface;

    public function setPoolConfig(array $config): void
    {
        if (isset($config['shufflePoolConfigs'])) {
            $this->shufflePoolConfigs = $config['shufflePoolConfigs'];
            unset($config['shufflePoolConfigs']);
        }
        if (isset($config['numberAttemptsBeforeSeverDisable'])) {
            $this->numberAttemptsBeforeSeverDisable = $config['numberAttemptsBeforeSeverDisable'];
            unset($config['numberAttemptsBeforeSeverDisable']);
        }
        if (isset($config['timeoutBeforeRetryingConnect'])) {
            $this->timeoutBeforeRetryingConnect = $config['timeoutBeforeRetryingConnect'];
            unset($config['timeoutBeforeRetryingConnect']);
        }
        if (empty($config['pool']) && empty($config['master']) && empty($config['masters'])) {
            $this->masterPoolConfigs = [$config];
        } else {
            if (isset($config['pool'])) {
                $this->masterPoolConfigs = $config['pool'];
                unset($config['pool']);
            }
            if (isset($config['master'])) {
                $this->masterPoolConfigs = [$config['master']];
                unset($config['master']);
            }
            if (isset($config['masters'])) {
                $this->masterPoolConfigs = $config['masters'];
                unset($config['masters']);
            }
            if (isset($config['slave'])) {
                $this->masterPoolConfigs = [$config['slave']];
                unset($config['slave']);
            }
            if (isset($config['slaves'])) {
                $this->masterPoolConfigs = [$config['slaves']];
                unset($config['slaves']);
            }
        }
        $this->defaultConfig = $config;
    }

    abstract protected function getConnectFromConfig(array $config);

    /**
     * @param bool $shuffle
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    protected function getConnectFromMasterServerPool(bool $shuffle = null)
    {
        return $this->getConnectFromServerPool($this->masterPoolConfigs, $shuffle, true);
    }

    /**
     * @param array $pool
     * @param bool $shuffle
     * @param bool $isMaster
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    protected function getConnectFromServerPool(array $pool, bool $shuffle = null, bool $isMaster = false)
    {
        if (empty($pool)) {
            return null;
        }

        if ($shuffle ?? $this->shufflePoolConfigs) {
            shuffle($pool);
        }

        $cache = $isMaster && count($pool) < 2 ? null : $this->getSchemaCache();
        $retryingPool = [];

        foreach ($pool as $config) {
            try {
                $cacheKey = null;
                $errorCount = 0;

                $config = ArrayHelper::merge($this->defaultConfig, $config);
                $config = $this->prepareConfig($config);

                if ($cache) {
                    $cacheKey = md5(__CLASS__ . serialize($config['dsn'] ?? $config));
                    if (($errorCount = $this->cacheGet($cache, $cacheKey)) >= $this->numberAttemptsBeforeSeverDisable) {
                        if ($isMaster) {
                            $retryingPool[] = [$cacheKey, $config];
                        }
                        continue;
                    }
                }

                try {
                    $instance = $this->getConnectFromConfig($config);

                    if ($instance !== false && $instance !== null) {
                        $this->lastSuccessfulConfiguration = $config;

                        return $instance;
                    }
                } catch (Throwable $e) {
                    $this->logException($e);
                }

                if ($cacheKey) {
                    $this->cacheSet($cache, $cacheKey, $errorCount + 1, $this->timeoutBeforeRetryingConnect);
                }
            } catch (Throwable $e) {
                $this->logException($e);
            }
        }

        foreach ($retryingPool as $item) {
            [$cacheKey, $config] = $item;
            try {
                $config = ArrayHelper::merge($this->defaultConfig, $config);

                $instance = $this->getConnectFromConfig($config);

                if ($instance !== false && $instance !== null) {
                    $cache->delete($cacheKey);
                    $this->lastSuccessfulConfiguration = $config;

                    return $instance;
                }
            } catch (Throwable $e) {
                $this->logException($e);
            }
        }

        return null;
    }

    /**
     * @param bool $shuffle
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    protected function getConnectFromSlaveServerPool(bool $shuffle = null)
    {
        return $this->getConnectFromServerPool($this->masterPoolConfigs, $shuffle);
    }

    abstract protected function logException(Throwable $e): void;

    abstract protected function prepareConfig(array $config): array;

    /**
     * @param CacheInterface $cache
     * @param string $key
     * @return int
     *
     * @throws InvalidArgumentException
     */
    private function cacheGet(CacheInterface $cache, string $key): int
    {
        try {
            return $cache->get($key);
        } catch (Throwable $e) {
            $this->logException($e);
            return 0;
        }
    }

    /**
     * @param CacheInterface $cache
     * @param string $key
     * @param int $value
     * @param int $ttl
     *
     * @throws InvalidArgumentException
     */
    private function cacheSet(CacheInterface $cache, string $key, int $value, int $ttl): void
    {
        try {
            $cache->set($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->logException($e);
        }
    }
}
