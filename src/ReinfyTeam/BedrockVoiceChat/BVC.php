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

namespace ReinfyTeam\BedrockVoiceChat;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use ReinfyTeam\BedrockVoiceChat\command\BVCCommand;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\event\BVCListener;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\service\AudioService;
use ReinfyTeam\BedrockVoiceChat\service\PositionService;
use ReinfyTeam\BedrockVoiceChat\service\VoiceService;
use ReinfyTeam\BedrockVoiceChat\user\UserManager;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function max;
use function rtrim;
use function trim;

/**
 * Main plugin class for BedrockVoiceChat.
 *
 * Fully asynchronous: all HTTP requests run on a dedicated background worker
 * thread (HttpWorkerThread), never blocking the server's main thread.
 * Designed to handle 100+ players with batched position updates.
 */
final class BVC extends PluginBase {
	private static ?self $instance = null;

	public ConfigManager $config;
	public HttpClient $http;
	public VoiceService $voiceService;
	public AudioService $audioService;
	public PositionService $positionService;
	public UserManager $userManager;

	private ?BVCCommand $command = null;
	private bool $httpStarted = false;

	public static function getInstance() : self {
		if (self::$instance === null) {
			throw new \RuntimeException("BVC instance is not available.");
		}
		return self::$instance;
	}

	public function getUserManager() : UserManager {
		return $this->userManager;
	}

	/**
	 * Log a debug message using PocketMine's logger.
	 * When `debug: true` is set in config.yml, it logs with [DEBUG] tag.
	 */
	public function debug(string $message) : void {
		if (isset($this->config) && (bool) $this->config->get('debug', false)) {
			$this->getLogger()->info("§7[DEBUG] " . $message);
		} else {
			$this->getLogger()->debug($message);
		}
	}

	protected function onEnable() : void {
		self::$instance = $this;
		$this->saveDefaultConfig();

		// Load configuration and register customizable permissions
		$this->config = new ConfigManager($this);
		$this->config->load();
		$this->config->registerPermissions();

		$this->debug("Starting dedicated asynchronous HTTP worker thread...");
		// Initialize the dedicated async HTTP thread
		$this->http = new HttpClient($this);
		$this->http->start();
		$this->httpStarted = true;

		$this->debug("Initializing voice, user, audio, and position services...");
		// Initialize services
		$this->voiceService = new VoiceService($this, $this->config, $this->http);
		$this->userManager = new UserManager($this, $this->config, $this->http, $this->voiceService);
		$this->audioService = new AudioService($this, $this->config, $this->http);
		$this->audioService->loadConfig();
		$this->audioService->loadDiscs();
		$this->positionService = new PositionService($this, $this->config, $this->http);
		$this->positionService->loadConfig();

		// World UUID: generate if missing, persist to config
		$worldUuid = $this->config->get('world_uuid', '');
		if (!is_string($worldUuid)) {
			$worldUuid = '';
		}
		$worldUuid = trim($worldUuid);
		if ($worldUuid === '') {
			$worldUuid = Util::generateUuid();
			$this->config->set('world_uuid', $worldUuid);
			$this->saveConfig();
			$this->debug("Generated new world UUID: " . $worldUuid);
		}
		$this->positionService->setWorldUuid($worldUuid);

		// Register events and command
		$this->getServer()->getPluginManager()->registerEvents(new BVCListener($this, $this->audioService, $this->positionService), $this);
		$this->command = new BVCCommand($this);
		$this->getServer()->getCommandMap()->register('bvc', $this->command);

		$pollIntervalValue = $this->config->getPerformance('poll_interval_ticks', 5);
		if (!is_int($pollIntervalValue)) {
			$pollIntervalValue = 5;
		}
		$pollInterval = max(1, $pollIntervalValue);
		$this->debug("Scheduling position polling every {$pollInterval} ticks...");
		// Schedule position polling (asynchronous, batched)
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			$this->positionService->pollPlayerPositions();
		}), $pollInterval);

		$this->debug("Scheduling audio disc tick (1 tick)...");
		// Schedule audio disc tick (hopper insertion, block state sync)
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			$this->audioService->tick();
		}), 1);

		$this->getLogger()->info('BVC PMMP bridge enabled (API 5.0.0). World UUID: ' . $worldUuid);
		if (!$this->voiceService->isConfigured()) {
			$this->getLogger()->warning('Set server_url and access_token in plugin_data/BVC/config.yml. BVC HTTP features are inactive.');
		}
	}

	protected function onDisable() : void {
		$this->debug("Plugin disabling: persisting active audio discs...");
		// Persist active discs before shutting down
		$this->audioService->persistDiscs();

		$this->debug("Ejecting active audio discs...");
		// Stop all active audio events and eject discs
		$this->audioService->ejectAllDiscs();

		$this->debug("Stopping HTTP worker thread...");
		// Stop the dedicated HTTP worker thread cleanly
		if ($this->httpStarted) {
			$this->http->stop();
		}

		self::$instance = null;
		$this->command = null;
		$this->getLogger()->info('BVC PMMP bridge disabled.');
	}

	public function baseUrl() : string {
		$url = $this->config->get('server_url', '');
		if (!is_string($url)) {
			$url = '';
		}
		return rtrim($url, '/');
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(bool $json = false) : array {
		$token = $this->config->get('access_token', '');
		if (!is_string($token)) {
			$token = '';
		}
		$headers = ['Authorization' => 'Bearer ' . trim($token), 'Accept' => 'application/json'];
		if ($json) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}

	public function httpTimeout() : int {
		$timeout = $this->config->get('http_timeout_seconds', 8);
		if (!is_int($timeout)) {
			$timeout = 8;
		}
		return max(1, $timeout);
	}

	public function isConfigured() : bool {
		return $this->voiceService->isConfigured();
	}
}
