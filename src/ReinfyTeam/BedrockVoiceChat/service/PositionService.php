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

namespace ReinfyTeam\BedrockVoiceChat\service;

use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\util\Util;
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
 *
 * The entire online roster is sent in a single POST each tick — the backend
 * already handles coalescing, so no client-side batching is needed.
 */
final class PositionService {
	private BVC $plugin;
	private ConfigManager $config;
	private HttpClient $http;

	private int $minimumPlayers = 1;
	private bool $enablePolling = true;
	/** @var array<string, string> */
	private array $dimensionMap = [];
	private string $worldUuid = '';

	/** @var array<string, true> */
	private array $deadPlayers = [];

	private int $consecutiveFailures = 0;
	private int $circuitOpenUntil = 0;

	public function __construct(BVC $plugin, ConfigManager $config, HttpClient $http) {
		$this->plugin = $plugin;
		$this->config = $config;
		$this->http = $http;
	}

	public function loadConfig() : void {
		$minimumPlayersValue = $this->config->get('minimum_players', 1);
		$this->minimumPlayers = max(1, is_int($minimumPlayersValue) ? $minimumPlayersValue : 1);
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
		$this->plugin->debug("debug_player_marked_dead", ['player' => $player->getName()]);
	}

	public function unmarkDead(Player $player) : void {
		unset($this->deadPlayers[$player->getUniqueId()->toString()]);
		$this->plugin->debug("debug_player_unmarked_dead", ['player' => $player->getName()]);
	}

	public function pollPlayerPositions() : void {
		if (!$this->isEnabled() || $this->worldUuid === '') {
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
		$body = json_encode(['game' => 'minecraft', 'players' => $allRows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($body)) {
			return;
		}

		$this->plugin->debug("debug_position_roster", ['count' => (string) count($allRows)]);
		$this->http->post($baseUrl . '/api/position', $headers, $body,
			function(int $status, string $body, string $error) : void {
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
					$this->plugin->debug("debug_position_failed", ['error' => $error]);
				}
			}, $timeout);
	}
}
