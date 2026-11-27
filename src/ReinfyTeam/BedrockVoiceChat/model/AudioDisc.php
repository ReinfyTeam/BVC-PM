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

namespace ReinfyTeam\BedrockVoiceChat\model;

use pocketmine\world\World;

/**
 * Tracks an active BVC audio disc placed in a jukebox.
 */
final class AudioDisc {
	public readonly string $key;
	public ?string $eventId = null;
	public readonly string $audioId;
	public readonly World $world;
	public readonly int $x;
	public readonly int $y;
	public readonly int $z;

	public function __construct(
		string $key,
		string $audioId,
		World $world,
		int $x,
		int $y,
		int $z,
	) {
		$this->key = $key;
		$this->audioId = $audioId;
		$this->world = $world;
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
	}

	public function hasEvent() : bool {
		return $this->eventId !== null;
	}
}
