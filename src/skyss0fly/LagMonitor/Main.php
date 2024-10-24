<?php
namespace skyss0fly\LagMonitor;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\utils\TextFormat as TF;
use pocketmine\player\Player;

class Main extends PluginBase implements Listener {

    private $lowTpsThreshold = 18.0;
    private $highMemoryThreshold = 80;
    private $activePlayers = [];

    public function onEnable(): void {
        // Register event listeners
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Save default config
        $this->saveDefaultConfig();

        // Schedule TPS monitoring every 60 seconds
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
            $this->checkServerPerformance();
        }), 1200); // 60 seconds = 1200 ticks

        $this->getLogger()->info(TF::GREEN . "LagMonitor has been enabled.");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "checkperformance":
                $this->displayPerformanceStats($sender);
                return true;
            case "optimize":
                $this->optimizeServer($sender);
                return true;
        }
        return false;
    }

    private function checkServerPerformance(): void {
        $tps = $this->getServer()->getTicksPerSecond();
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $memoryLimit = ini_get('memory_limit');
        $usedPercentage = ($memoryUsage / $this->getMemoryLimitInMB($memoryLimit)) * 100;

        if ($tps < $this->lowTpsThreshold) {
            $this->getServer()->broadcastMessage(TF::RED . "Warning: Low TPS detected! Current TPS: " . round($tps, 2));
        }

        if ($usedPercentage > $this->highMemoryThreshold) {
            $this->getServer()->broadcastMessage(TF::RED . "Warning: High memory usage! Current usage: " . round($usedPercentage, 2) . "%");
        }
    }

    private function displayPerformanceStats(CommandSender $sender): void {
        $tps = $this->getServer()->getTicksPerSecond();
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $memoryLimit = ini_get('memory_limit');
        $usedPercentage = ($memoryUsage / $this->getMemoryLimitInMB($memoryLimit)) * 100;

        $sender->sendMessage(TF::AQUA . "Performance Stats:");
        $sender->sendMessage(TF::YELLOW . "Current TPS: " . round($tps, 2));
        $sender->sendMessage(TF::YELLOW . "Memory Usage: " . round($memoryUsage, 2) . " MB (" . round($usedPercentage, 2) . "%)");
    }

    private function optimizeServer(CommandSender $sender): void {
        $this->clearUnusedEntities();
        $this->unloadUnusedChunks();
        $this->unloadInactivePlayerChunks();
        $this->clearInactivePlayerData();
        
        $sender->sendMessage(TF::GREEN . "Server optimized: Cleared entities, unloaded chunks, handled inactive player data.");
    }

    private function clearUnusedEntities(): void {
        $entityTypes = $this->getConfig()->get("cleanup-entities", ["ItemEntity"]);

        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                foreach ($entityTypes as $entityType) {
                    $className = "pocketmine\\entity\\{$entityType}";
                    if ($entity instanceof $className) {
                        $entity->flagForDespawn();
                    }
                }
            }
        }
        $this->getServer()->broadcastMessage(TF::YELLOW . "Cleared unused entities based on config.");
    }

    private function unloadUnusedChunks(): void {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $world->unloadChunks(true);
        }
        $this->getServer()->broadcastMessage(TF::YELLOW . "Unloaded unused chunks.");
    }

    private function unloadInactivePlayerChunks(): void {
        $inactivityLimit = 300;

        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $lastActive = $this->activePlayers[$player->getName()] ?? time();
            if (time() - $lastActive > $inactivityLimit) {
                $player->getWorld()->unloadChunks(true);
                $player->sendMessage(TF::YELLOW . "Chunks around you have been unloaded due to inactivity.");
            }
        }
    }

    private function clearInactivePlayerData(): void {
        $inactivityThreshold = 604800;
        $playerDataDir = $this->getServer()->getDataPath() . "players/";

        foreach (scandir($playerDataDir) as $file) {
            if ($file !== "." && $file !== "..") {
                $filePath = $playerDataDir . $file;
                if (filemtime($filePath) < (time() - $inactivityThreshold)) {
                    unlink($filePath); // Delete inactive player's data file
                    $this->getLogger()->info("Cleared data for inactive player: " . $file);
                }
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $this->activePlayers[$player->getName()] = time();
    }

    private function getMemoryLimitInMB(string $memoryLimit): float {
        if (strpos($memoryLimit, 'M') !== false) {
            return (float)str_replace('M', '', $memoryLimit);
        } elseif (strpos($memoryLimit, 'G') !== false) {
            return (float)str_replace('G', '', $memoryLimit) * 1024;
        } else {
            return (float)$memoryLimit / 1024 / 1024;
        }
    }
}
