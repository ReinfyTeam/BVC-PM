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

use pocketmine\player\Player;
use function max;
use function min;

/**
 * Tracks the voice chat state for a single player.
 */
final class PlayerState {
	public string $name;
	public bool $muted = false;
	public bool $deafened = false;
	public bool $speaking = false;
	public bool $recording = false;
	public bool $connected = false;
	public float $volume = 1.0; // 0.0 - 1.5
	public bool $hearingOthers = true;
	public ?string $group = null;

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function isMuted() : bool {
		return $this->muted;
	}

	public function setMuted(bool $muted) : void {
		$this->muted = $muted;
	}

	public function isDeafened() : bool {
		return $this->deafened;
	}

	public function setDeafened(bool $deafened) : void {
		$this->deafened = $deafened;
	}

	public function isSpeaking() : bool {
		return $this->speaking;
	}

	public function isTalking() : bool {
		return $this->speaking;
	}

	public function setSpeaking(bool $speaking) : void {
		$this->speaking = $speaking;
	}

	public function isRecording() : bool {
		return $this->recording;
	}

	public function setRecording(bool $recording) : void {
		$this->recording = $recording;
	}

	public function isConnected() : bool {
		return $this->connected;
	}

	public function setConnected(bool $connected) : void {
		$this->connected = $connected;
	}

	public function getVolume() : float {
		return $this->volume;
	}

	public function setVolume(float $volume) : void {
		$this->volume = max(0.0, min(1.5, $volume));
	}

	public function isHearingOthers() : bool {
		return $this->hearingOthers;
	}

	public function setHearingOthers(bool $hearing) : void {
		$this->hearingOthers = $hearing;
	}

	public function getGroup() : ?string {
		return $this->group;
	}

	public function setGroup(?string $group) : void {
		$this->group = $group;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray() : array {
		return [
			'name' => $this->name,
			'muted' => $this->muted,
			'deafened' => $this->deafened,
			'speaking' => $this->speaking,
			'recording' => $this->recording,
			'connected' => $this->connected,
			'volume' => $this->volume,
			'hearingOthers' => $this->hearingOthers,
			'group' => $this->group,
		];
	}
}
