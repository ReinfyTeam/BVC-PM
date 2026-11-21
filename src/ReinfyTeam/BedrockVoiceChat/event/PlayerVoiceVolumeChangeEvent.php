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
 * Zuri attempts to enforce "vanilla Minecraft" mechanics, as well as preventing
 * players from abusing weaknesses in Minecraft or its protocol, making your server
 * more safe. Organized in different sections, various checks are performed to test
 * players doing, covering a wide range including flying and speeding, fighting
 * hacks, fast block breaking and nukers, inventory hacks, chat spam and other types
 * of malicious behaviour.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\event;

use pocketmine\command\CommandSender;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called when a player's voice volume is changed.
 */
class PlayerVoiceVolumeChangeEvent extends BVCEvent implements Cancellable {
	use CancellableTrait;

	public function __construct(
		private Player $player,
		private Player|string $target,
		private int $oldVolume,
		private int $newVolume,
		private ?CommandSender $initiator = null
	) {}

	public function getPlayer() : Player {
		return $this->player;
	}

	public function getTarget() : Player|string {
		return $this->target;
	}

	public function getOldVolume() : int {
		return $this->oldVolume;
	}

	public function getNewVolume() : int {
		return $this->newVolume;
	}

	public function setNewVolume(int $newVolume) : void {
		$this->newVolume = $newVolume;
	}

	public function getInitiator() : ?CommandSender {
		return $this->initiator;
	}
}
