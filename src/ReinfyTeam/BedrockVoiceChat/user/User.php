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

namespace ReinfyTeam\BedrockVoiceChat\user;

use pocketmine\player\Player;
use pocketmine\Server;
use ReinfyTeam\BedrockVoiceChat\BVC;
use function is_int;
use function max;
use function min;
use function round;

/**
 * Public API representation of a Bedrock Voice Chat user.
 * Allows checking and updating voice states: muted, deafened, speaking, recording, connected, group, etc.
 */
final class User {
	private string $name;
	private ?string $uuid = null;
	private bool $muted = false;
	private bool $deafened = false;
	private bool $speaking = false;
	private bool $recording = false;
	private bool $connected = false;
	private float $volume = 1.0;
	private bool $hearingOthers = true;
	private ?string $group = null;
	private int $lastSyncTime = 0;

	public function __construct(string $name, ?string $uuid = null) {
		$this->name = $name;
		$this->uuid = $uuid;
	}

	public function getName() : string {
		return $this->name;
	}

	public function getUuid() : ?string {
		return $this->uuid;
	}

	public function setUuid(?string $uuid) : self {
		$this->uuid = $uuid;
		return $this;
	}

	public function getPlayer() : ?Player {
		return Server::getInstance()->getPlayerExact($this->name);
	}

	public function isOnline() : bool {
		return $this->getPlayer() !== null;
	}

	/** Check if the player's microphone is muted. */
	public function isMuted() : bool {
		return $this->muted;
	}

	public function setMuted(bool $muted, bool $sendToBackend = false) : self {
		$this->muted = $muted;
		if ($sendToBackend) {
			$player = $this->getPlayer();
			if ($player !== null) {
				BVC::getInstance()->getVoiceService()->toggleMute($player, $muted);
			}
		}
		return $this;
	}

	/** Check if the player is deafened (incoming audio disabled). */
	public function isDeafened() : bool {
		return $this->deafened;
	}

	public function setDeafened(bool $deafened, bool $sendToBackend = false) : self {
		$this->deafened = $deafened;
		if ($sendToBackend) {
			$player = $this->getPlayer();
			if ($player !== null) {
				BVC::getInstance()->getVoiceService()->toggleDeafen($player, $deafened);
			}
		}
		return $this;
	}

	/** Check if the player is currently transmitting audio / speaking. */
	public function isSpeaking() : bool {
		return $this->speaking;
	}

	/** Alias for isSpeaking(). */
	public function isTalking() : bool {
		return $this->speaking;
	}

	public function setSpeaking(bool $speaking) : self {
		$this->speaking = $speaking;
		return $this;
	}

	/** Alias for setSpeaking(). */
	public function setTalking(bool $talking) : self {
		return $this->setSpeaking($talking);
	}

	/** Check if the player is recording audio. */
	public function isRecording() : bool {
		return $this->recording;
	}

	public function setRecording(bool $recording, bool $sendToBackend = false) : self {
		$this->recording = $recording;
		if ($sendToBackend) {
			$player = $this->getPlayer();
			if ($player !== null) {
				BVC::getInstance()->getVoiceService()->toggleRecording($player, $recording);
			}
		}
		return $this;
	}

	/** Check if the player has an active voice session with the BVC desktop client. */
	public function isConnected() : bool {
		return $this->connected;
	}

	public function setConnected(bool $connected) : self {
		$this->connected = $connected;
		return $this;
	}

	public function isHearingOthers() : bool {
		return $this->hearingOthers;
	}

	public function setHearingOthers(bool $hearingOthers, bool $sendToBackend = false) : self {
		$this->hearingOthers = $hearingOthers;
		if ($sendToBackend) {
			$player = $this->getPlayer();
			if ($player !== null) {
				BVC::getInstance()->getVoiceService()->toggleHearingAll($player, $hearingOthers);
			}
		}
		return $this;
	}

	public function getVolume() : float {
		return $this->volume;
	}

	public function getVolumePercent() : int {
		return (int) round($this->volume * 100);
	}

	public function setVolume(float|int $volume, bool $sendToBackend = false) : self {
		if (is_int($volume) && $volume > 1.5) {
			$this->volume = max(0.0, min(1.5, $volume / 100.0));
		} else {
			$this->volume = max(0.0, min(1.5, (float) $volume));
		}
		if ($sendToBackend) {
			$player = $this->getPlayer();
			if ($player !== null) {
				BVC::getInstance()->getVoiceService()->setVolume($player, $this->name, $this->getVolumePercent());
			}
		}
		return $this;
	}

	public function getGroup() : ?string {
		return $this->group;
	}

	public function hasGroup() : bool {
		return $this->group !== null && $this->group !== '';
	}

	public function setGroup(?string $group) : self {
		$this->group = $group;
		return $this;
	}

	public function getLastSyncTime() : int {
		return $this->lastSyncTime;
	}

	public function setLastSyncTime(int $time) : self {
		$this->lastSyncTime = $time;
		return $this;
	}

	/**
	 * Asynchronously refresh this user's state from the BVC backend.
	 * @param (\Closure(User $user, bool $success, string $error): void)|null $callback
	 */
	public function refresh(?\Closure $callback = null) : void {
		UserManager::getInstance()->fetchState($this->name, $callback);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray() : array {
		return [
			'name' => $this->name,
			'uuid' => $this->uuid,
			'muted' => $this->muted,
			'deafened' => $this->deafened,
			'speaking' => $this->speaking,
			'recording' => $this->recording,
			'connected' => $this->connected,
			'volume' => $this->volume,
			'hearingOthers' => $this->hearingOthers,
			'group' => $this->group,
			'lastSyncTime' => $this->lastSyncTime,
		];
	}
}
