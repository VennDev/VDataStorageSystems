<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems\tasks;

use ReflectionClass;
use Throwable;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use venndev\vdatastoragesystems\VDataStorageSystems;
use vennv\vapm\Async;

final class ServerTickTask extends Task
{

    private ?Async $promiseProcess = null;

    public function __construct(
        private readonly PluginBase $plugin
    )
    {
        //TODO: Implement __construct() method.
    }

    /**
     * @throws Throwable
     */
    public function onRun(): void
    {
        if ($this->promiseProcess === null) {
            $reflection = new ReflectionClass($this->plugin);
            $traits = $reflection->getTraits();
            if (isset($traits[VDataStorageSystems::class])) {
                $this->promiseProcess = new Async(function (): void {
                    /** @var VDataStorageSystems|PluginBase $plugin */
                    $plugin = $this->plugin;
                    Async::await($plugin::saveAllAsync());
                    $this->promiseProcess = null;
                });
            }
        }
    }

    public function getPlugin(): PluginBase
    {
        return $this->plugin;
    }

}