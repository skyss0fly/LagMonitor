<?php
namespace skyss0fly\LagMonitor;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase implements Listener {

    private $lowTpsThreshold = 18.0; // TPS below this is considered laggy
    private $highMemoryThreshold = 80; // Percentage of memory usage considered high
    private $activePlayers = [];

    public function onEnable(): void {
        // Register event listeners for player movement tracking
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Save default config
        $this->saveDefaultConfig();

        // Schedule TPS monitoring every 60 seconds
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
            $this->checkServerPerformance();
        }), 1200); // 60 seconds = 1200 ticks (20 ticks per second)
        
        $this->getLogger()->info(TF::GREEN . "LagMonitor has been enabled.");
    }

    // Command handler for admin commands
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

    // Function to check server performance (called automatically or via command)
    private function checkServerPerformance(): void {
        $tps = $this->getServer()->getTicksPerSecond();
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // Memory usage in MB
        $memoryLimit = ini_get('memory_limit');
        $usedPercentage = ($memoryUsage / $this->getMemoryLimitInMB($memoryLimit)) * 100;

        if ($tps < $this->lowTpsThreshold) {
            $this->getServer()->broadcastMessage(TF::RED . "Warning: Low TPS detected! Current TPS: " . round($tps, 2));
        }

        if ($usedPercentage > $this->highMemoryThreshold) {
            $this->getServer()->broadcastMessage(TF::RED . "Warning: High memory usage! Current usage: " . round($usedPercentage, 2) . "%");
        }
    }

    // Display performance stats to the command sender
    private function displayPerformanceStats(CommandSender $sender): void {
        $tps = $this->getServer()->getTicksPerSecond();
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // Memory usage in MB
        $memoryLimit = ini_get('memory_limit');
        $usedPercentage = ($memoryUsage / $this->getMemoryLimitInMB($memoryLimit)) * 100;

        $sender->sendMessage(TF::AQUA . "Performance Stats:");
        $sender->sendMessage(TF::YELLOW . "Current TPS: " . round($tps, 2));
        $sender->sendMessage(TF::YELLOW . "Memory Usage: " . round($memoryUsage, 2) . " MB (" . round($usedPercentage, 2) . "%)");
    }

    // Function to optimize server by clearing unused entities, unloading chunks, etc.
    private function optimizeServer(CommandSender $sender): void {
        $this->clearUnusedEntities();
        $this->unloadUnusedChunks();
        $this->unloadInactivePlayerChunks();
        $this->clearInactivePlayerData();
        
        $sender->sendMessage(TF::GREEN . "Server optimized: Cleared entities, unloaded chunks, handled inactive player data.");
    }

    // Clear unused entities based on config
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

    // Unload unused chunks to free up resources
    private function unloadUnusedChunks(): void {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $world->unloadChunks(true);
        }
        $this->getServer()->broadcastMessage(TF::YELLOW . "Unloaded unused chunks.");
    }

    // Unload chunks around inactive players
    private function unloadInactivePlayerChunks(): void {
        $inactivityLimit = 300; // 5 minutes

        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $lastActive = $this->activePlayers[$player->getName()] ?? time();
            if (time() - $lastActive > $inactivityLimit) {
                $player->getWorld()->unloadChunks(true);
                $player->sendMessage(TF::YELLOW . "Chunks around you have been unloaded due to inactivity.");
            }
        }
    }

    // Clear inactive player data (e.g., players who haven't logged in for 1 week)
    private function clearInactivePlayerData(): void {
        $inactivityThreshold = 604800; // 1 week in seconds

        foreach ($this->getServer()->getOfflinePlayers() as $player) {
            if ($player->getLastPlayed() < (time() - $inactivityThreshold)) {
                $this->getServer()->getPlayerDataManager()->removePlayerData($player->getName());
                $this->getLogger()->info("Cleared data for inactive player: " . $player->getName());
            }
        }
    }

    // Track player movement to detect active players
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $this->activePlayers[$player->getName()] = time();
    }

    // Get the memory limit in MB
    private function getMemoryLimitInMB(string $memoryLimit): float {
        if (strpos($memoryLimit, 'M') !== false) {
            return (float)str_replace('M', '', $memoryLimit);
        } elseif (strpos($memoryLimit, 'G') !== false) {
            return (float)str_replace('G', '', $memoryLimit) * 1024;
        } else {
            return (float)$memoryLimit / 1024 / 1024; // Convert bytes to MB
        }
    }
}
