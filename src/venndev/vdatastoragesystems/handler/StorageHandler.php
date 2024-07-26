<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems\handler;

use Exception;
use Throwable;
use venndev\vapmdatabase\database\mysql\MySQL;
use venndev\vapmdatabase\database\sqlite\SQLite;
use vennv\vapm\Async;
use vennv\vapm\FiberManager;

trait StorageHandler
{

    private static array $storages = [];

    public static function getStorage(string $name): ?DataStorage
    {
        return self::$storages[$name] ?? null;
    }

    /**
     * @throws Exception
     */
    public static function createStorage(string $name, int $type, MySQL|SQLite|null $database = null, mixed $hashData = false): DataStorage
    {
        if (isset(self::$storages[$name])) throw new Exception("Storage with name $name already exists");
        $storage = new DataStorage($name, $type, $database, $hashData);
        self::$storages[$name] = $storage;
        return $storage;
    }

    public static function removeStorage(string $name): void
    {
        if (isset(self::$storages[$name])) unset(self::$storages[$name]);
    }

    public static function getStorages(): array
    {
        return self::$storages;
    }

    public static function clearStorages(): void
    {
        self::$storages = [];
    }

    public static function saveAll(): void
    {
        foreach (self::$storages as $storage) $storage->save();
    }

    /**
     * @throws Throwable
     */
    public static function saveAllAsync(): Async
    {
        return new Async(function (): void {
            foreach (self::$storages as $storage) {
                $storage->save();
                FiberManager::wait();
            }
        });
    }

}