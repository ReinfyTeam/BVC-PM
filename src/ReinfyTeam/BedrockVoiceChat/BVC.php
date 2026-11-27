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
use function is_int;
use function is_string;
use function max;
use function rtrim;
use function trim;

/**
 * Main plugin class for BedrockVoiceChat.
 *
 * Fully asynchronous: all HTTP requests run on a dedicated background thread
 * via libpmcurl (curl_multi), never blocking the server's main thread.
 * Audio disc events are driven by jukebox interact/block-break events rather
 * than a per-tick scan.
 *
 * @property-read ConfigManager $config
 * @property-read HttpClient $http
 * @property-read VoiceService $voiceService
 * @property-read AudioService $audioService
 * @property-read PositionService $positionService
 * @property-read UserManager $userManager
 */
final class BVC extends PluginBase {
	private static ?self $instance = null;

	private ConfigManager $config;
	private HttpClient $http;
	private VoiceService $voiceService;
	private AudioService $audioService;
	private PositionService $positionService;
	private UserManager $userManager;

	private ?BVCCommand $command = null;

	public static function getInstance() : self {
		if (self::$instance === null) {
			throw new \RuntimeException("BVC instance is not available.");
		}
		return self::$instance;
	}

	public function getConfigManager() : ConfigManager {
		return $this->config;
	}

	public function getHttpClient() : HttpClient {
		return $this->http;
	}

	public function getVoiceService() : VoiceService {
		return $this->voiceService;
	}

	public function getAudioService() : AudioService {
		return $this->audioService;
	}

	public function getPositionService() : PositionService {
		return $this->positionService;
	}

	public function getUserManager() : UserManager {
		return $this->userManager;
	}

	/**
	 * @return mixed
	 */
	public function __get(string $name) {
		return match ($name) {
			'config' => $this->config,
			'http' => $this->http,
			'voiceService' => $this->voiceService,
			'audioService' => $this->audioService,
			'positionService' => $this->positionService,
			'userManager' => $this->userManager,
			default => throw new \Error("Undefined property: " . self::class . "::\${$name}"),
		};
	}

	public function __isset(string $name) : bool {
		return match ($name) {
			'config' => isset($this->config),
			'http' => isset($this->http),
			'voiceService' => isset($this->voiceService),
			'audioService' => isset($this->audioService),
			'positionService' => isset($this->positionService),
			'userManager' => isset($this->userManager),
			default => false,
		};
	}

	/**
	 * Log a debug message using a key from messages.yml.
	 * All debug messages are universally prefixed with the `prefix` key from messages.yml.
	 * When `debug: true` is set in config.yml, it logs with the prefix at info level.
	 *
	 * @param array<string, string> $placeholders
	 */
	public function debug(string $key, array $placeholders = []) : void {
		$message = $this->config->message($key, $placeholders);
		$this->getLogger()->debug($message);
	}

	protected function onEnable() : void {
		self::$instance = $this;
		$this->saveDefaultConfig();
		$this->config = new ConfigManager($this);
		$this->config->load();
		$this->config->registerPermissions();

		$this->debug("debug_http_init");
		$this->http = new HttpClient($this);

		$this->debug("debug_services_init");

		$this->voiceService = new VoiceService($this, $this->config, $this->http);
		$this->userManager = new UserManager($this, $this->config, $this->http, $this->voiceService);
		$this->audioService = new AudioService($this, $this->config, $this->http);
		$this->audioService->loadConfig();
		$this->audioService->loadDiscs();
		$this->positionService = new PositionService($this, $this->config, $this->http);
		$this->positionService->loadConfig();

		$worldUuid = $this->config->get('world_uuid', '');
		if (!is_string($worldUuid)) {
			$worldUuid = '';
		}
		$worldUuid = trim($worldUuid);
		if ($worldUuid === '') {
			$worldUuid = Util::generateUuid();
			$this->config->set('world_uuid', $worldUuid);
			$this->saveConfig();
			$this->debug("debug_uuid_generated", ['uuid' => $worldUuid]);
		}
		$this->positionService->setWorldUuid($worldUuid);
		$this->getServer()->getPluginManager()->registerEvents(new BVCListener($this, $this->audioService, $this->positionService), $this);
		$this->command = new BVCCommand($this);
		$this->getServer()->getCommandMap()->register('bvc', $this->command);

		$pollIntervalValue = $this->config->getPerformance('poll_interval_ticks', 5);
		if (!is_int($pollIntervalValue)) {
			$pollIntervalValue = 5;
		}

		$pollInterval = max(1, $pollIntervalValue);
		$this->debug("debug_poll_scheduling", ['poll_interval' => (string) $pollInterval]);

		// Schedule position polling (single POST roster per tick)
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
			$this->positionService->pollPlayerPositions();
		}), $pollInterval);

		$this->getLogger()->debug($this->config->message('debug_bridge_enabled', ['uuid' => $worldUuid]));
		if (!$this->isConfigured()) {
			$this->getLogger()->warning('Set server_url and access_token in plugin_data/BVC/config.yml. BVC HTTP features are inactive.');
		}
	}

	protected function onDisable() : void {
		$this->debug("debug_disc_persisting");
		$this->audioService->persistDiscs();

		$this->debug("debug_disc_ejecting");
		$this->audioService->ejectAllDiscs();

		$this->debug("debug_http_stopping");
		$this->http->stop();

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
