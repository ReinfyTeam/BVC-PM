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
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Called when a BVC audio disc is ejected from a jukebox.
 */
final class JukeboxEjectAudioDiscEvent extends BVCEvent implements Cancellable {
	use CancellableTrait;

	public function __construct(
		public readonly ?Player $player,
		public readonly Jukebox $jukebox,
		public readonly string $audioId,
		public readonly Position $position,
	) {
	}

	public function getPlayer() : ?Player {
		return $this->player;
	}

	public function getJukebox() : Jukebox {
		return $this->jukebox;
	}

	public function getAudioId() : string {
		return $this->audioId;
	}

	public function getPosition() : Position {
		return $this->position;
	}
}
