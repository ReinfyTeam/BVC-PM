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

namespace ReinfyTeam\BedrockVoiceChat\service;

use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function array_chunk;
use function count;
use function floor;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function max;
use function microtime;
use function min;
use function rtrim;
use function trim;

/**
 * Periodically polls online player positions and sends them to the BVC backend.
 * Uses batching to handle 100+ players efficiently without blocking the main thread.
 */
final class PositionService {
	private BVC $plugin;
	private ConfigManager $config;
	private HttpClient $http;

	private int $minimumPlayers = 1;
	private int $batchSize = 50;
	private bool $enablePolling = true;
	/** @var array<string, string> */
	private array $dimensionMap = [];
	private string $worldUuid = '';

	/** @var array<string, true> */
	private array $deadPlayers = [];

	private int $consecutiveFailures = 0;
	private int $circuitOpenUntil = 0;
	private bool $positionRequestPending = false;

	public function __construct(BVC $plugin, ConfigManager $config, HttpClient $http) {
		$this->plugin = $plugin;
		$this->config = $config;
		$this->http = $http;
	}

	public function loadConfig() : void {
		$minimumPlayersValue = $this->config->get('minimum_players', 1);
		$this->minimumPlayers = max(1, is_int($minimumPlayersValue) ? $minimumPlayersValue : 1);
		$batchSizeValue = $this->config->getPerformance('position_batch_size', 50);
		$this->batchSize = max(1, is_int($batchSizeValue) ? $batchSizeValue : 50);
		$this->enablePolling = (bool) $this->config->getPerformance('enable_position_polling', true);
		$rawDimensionMap = $this->config->get('dimension_map', []);
		$this->dimensionMap = [];
		if (is_array($rawDimensionMap)) {
			foreach ($rawDimensionMap as $folder => $dimension) {
				if (is_string($folder) && is_string($dimension)) {
					$this->dimensionMap[$folder] = $dimension;
				}
			}
		}
		$worldUuidValue = $this->config->get('world_uuid', '');
		if (!is_string($worldUuidValue)) {
			$worldUuidValue = '';
		}
		$this->worldUuid = trim($worldUuidValue);
	}

	public function setWorldUuid(string $uuid) : void {
		$this->worldUuid = $uuid;
	}

	public function isEnabled() : bool {
		return $this->enablePolling;
	}

	public function markDead(Player $player) : void {
		$this->deadPlayers[$player->getUniqueId()->toString()] = true;
		$this->plugin->debug("[PositionService] Player marked dead: " . $player->getName());
	}

	public function unmarkDead(Player $player) : void {
		unset($this->deadPlayers[$player->getUniqueId()->toString()]);
		$this->plugin->debug("[PositionService] Player unmarked dead: " . $player->getName());
	}

	/**
	 * Send a disconnect/phantom update for a player who left, so the backend
	 * stops routing audio for them (mirrors main.js fromDisconnectedPlayer).
	 */
	public function sendDisconnectUpdate(Player $player) : void {
		if (!$this->isEnabled() || $this->worldUuid === '') {
			return;
		}
		$name = $player->getName();
		$this->plugin->debug("[PositionService] Sending disconnect position for {$name}");
		$row = [
			'name' => $name,
			'dimension' => 'death',
			'coordinates' => ['x' => -10000, 'y' => -10000, 'z' => -10000],
			'deafen' => false,
			'orientation' => ['x' => 0, 'y' => 0],
			'spectator' => true,
			'world_uuid' => $this->worldUuid
		];
		$baseUrlValue = $this->config->get('server_url', '');
		if (!is_string($baseUrlValue)) {
			$baseUrlValue = '';
		}
		$baseUrl = rtrim($baseUrlValue, '/');
		$tokenValue = $this->config->get('access_token', '');
		if (!is_string($tokenValue)) {
			$tokenValue = '';
		}
		$headers = ['Authorization' => 'Bearer ' . trim($tokenValue), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
		$timeoutValue = $this->config->get('http_timeout_seconds', 8);
		if (!is_int($timeoutValue)) {
			$timeoutValue = 8;
		}
		$timeout = max(1, $timeoutValue);
		$body = json_encode(['game' => 'minecraft', 'players' => [$row]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($body)) {
			return;
		}
		$this->http->request('POST', $baseUrl . '/api/position', $headers, $body,
			static function(int $status, string $body, string $error) : void {
				// Fire-and-forget disconnect update
			}, $timeout);
	}

	public function pollPlayerPositions() : void {
		if (!$this->isEnabled() || $this->worldUuid === '' || $this->positionRequestPending) {
			return;
		}
		$players = $this->plugin->getServer()->getOnlinePlayers();
		if (count($players) < $this->minimumPlayers) {
			return;
		}

		$now = (int) floor(microtime(true) * 1000);
		if ($this->consecutiveFailures >= 3 && $now < $this->circuitOpenUntil) {
			return;
		}

		// Process players in batches for large servers (100+ players)
		/** @var array<string, mixed> $allRows */
		$allRows = [];
		foreach ($players as $player) {
			$id = $player->getUniqueId()->toString();
			$dead = isset($this->deadPlayers[$id]);
			$location = $player->getLocation();
			$allRows[] = [
				'name' => $player->getName(),
				'dimension' => $dead ? 'death' : Util::dimensionFor($player->getWorld(), $this->dimensionMap),
				'coordinates' => $dead ? ['x' => 0, 'y' => 0, 'z' => 0] : [
					'x' => $location->x,
					'y' => $location->y,
					'z' => $location->z
				],
				'deafen' => $player->isSneaking(),
				'orientation' => [
					'x' => $location->getPitch(),
					'y' => $location->getYaw()
				],
				'spectator' => $dead ? false : $player->isSpectator(),
				'world_uuid' => $this->worldUuid
			];
		}

		// Send in batches
		$batchSize = max(1, $this->batchSize);
		$batches = array_chunk($allRows, $batchSize);
		foreach ($batches as $batch) {
			/** @var array<string, mixed> $batch */
			$this->sendPositionBatch($batch);
		}
	}

	/**
	 * @param array<string, mixed> $rows
	 */
	private function sendPositionBatch(array $rows) : void {
		$baseUrlValue = $this->config->get('server_url', '');
		if (!is_string($baseUrlValue)) {
			$baseUrlValue = '';
		}
		$baseUrl = rtrim($baseUrlValue, '/');
		$tokenValue = $this->config->get('access_token', '');
		if (!is_string($tokenValue)) {
			$tokenValue = '';
		}
		$headers = ['Authorization' => 'Bearer ' . trim($tokenValue), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
		$timeoutValue = $this->config->get('http_timeout_seconds', 8);
		if (!is_int($timeoutValue)) {
			$timeoutValue = 8;
		}
		$timeout = max(1, $timeoutValue);

		$this->positionRequestPending = true;
		$this->plugin->debug("[PositionService] Sending position batch of " . count($rows) . " player(s)");
		$body = json_encode(['game' => 'minecraft', 'players' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($body)) {
			$this->positionRequestPending = false;
			return;
		}
		$this->http->request('POST', $baseUrl . '/api/position', $headers, $body,
			function(int $status, string $body, string $error) : void {
				$this->positionRequestPending = false;
				$this->plugin->debug("[PositionService] Position batch returned: HTTP {$status}");
				if ($status >= 200 && $status < 300) {
					if ($this->consecutiveFailures >= 3) {
						$this->plugin->getLogger()->info('BVC backend connection restored.');
					}
					$this->consecutiveFailures = 0;
					return;
				}
				$this->consecutiveFailures++;
				if ($this->consecutiveFailures === 3) {
					$this->plugin->getLogger()->warning('BVC backend is unreachable; position updates are temporarily paused.');
				}
				if ($this->consecutiveFailures >= 3) {
					$delay = min(30000, 10000 * (2 ** ($this->consecutiveFailures - 3)));
					$this->circuitOpenUntil = (int) floor(microtime(true) * 1000) + $delay;
				}
				if ($error !== '') {
					$this->plugin->getLogger()->debug('BVC position request failed: ' . $error);
				}
			}, $timeout);
	}
}
