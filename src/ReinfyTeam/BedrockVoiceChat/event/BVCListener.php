<?php

/*
 *
 *  ____           _            __           _____
 * |  _ \    ___  (_)  _ __    / _|  _   _  |_   _|   ___    __ _   _ __ ___
 * | |_) |  / _ \ | | | '_ \  | |_  | | | |   | |    / _ \  / _` | | '_ ` _ \
 * |  _ <  |  __/ | | | | | | |  _| | |_| |   | |   |  __/ | (_| | | | | | | |
 * |_| \_\  \___| |_| |_| |_| |_|    \__, |   |_|    \___|  \__,_| |_| |_| |_|
 *                                   |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BedrockVoiceChat is a high-performance, fully asynchronous proximity voice chat
 * bridge for PocketMine-MP 5.36.0+ servers. Designed to handle 100+ concurrent players
 * without causing server tick drops or main-thread lag.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\event;

use pocketmine\block\Jukebox;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\service\AudioService;
use ReinfyTeam\BedrockVoiceChat\service\PositionService;
use ReinfyTeam\BedrockVoiceChat\util\PlayerInteractData;

/**
 * Listens to game events and delegates to the appropriate services.
 */
final class BVCListener implements Listener {
	private BVC $plugin;
	private AudioService $audioService;
	private PositionService $positionService;

	public function __construct(BVC $plugin, AudioService $audioService, PositionService $positionService) {
		$this->plugin = $plugin;
		$this->audioService = $audioService;
		$this->positionService = $positionService;
	}

	public function onPlayerInteract(PlayerInteractEvent $event) : void {
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
			return;
		}
		$block = $event->getBlock();
		if (!$block instanceof Jukebox) {
			return;
		}
		$player = $event->getPlayer();
		$position = $block->getPosition();
		$world = $position->getWorld();

		$data = new PlayerInteractData($player, $block, $world, $position, $event);
		$this->audioService->onJukeboxRightClick($data);
	}

	public function onBlockBreak(BlockBreakEvent $event) : void {
		$block = $event->getBlock();
		if (!$block instanceof Jukebox) {
			return;
		}
		$position = $block->getPosition();
		$this->audioService->onBlockBreak($position->getWorld(), $position);
	}

	public function onPlayerDeath(PlayerDeathEvent $event) : void {
		$this->positionService->markDead($event->getPlayer());
	}

	public function onPlayerRespawn(PlayerRespawnEvent $event) : void {
		$this->positionService->unmarkDead($event->getPlayer());
	}

	public function onPlayerQuit(PlayerQuitEvent $event) : void {
		$player = $event->getPlayer();
		$this->positionService->unmarkDead($player);
		$this->plugin->getVoiceService()->removePlayerState($player);
		$this->plugin->getUserManager()->removeUser($player);
	}
}
