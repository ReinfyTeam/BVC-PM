<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\event;

use pocketmine\block\Jukebox;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\service\AudioService;
use ReinfyTeam\BedrockVoiceChat\service\PositionService;
use ReinfyTeam\BedrockVoiceChat\util\PlayerInteractData;

/**
 * Listens to game events and delegates to the appropriate services.
 */
final class BVCListener implements Listener{

    private BVC $plugin;
    private AudioService $audioService;
    private PositionService $positionService;

    public function __construct(BVC $plugin, AudioService $audioService, PositionService $positionService){
        $this->plugin = $plugin;
        $this->audioService = $audioService;
        $this->positionService = $positionService;
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void{
        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }
        $block = $event->getBlock();
        if(!$block instanceof Jukebox){
            return;
        }
        $player = $event->getPlayer();
        $position = $block->getPosition();
        $world = $position->getWorld();

        $data = new PlayerInteractData($player, $block, $world, $position, $event);
        $this->audioService->onJukeboxRightClick($data);
    }

    public function onBlockBreak(BlockBreakEvent $event): void{
        $block = $event->getBlock();
        if(!$block instanceof Jukebox){
            return;
        }
        $position = $block->getPosition();
        $this->audioService->onBlockBreak($position->getWorld(), $position);
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void{
        $this->positionService->markDead($event->getPlayer());
    }

    public function onPlayerRespawn(PlayerRespawnEvent $event): void{
        $this->positionService->unmarkDead($event->getPlayer());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void{
        $player = $event->getPlayer();
        $this->positionService->unmarkDead($player);
        $this->positionService->sendDisconnectUpdate($player);
        $this->plugin->voiceService->removePlayerState($player);
        $this->plugin->userManager->removeUser($player);
    }
}
