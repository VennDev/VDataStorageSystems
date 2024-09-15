<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems\handler;

use Exception;
use JsonException;
use Throwable;
use Generator;
use pocketmine\Server;
use pocketmine\utils\Config;
use venndev\vapmdatabase\database\mysql\MySQL;
use venndev\vapmdatabase\database\ResultQuery;
use venndev\vapmdatabase\database\sqlite\SQLite;
use venndev\vdatastoragesystems\utils\TypeDataStorage;
use vennv\vapm\EventLoop;
use vennv\vapm\FiberManager;
use vennv\vapm\Async;
use vennv\vapm\Promise;

final class DataStorage
{

    private const LIMIT_DATA = 4294967295;

    private array $data = [];

    private ?Async $promiseProcess = null;

    public function __construct(
        private readonly string            $name,
        private readonly int               $type,
        private readonly MySQL|SQLite|null $database = null,
        private readonly mixed             $hashData = false
    )
    {
        //TODO: Implement
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getAll(): Generator
    {
        foreach ($this->data as $key => $value) {
            yield $key => $value;
        }
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function reload(): void
    {
        $this->data = [];
    }

    /**
     * @throws Throwable
     */
    public function getAsync(string $key, mixed $default = null): Async
    {
        return new Async(function () use ($key, $default): mixed {
            $data = $this->data[$key] ?? $default;
            if ($data === $default) $data = Async::await(Async::await($this->loadDataByKey($key, $default)));
            return $data;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function addData(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    public function removeData(array $data): void
    {
        $this->data = array_diff($this->data, $data);
    }

    public function clearData(): void
    {
        $this->data = [];
    }

    /**
     * @throws Throwable
     */
    public function setNestedAsync(string $key, $value): Promise
    {
        return Promise::c(function ($resolve, $reject) use ($key, $value) {
            try {
                $keys = explode('.', $key);
                $data = &$this->data;
                foreach ($keys as $key) {
                    $data = &$data[$key];
                    FiberManager::wait();
                }
                $data = $value;
                $resolve(true);
            } catch (Exception $e) {
                $reject($e);
            }
        });
    }

    /**
     * @throws Throwable
     * @return Async<mixed>
     */
    public function getNestedAsync(string $key, mixed $default = null): Async
    {
        return new Async(function () use ($key, $default): mixed {
            $keys = explode('.', $key);
            $keyFirst = explode('.', $key)[0];
            $data = $this->data[$keyFirst] ?? $default;
            if ($data === $default) Async::await($this->loadDataByKey($keyFirst, $default));
            $data = &$this->data;
            foreach ($keys as $key) {
                if (!isset($data[$key])) return $default;
                $data = $data[$key];
                FiberManager::wait();
            }
            return $data;
        });
    }

    public function getNested(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $data = $this->data[$keys[0]] ?? $default;
        if ($data === $default) $data = $this->get($keys[0], $default);
        foreach ($keys as $key) {
            if (!isset($data[$key])) return $default;
            $data = $data[$key];
        }
        return $data;
    }

    public function setNested(string $key, $value): void
    {
        $keys = explode('.', $key);
        $data = &$this->data;
        foreach ($keys as $key) $data = &$data[$key];
        $data = $value;
    }

    private function encodeData(string $data): string
    {
        if ($this->hashData === true) {
            return base64_encode(gzcompress($data));
        } elseif (is_callable($this->hashData)) {
            return call_user_func($this->hashData, $data);
        }
        return $data;
    }

    private function decodeData(string $data): string
    {
        if ($this->hashData === true) {
            return gzuncompress(base64_decode($data));
        } elseif (is_callable($this->hashData)) {
            return call_user_func($this->hashData, $data);
        }
        return $data;
    }

    private function generateKey(string $key): string
    {
        return md5($key);
    }

    /**
     * @throws Throwable
     * @throws JsonException
     * @return Async<mixed>
     */
    private function loadDataByKey(string $key, mixed $default = null): Async
    {
        return new Async(function () use ($key, $default): mixed {
            if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
                $config = new Config($this->name, $this->type);
                $data = $config->get($key, $default);
                $data ?? $this->data[$key] = $data;
                return $data;
            } elseif ($this->database instanceof MySQL || $this->database instanceof SQLite) {
                try {
                    $data = Async::await($this->database->execute("SELECT * FROM `{$key}`"));
                    if ($data instanceof ResultQuery && $data->getStatus() === ResultQuery::SUCCESS) {
                        $result = array_column($data->getResult(), "value");
                        if (count($result) > 0) {
                            $this->data[$key] = json_decode($this->decodeData(implode("", $result)), true, 512, JSON_THROW_ON_ERROR);
                        }
                        return $this->data[$key];
                    }
                } catch (Throwable $e) {
                    Server::getInstance()->getLogger()->error($e->getMessage());
                }
            } else {
                throw new Exception("DataStorage: The database is not supported.");
            }
            return $default;
        });
    }

    /**
     * @throws JsonException
     * @throws Throwable
     */
    public function saveAsync(): void
    {
        if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
            $config = new Config($this->name, $this->type);
            $config->setAll(array_merge($config->getAll(), $this->data));
            $config->save();
        } else {
            if ($this->database !== null && $this->promiseProcess === null) {
                $this->promiseProcess = new Async(function (): void {
                    try {
                        foreach ($this->data as $key => $value) {
                            $generateKey = $this->generateKey($key);
                            try {
                                if ($this->database instanceof MySQL) {
                                    Async::await($this->database->execute("CREATE TABLE IF NOT EXISTS `{$key}` (`key` VARCHAR(255) PRIMARY KEY, `value` LONGTEXT UNIQUE, FULLTEXT (`value`))"));
                                } elseif ($this->database instanceof SQLite) {
                                    Async::await($this->database->execute("CREATE TABLE IF NOT EXISTS {$key} (key TEXT PRIMARY KEY, value TEXT UNIQUE)"));
                                }
                            } catch (Throwable $e) {
                                Server::getInstance()->getLogger()->error($e->getMessage());
                            }
                            $dataEncoded = $this->encodeData(json_encode($value, JSON_THROW_ON_ERROR));
                            $i = 0;
                            foreach (str_split($dataEncoded, self::LIMIT_DATA) as $data) {
                                $keyData = $generateKey . "_" . $i;
                                try {
                                    $result = null;
                                    $checkExists = Async::await($this->database->execute("SELECT * FROM `{$key}` WHERE `key` = '{$keyData}'"));
                                    if ($checkExists instanceof ResultQuery && $checkExists->getStatus() === ResultQuery::SUCCESS && is_array($checkExists->getResult()) && count($checkExists->getResult()) > 0) {
                                        $result = Async::await($this->database->execute("UPDATE `{$key}` SET `value` = '{$data}' WHERE `key` = '{$keyData}'"));
                                    } else {
                                        if ($this->database instanceof MySQL) {
                                            $result = Async::await($this->database->execute("INSERT IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$keyData}', '{$data}')"));
                                        } elseif ($this->database instanceof SQLite) {
                                            $result = Async::await($this->database->execute("INSERT OR IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$keyData}', '{$data}')"));
                                        }
                                    }
                                    if ($result instanceof ResultQuery && $result->getStatus() === ResultQuery::FAILED) throw new Exception($result->getReason());
                                } catch (Throwable $e) {
                                    Server::getInstance()->getLogger()->error($e->getMessage());
                                }
                                $i++;
                            }
                        }
                        Server::getInstance()->getLogger()->debug("DataStorage: The data has been saved successfully.");
                    } catch (Throwable $e) {
                        Server::getInstance()->getLogger()->error($e->getMessage());
                    }
                });
            } else {
                if ($this->promiseProcess !== null && EventLoop::getQueue($this->promiseProcess->getId()) === null) $this->promiseProcess = null;
            }
        }
    }

    /**
     * @throws JsonException
     * @throws Throwable
     */
    public function save(): void
    {
        if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
            $config = new Config($this->name, $this->type);
            $config->setAll(array_merge($config->getAll(), $this->data));
            $config->save();
        } else {
            if ($this->database !== null && $this->promiseProcess === null) {
                foreach ($this->data as $key => $value) {
                    $generateKey = $this->generateKey($key);
                    try {
                        if ($this->database instanceof MySQL) {
                            $this->database->executeSync("CREATE TABLE IF NOT EXISTS `{$key}` (`key` VARCHAR(255) PRIMARY KEY, `value` LONGTEXT UNIQUE, FULLTEXT (`value`))");
                        } elseif ($this->database instanceof SQLite) {
                            $this->database->executeSync("CREATE TABLE IF NOT EXISTS {$key} (key TEXT PRIMARY KEY, value TEXT UNIQUE)");
                        }
                    } catch (Throwable $e) {
                        Server::getInstance()->getLogger()->error($e->getMessage());
                    }
                    $dataEncoded = $this->encodeData(json_encode($value, JSON_THROW_ON_ERROR));
                    $i = 0;
                    foreach (str_split($dataEncoded, self::LIMIT_DATA) as $data) {
                        $keyData = $generateKey . "_" . $i;
                        try {
                            $result = null;
                            $checkExists = $this->database->executeSync("SELECT * FROM `{$key}` WHERE `key` = '{$keyData}'");
                            if ($checkExists instanceof ResultQuery && $checkExists->getStatus() === ResultQuery::SUCCESS && is_array($checkExists->getResult()) && count($checkExists->getResult()) > 0) {
                                $result = $this->database->executeSync("UPDATE `{$key}` SET `value` = '{$data}' WHERE `key` = '{$keyData}'");
                            } else {
                                if ($this->database instanceof MySQL) {
                                    $result = $this->database->executeSync("INSERT IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$keyData}', '{$data}')");
                                } elseif ($this->database instanceof SQLite) {
                                    $result = $this->database->executeSync("INSERT OR IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$keyData}', '{$data}')");
                                }
                            }
                            if ($result instanceof ResultQuery && $result->getStatus() === ResultQuery::FAILED) throw new Exception($result->getReason());
                        } catch (Throwable $e) {
                            Server::getInstance()->getLogger()->error($e->getMessage());
                        }
                        $i++;
                    }
                }
                Server::getInstance()->getLogger()->debug("DataStorage: The data has been saved successfully.");
            }
        }
    }

    /**
     * @throws Throwable
     * @return Async<bool>
     */
    public function remove(string $key): Async
    {
        return new Async(function () use ($key): bool {
            try {
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                } else {
                    $data = Async::await($this->loadDataByKey($key));
                    if ($data !== null) unset($this->data[$key]);
                }
                return true;
            } catch (Throwable $e) {
                Server::getInstance()->getLogger()->error($e->getMessage());
                return false;
            }
        });
    }

    /**
     * @throws Throwable
     * @throws JsonException
     * @return Async<array>
     */
    public function getAllAsync(): Async
    {
        return new Async(function (): array {
            $handler = [];
            try {
                if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
                    $config = new Config($this->name, $this->type);
                    $handler = $config->getAll();
                } else {
                    if ($this->database !== null) {
                        if ($this->database instanceof MySQL || $this->database instanceof SQLite) {
                            $data = Async::await($this->database->execute("SHOW TABLES"));
                            if ($data instanceof ResultQuery && $data->getStatus() === ResultQuery::SUCCESS) {
                                $tables = array_column($data->getResult(), "Tables_in_" . $this->database->getDatabaseName());
                                foreach ($tables as $table) {
                                    $data = Async::await($this->database->execute("SELECT * FROM `{$table}`"));
                                    if ($data instanceof ResultQuery && $data->getStatus() === ResultQuery::SUCCESS) {
                                        $result = array_column($data->getResult(), "value");
                                        if (count($result) > 0) {
                                            $handler[$table] = json_decode($this->decodeData(implode("", $result)), true, 512, JSON_THROW_ON_ERROR);
                                        }
                                    }
                                }
                            }
                        } else {
                            throw new Exception("DataStorage: The database is not supported.");
                        }
                    }
                }
                Server::getInstance()->getLogger()->debug("DataStorage: The data has been loaded successfully.");
            } catch (Throwable $e) {
                Server::getInstance()->getLogger()->error($e->getMessage());
            }
            return $handler;
        });
    }

    /**
     * @throws Throwable
     * @return Async<array>
     */
    public function loadAllData(): Async
    {
        return new Async(function (): array {
            $this->data = Async::await($this->getAllAsync());
            Server::getInstance()->getLogger()->debug("DataStorage: The data has been loaded successfully.");
            return $this->data;
        });
    }

}