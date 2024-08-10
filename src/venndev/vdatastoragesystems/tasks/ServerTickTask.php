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
        private readonly PluginBase $plugin,
        private readonly object     $dataStorage
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
            $traitsPlugin = $reflection->getTraits();
            $reflection = new ReflectionClass($this->dataStorage);
            $traitsDataStorage = $reflection->getTraits();
            $plugin = null;
            if (isset($traitsPlugin[VDataStorageSystems::class])) {
                $plugin = $this->plugin;
            } elseif (isset($traitsDataStorage[VDataStorageSystems::class])) {
                $plugin = $this->dataStorage;
            }
            if ($plugin === null) return;
            $this->promiseProcess = new Async(function () use ($plugin): void {
                /** @var VDataStorageSystems|PluginBase $plugin */
                Async::await($plugin::saveAllAsync());
                $this->promiseProcess = null;
            });
        }
    }

    public function getPlugin(): PluginBase
    {
        return $this->plugin;
    }

    public function getDataStorage(): object
    {
        return $this->dataStorage;
    }

}