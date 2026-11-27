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

use pocketmine\command\CommandSender;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Called when jukebox hearing is muted or unmuted.
 */
class JukeboxMuteEvent extends BVCEvent implements Cancellable {
	use CancellableTrait;

	public function __construct(
		private CommandSender $sender,
		private bool $muted
	) {
	}

	public function getSender() : CommandSender {
		return $this->sender;
	}

	public function isMuted() : bool {
		return $this->muted;
	}

	public function setMuted(bool $muted) : void {
		$this->muted = $muted;
	}
}
