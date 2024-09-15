<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems;

use ErrorException;
use Throwable;
use pocketmine\plugin\PluginBase;
use venndev\verrorhandler\VErrorException;
use venndev\verrorhandler\VErrorHandler;
use vennv\vapm\Async;
use vennv\vapm\VapmPMMP;

trait VDataStorageSystems
{
    use handler\StorageHandler;

    private static int $period_task = 30 * 60 * 20; // Default 30 minutes

    /**
     * @throws VErrorException
     */
    public static function initVDataStorageSystems(PluginBase $plugin, object $dataStorage): void
    {
        VapmPMMP::init($plugin); // Init VAPM
        VErrorHandler::init(); // Init VErrorHandler

        /**
         * @throws Throwable
         */
        VErrorHandler::register(function (string|int $errorMessage) use ($plugin): void {
            new Async(function () use ($errorMessage): void {
                Async::await(self::saveAllAsync());
                throw new ErrorException($errorMessage);
            });
        });

        $plugin->getScheduler()->scheduleRepeatingTask(new tasks\ServerTickTask($plugin, $dataStorage), self::$period_task);
    }

    public static function setPeriodTask(int $period_task): void
    {
        self::$period_task = $period_task;
    }

    public static function getPeriodTask(): int
    {
        return self::$period_task;
    }

}