<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems;

use Throwable;
use Exception;
use pocketmine\plugin\PluginBase;
use vennv\vapm\System;
use vennv\vapm\VapmPMMP;

trait VDataStorageSystems
{
    use handler\StorageHandler;

    private static int $period_task = 30 * 60; // Default 30 minutes

    public static function initVDataStorageSystems(PluginBase $plugin): void
    {
        VapmPMMP::init($plugin); // Init VAPM

        /**
         * @throws Throwable
         */
        $functionErrorHandler = function ($error = null): void {
            if ($error instanceof Exception) {
                echo "Exception at: " . $error->getMessage() . "\n";
                echo "File: " . $error->getFile() . "\n";
                echo "Line: " . $error->getLine() . "\n";
            }
            self::saveAll();
            System::runSingleEventLoop();
        };
        set_error_handler($functionErrorHandler);
        set_exception_handler($functionErrorHandler);

        $plugin->getScheduler()->scheduleRepeatingTask(new tasks\ServerTickTask($plugin), self::$period_task);
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
