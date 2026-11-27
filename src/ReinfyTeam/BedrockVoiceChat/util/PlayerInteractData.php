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

namespace ReinfyTeam\BedrockVoiceChat\util;

use pocketmine\block\Jukebox;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Data carrier for jukebox right-click interactions.
 */
final class PlayerInteractData {
	public readonly Player $player;
	public readonly Jukebox $block;
	public readonly World $world;
	public readonly Vector3 $position;
	public readonly \pocketmine\event\player\PlayerInteractEvent $event;

	public function __construct(
		Player $player,
		Jukebox $block,
		World $world,
		Vector3 $position,
		\pocketmine\event\player\PlayerInteractEvent $event,
	) {
		$this->player = $player;
		$this->block = $block;
		$this->world = $world;
		$this->position = $position;
		$this->event = $event;
	}
}
