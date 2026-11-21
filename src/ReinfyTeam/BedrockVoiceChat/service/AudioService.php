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

use pocketmine\block\Jukebox;
use pocketmine\item\Record;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\event\JukeboxEjectAudioDiscEvent;
use ReinfyTeam\BedrockVoiceChat\event\JukeboxPlayAudioDiscEvent;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\model\AudioDisc;
use ReinfyTeam\BedrockVoiceChat\util\PlayerInteractData;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function array_keys;
use function ceil;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filter_var;
use function is_array;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mkdir;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function strlen;
use function trim;

const DISC_STORAGE_FILE = 'active_discs.json';

/**
 * Handles BVC music discs, jukebox interactions, and audio event lifecycle.
 */
final class AudioService {
	private BVC $plugin;
	private ConfigManager $config;
	private HttpClient $http;
	private bool $autoEjectDiscs = true;
	private int $httpTimeout = 5;

	/** @var array<string, AudioDisc> */
	private array $activeDiscs = [];

	public function __construct(BVC $plugin, ConfigManager $config, HttpClient $http) {
		$this->plugin = $plugin;
		$this->config = $config;
		$this->http = $http;
	}

	public function loadConfig() : void {
		$this->autoEjectDiscs = (bool) $this->config->get('auto_eject_discs', true);
		$httpTimeoutValue = $this->config->get('http_timeout_seconds', 5);
		$this->httpTimeout = is_int($httpTimeoutValue) ? $httpTimeoutValue : 5;
		$this->httpTimeout = max(1, $this->httpTimeout);
	}

	/**
	 * Restore active discs from disk storage (survives server restarts).
	 */
	public function loadDiscs() : void {
		$file = $this->getStorageFile();
		if (!file_exists($file)) {
			return;
		}
		$raw = file_get_contents($file);
		if (!is_string($raw) || $raw === '') {
			return;
		}
		$entries = json_decode($raw, true);
		if (!is_array($entries)) {
			return;
		}
		$restored = 0;
		foreach ($entries as $key => $data) {
			if (!is_string($key)) {
				continue;
			}
			if (isset($this->activeDiscs[$key])) {
				continue;
			}
			if (!is_array($data)) {
				continue;
			}
			$x = $data['x'] ?? null;
			$y = $data['y'] ?? null;
			$z = $data['z'] ?? null;
			if (!isset($data['audioId']) || $x === null || $y === null || $z === null) {
				continue;
			}
			$audioId = $data['audioId'];
			if (!is_string($audioId)) {
				continue;
			}
			if (!is_int($x) || !is_int($y) || !is_int($z)) {
				continue;
			}
			$worldName = $data['world'] ?? null;
			if (!is_string($worldName)) {
				$worldName = $this->plugin->getServer()->getWorldManager()->getDefaultWorld()?->getFolderName() ?? 'world';
			}
			$targetWorld = $this->plugin->getServer()->getWorldManager()->getWorldByName($worldName);
			if ($targetWorld === null) {
				$targetWorld = $this->plugin->getServer()->getWorldManager()->getDefaultWorld();
				if ($targetWorld === null) {
					continue;
				}
			}
			$disc = new AudioDisc(
				$key,
				$audioId,
				$targetWorld,
				$x,
				$y,
				$z
			);
			$this->activeDiscs[$key] = $disc;
			$restored++;
		}
		if ($restored > 0) {
			$this->plugin->getLogger()->info("BVC restored {$restored} active disc(s)");
		}
	}

	/**
	 * Persist active discs to disk storage.
	 */
	public function persistDiscs() : void {
		$entries = [];
		foreach ($this->activeDiscs as $key => $disc) {
			$entries[$key] = [
				'audioId' => $disc->audioId,
				'world' => $disc->world->getFolderName(),
				'x' => $disc->x,
				'y' => $disc->y,
				'z' => $disc->z,
			];
		}
		$file = $this->getStorageFile();
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		file_put_contents($file, json_encode($entries));
	}

	private function getStorageFile() : string {
		return $this->plugin->getDataFolder() . DISC_STORAGE_FILE;
	}

	public function isConfigured() : bool {
		$baseUrl = $this->config->get('server_url', '');
		if (!is_string($baseUrl)) {
			$baseUrl = '';
		}
		$token = $this->config->get('access_token', '');
		if (!is_string($token)) {
			$token = '';
		}
		return $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
			&& $token !== '' && $token !== 'CHANGE_ME';
	}

	public function baseUrl() : string {
		$url = $this->config->get('server_url', '');
		if (!is_string($url)) {
			$url = '';
		}
		return rtrim($url, '/');
	}

	public function token() : string {
		$token = $this->config->get('access_token', '');
		if (!is_string($token)) {
			$token = '';
		}
		return trim($token);
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(bool $json = false) : array {
		$headers = ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'];
		if ($json) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}

	/**
	 * @return array<string, AudioDisc>
	 */
	public function getActiveDiscs() : array {
		return $this->activeDiscs;
	}

	public function hasDiscAt(string $key) : bool {
		return isset($this->activeDiscs[$key]);
	}

	public function giveBvcDisc(Player $player, string $audioId) : void {
		$audioId = trim($audioId);
		if (strlen($audioId) > 128 || preg_match('/[\r\n]/', $audioId) === 1) {
			$player->sendMessage($this->config->message('disc_invalid'));
			return;
		}
		$item = VanillaItems::RECORD_5();
		$tag = $item->getNamedTag();
		$tag->setString('bvc:audio_id', $audioId);
		$tag->setByte('bvc:is_bvc_disc', 1);
		$item->setNamedTag($tag);
		$item->setCustomName('§bBVC: ' . $audioId);
		foreach ($player->getInventory()->addItem($item) as $leftover) {
			$player->getWorld()->dropItem($player->getPosition()->add(0, 1, 0), $leftover);
		}
		$player->sendMessage($this->config->message('disc_created', ['audioId' => $audioId]));
		$this->persistDiscs();
	}

	public function onJukeboxRightClick(PlayerInteractData $data) : void {
		$player = $data->player;
		$block = $data->block;
		$world = $data->world;
		$position = $data->position;
		$key = Util::locationKey($world, (int) $position->x, (int) $position->y, (int) $position->z);
		$currentRecord = $block->getRecord();

		$audioId = $currentRecord !== null ? Util::getBvcAudioId($currentRecord) : null;
		if ($audioId !== null) {
			$data->event->cancel();
			$state = $this->activeDiscs[$key] ?? null;
			$event = new JukeboxEjectAudioDiscEvent($player, $block, $audioId, $block->getPosition());
			$event->call();
			if ($event->isCancelled()) {
				return;
			}
			if ($state instanceof AudioDisc && $state->hasEvent()) {
				$this->deleteAudioEvent($state->eventId);
			}
			unset($this->activeDiscs[$key]);
			$this->persistDiscs();
			$block->ejectRecord();
			$world->setBlock($position, $block);
			$this->plugin->debug("[AudioService] Ejected BVC disc at {$key}");
			$player->sendMessage($this->config->message('disc_ejected'));
			return;
		}

		$held = $player->getInventory()->getItemInHand();
		$audioId = Util::getBvcAudioId($held);
		if ($audioId === null || $currentRecord !== null || !$held instanceof Record) {
			return;
		}

		$data->event->cancel();

		$event = new JukeboxPlayAudioDiscEvent($player, $block, $audioId, $block->getPosition());
		$event->call();
		if ($event->isCancelled()) {
			return;
		}
		$audioId = $event->getAudioId();
		$block->insertRecord(clone $held);
		$block->stopSound();
		$world->setBlock($position, $block);

		if ($held->getCount() <= 1) {
			$player->getInventory()->setItemInHand(VanillaItems::AIR());
		} else {
			$remaining = clone $held;
			$remaining->setCount($held->getCount() - 1);
			$player->getInventory()->setItemInHand($remaining);
		}

		$disc = new AudioDisc($key, $audioId, $world, (int) $position->x, (int) $position->y, (int) $position->z);
		$this->activeDiscs[$key] = $disc;
		$this->persistDiscs();
		$this->plugin->debug("[AudioService] Jukebox loaded BVC disc '{$audioId}' at {$key}");
		$this->startAudioEvent($disc);
		$player->sendMessage($this->config->message('disc_inserted'));
	}

	public function forceEjectAt(World $world, int $x, int $y, int $z) : void {
		$key = Util::locationKey($world, $x, $y, $z);
		if (isset($this->activeDiscs[$key])) {
			$this->ejectBvcDisc($key);
			return;
		}
		$block = $world->getBlock(new Vector3($x, $y, $z));
		if ($block instanceof Jukebox && Util::getBvcAudioId($block->getRecord()) !== null) {
			$block->ejectRecord();
			$world->setBlock(new Vector3($x, $y, $z), $block);
		}
	}

	public function onBlockBreak(World $world, Vector3 $position) : void {
		$key = Util::locationKey($world, (int) $position->x, (int) $position->y, (int) $position->z);
		$state = $this->activeDiscs[$key] ?? null;
		if ($state instanceof AudioDisc) {
			if ($state->hasEvent()) {
				$this->deleteAudioEvent($state->eventId);
			}
			unset($this->activeDiscs[$key]);
			$this->persistDiscs();
		}
	}

	public function ejectBvcDisc(string $key) : void {
		$state = $this->activeDiscs[$key] ?? null;
		if (!($state instanceof AudioDisc)) {
			return;
		}
		$world = $state->world;
		$position = new Position($state->x, $state->y, $state->z, $world);
		$block = $world->getBlock($position);
		if ($block instanceof Jukebox && Util::getBvcAudioId($block->getRecord()) !== null) {
			$event = new JukeboxEjectAudioDiscEvent(null, $block, $state->audioId, $position);
			$event->call();
			if ($event->isCancelled()) {
				return;
			}
			$block->ejectRecord();
			$world->setBlock($position, $block);
			$this->plugin->getLogger()->info('Auto-ejected BVC disc ' . $state->audioId . ' from ' . $key);
		}
		if ($state->hasEvent()) {
			$this->deleteAudioEvent($state->eventId);
		}
		unset($this->activeDiscs[$key]);
		$this->persistDiscs();
	}

	public function ejectAllDiscs() : void {
		foreach (array_keys($this->activeDiscs) as $key) {
			$this->ejectBvcDisc($key);
		}
		foreach (array_keys($this->activeDiscs) as $key) {
			unset($this->activeDiscs[$key]);
		}
		$this->persistDiscs();
	}

	private function startAudioEvent(AudioDisc $disc) : void {
		if (!$this->isConfigured()) {
			$this->ejectBvcDisc($disc->key);
			return;
		}
		$dimensionMapValue = $this->config->getPerformance('dimension_map', []);
		$dimensionMap = [];
		if (is_array($dimensionMapValue)) {
			foreach ($dimensionMapValue as $k => $v) {
				if (is_string($k) && is_string($v)) {
					$dimensionMap[$k] = $v;
				}
			}
		}
		$payload = [
			'audio_file_id' => $disc->audioId,
			'game' => [
				'game' => 'minecraft',
				'coordinates' => ['x' => $disc->x, 'y' => $disc->y, 'z' => $disc->z],
				'dimension' => Util::dimensionFor($disc->world, $dimensionMap),
				'world_uuid' => $this->config->get('world_uuid', '')
			]
		];
		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($body)) {
			$this->ejectBvcDisc($disc->key);
			return;
		}
		$this->http->request('POST', $this->baseUrl() . '/api/audio/event', $this->headers(true), $body,
			function(int $status, string $responseBody, string $error) use ($disc) : void {
				if ($status < 200 || $status >= 300) {
					$this->plugin->getLogger()->warning('BVC audio play request failed (HTTP ' . $status . '): ' . $error);
					$this->ejectBvcDisc($disc->key);
					return;
				}
				$data = json_decode($responseBody, true);
				if (!is_array($data) || !isset($data['event_id']) || !isset($data['duration_ms'])) {
					$this->plugin->getLogger()->warning('BVC audio response did not include event_id and duration_ms.');
					$this->ejectBvcDisc($disc->key);
					return;
				}
				if (!isset($this->activeDiscs[$disc->key])) {
					$eventIdValue = $data['event_id'];
					if (is_string($eventIdValue)) {
						$this->deleteAudioEvent($eventIdValue);
					}
					return;
				}
				$eventIdValue = $data['event_id'];
				if (!is_string($eventIdValue)) {
					$this->ejectBvcDisc($disc->key);
					return;
				}
				$this->activeDiscs[$disc->key]->eventId = $eventIdValue;
				if ($this->autoEjectDiscs) {
					$durationMs = $data['duration_ms'];
					if (!is_int($durationMs) && !is_float($durationMs)) {
						$this->ejectBvcDisc($disc->key);
						return;
					}
					$ticks = max(1, (int) ceil((float) $durationMs / 50));
					$this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function() use ($disc) : void {
						$this->ejectBvcDisc($disc->key);
					}), $ticks);
				}
			}, $this->httpTimeout);
	}

	public function deleteAudioEvent(?string $eventId) : void {
		if ($eventId === null || !$this->isConfigured()) {
			return;
		}
		$this->http->request('DELETE', $this->baseUrl() . '/api/audio/event/' . rawurlencode($eventId), $this->headers(), null,
			static function(int $status, string $body, string $error) : void {
				// Fire-and-forget delete
			}, $this->httpTimeout);
	}

	/**
	 * Per-tick maintenance: check adjacent hoppers for BVC discs and sync jukebox block state.
	 * Mirrors main.js AudioComponentRegistry.onTick behavior.
	 */
	public function tick() : void {
		foreach ($this->activeDiscs as $key => $disc) {
			$block = $disc->world->getBlock(new Vector3($disc->x, $disc->y, $disc->z));
			if ($block instanceof Jukebox) {
				$record = $block->getRecord();
				$hasRecord = $record !== null && Util::getBvcAudioId($record) !== null;
				if (!$hasRecord) {
					// Block no longer holds the disc — clean up
					$this->ejectBvcDisc($key);
				}
			}
		}
	}
}
