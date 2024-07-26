<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems\handler;

use Exception;
use JsonException;
use Throwable;
use pocketmine\Server;
use pocketmine\utils\Config;
use venndev\vapmdatabase\database\mysql\MySQL;
use venndev\vapmdatabase\database\ResultQuery;
use venndev\vapmdatabase\database\sqlite\SQLite;
use venndev\vdatastoragesystems\utils\TypeDataStorage;
use vennv\vapm\FiberManager;
use vennv\vapm\Async;
use vennv\vapm\Promise;

final class DataStorage
{

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

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @throws Throwable
     */
    public function get(string $key, mixed $default = null): Async
    {
        return new Async(function () use ($key, $default): mixed {
            $data = $this->data[$key] ?? $default;
            if ($data === $default) $data = Async::await($this->loadDataByKey($key));
            return $data;
        });
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
     */
    public function getNested(string $key, mixed $default = null): Async
    {
        return new Async(function () use ($key, $default): mixed {
            $keys = explode('.', $key);
            $keyFirst = explode('.', $key)[0];
            $data = $this->data[$keyFirst] ?? $default;
            if ($data === $default) Async::await($this->loadDataByKey($keyFirst));
            $data = &$this->data;
            foreach ($keys as $key) {
                if (!isset($data[$key])) return $default;
                $data = $data[$key];
                FiberManager::wait();
            }
            return $data;
        });
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
     */
    private function loadDataByKey(string $key): Async
    {
        return new Async(function () use ($key): mixed {
            if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
                $config = new Config($this->name, $this->type);
                $data = $config->get($key, null);
                $data ?? $this->data[$key] = $data;
                return $data;
            } else {
                if ($this->database !== null) {
                    if ($this->database instanceof MySQL || $this->database instanceof SQLite) {
                        $data =  Async::await($this->database->execute("SELECT * FROM `{$key}`"));
                        try {
                            if ($data instanceof ResultQuery && $data->getStatus() === ResultQuery::SUCCESS) {
                                $result = $data->getResult()[0] ?? $data->getResult();
                                if (count($result) > 0) {
                                    $this->data[$key] = json_decode($this->decodeData($result["value"]), true, 512, JSON_THROW_ON_ERROR);
                                }
                                return $this->data[$key];
                            } else {
                                Server::getInstance()->getLogger()->error($data->getReason());
                            }
                        } catch (Throwable $e) {
                            Server::getInstance()->getLogger()->error($e->getMessage());
                        }
                    } else {
                        throw new Exception("DataStorage: The database is not supported.");
                    }
                }
            }
            return null;
        });
    }

    /**
     * @throws JsonException
     * @throws Throwable
     */
    public function save(bool $async = true): void
    {
        if ($this->type !== TypeDataStorage::TYPE_MYSQL && $this->type !== TypeDataStorage::TYPE_SQLITE) {
            $config = new Config($this->name, $this->type);
            $config->setAll(array_merge($config->getAll(), $this->data));
            $config->save();
        } else {
            if ($this->database !== null) {
                if ($async && $this->promiseProcess === null) {
                    $this->promiseProcess = new Async(function (): void {
                        foreach ($this->data as $key => $value) {
                            $generateKey = $this->generateKey($key);
                            if ($this->database instanceof MySQL) {
                                Async::await($this->database->execute("CREATE TABLE IF NOT EXISTS `{$key}` (`key` VARCHAR(255) PRIMARY KEY, `value` LONGTEXT UNIQUE, FULLTEXT (`value`))"));
                            } elseif ($this->database instanceof SQLite) {
                                Async::await($this->database->execute("CREATE TABLE IF NOT EXISTS {$key} (key TEXT PRIMARY KEY, value TEXT UNIQUE)"));
                            } else {
                                throw new Exception("DataStorage: The database is not supported.");
                            }
                            $dataEncoded = $this->encodeData(json_encode($value, JSON_THROW_ON_ERROR));
                            if (count(str_split($dataEncoded, 4294967295)) <= 1) {
                                try {
                                    $check = Async::await($this->database->execute("SELECT * FROM `{$key}`"));
                                    if ($check instanceof ResultQuery && $check->getStatus() === ResultQuery::SUCCESS && is_array($check->getResult()) && count($check->getResult()) > 0) {
                                        $result = Async::await($this->database->execute("UPDATE `{$key}` SET `value` = '{$dataEncoded}' WHERE `key` = '{$generateKey}'"));
                                    } else {
                                        if ($this->database instanceof MySQL) {
                                            $result = Async::await($this->database->execute("INSERT INTO `{$key}` (`key`, `value`) VALUES ('{$generateKey}', '{$dataEncoded}')"));
                                        } elseif ($this->database instanceof SQLite) {
                                            $result = Async::await($this->database->execute("INSERT OR IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$generateKey}', '{$dataEncoded}')"));
                                        };
                                    }
                                    if ($result instanceof ResultQuery && $result->getStatus() === ResultQuery::FAILED) throw new Exception($result->getReason());
                                } catch (Throwable $e) {
                                    Server::getInstance()->getLogger()->error($e->getMessage());
                                }
                            } else {
                                Server::getInstance()->getLogger()->warning("DataStorage: The data is too large to be saved in the database, the data will be saved in the file.");
                            }
                            FiberManager::wait();
                        }
                        Server::getInstance()->getLogger()->debug("DataStorage: The data has been saved successfully.");
                        $this->promiseProcess = null;
                    });
                } else {
                    foreach ($this->data as $key => $value) {
                        $generateKey = $this->generateKey($key);
                        if ($this->database instanceof MySQL) {
                            $this->database->execute("CREATE TABLE IF NOT EXISTS `{$key}` (`key` TEXT PRIMARY KEY, `value` LONGTEXT UNIQUE, FULLTEXT (`value`))");
                        } elseif ($this->database instanceof SQLite) {
                            $this->database->execute("CREATE TABLE IF NOT EXISTS {$key} (key TEXT PRIMARY KEY, value TEXT UNIQUE)");
                        } else {
                            throw new Exception("DataStorage: The database is not supported.");
                        }
                        $dataEncoded = $this->encodeData(json_encode($value, JSON_THROW_ON_ERROR));
                        if (count(str_split($dataEncoded, 4294967295)) <= 1) {
                            $this->database->execute("SELECT * FROM `{$key}`")->then(function (ResultQuery $check) use ($key, $dataEncoded, $generateKey): void {
                                try {
                                    if ($check->getStatus() === ResultQuery::SUCCESS && is_array($check->getResult()) && count($check->getResult()) > 0) {
                                        $this->database->execute("UPDATE `{$key}` SET `value` = '{$dataEncoded}' WHERE `key` = '{$generateKey}'")->then(function (ResultQuery $result) use ($key): void {
                                            if ($result->getStatus() === ResultQuery::FAILED) throw new Exception($result->getReason());
                                        });
                                    } else {
                                        if ($this->database instanceof MySQL) {
                                            $this->database->execute("INSERT INTO `{$key}` (`key`, `value`) VALUES ('{$generateKey}', '{$dataEncoded}')");
                                        } elseif ($this->database instanceof SQLite) {
                                            $this->database->execute("INSERT OR IGNORE INTO `{$key}` (`key`, `value`) VALUES ('{$generateKey}', '{$dataEncoded}')");
                                        };
                                    }
                                } catch (Throwable $e) {
                                    Server::getInstance()->getLogger()->error($e->getMessage());
                                }
                            });
                        } else {
                            Server::getInstance()->getLogger()->warning("DataStorage: The data is too large to be saved in the database, the data will be saved in the file.");
                        }
                    }
                    Server::getInstance()->getLogger()->debug("DataStorage: The data has been saved successfully.");
                }
            }
        }
    }

}