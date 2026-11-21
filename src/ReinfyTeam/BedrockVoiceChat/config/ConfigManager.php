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

namespace ReinfyTeam\BedrockVoiceChat\config;

use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\utils\Config;
use ReinfyTeam\BedrockVoiceChat\BVC;
use function array_key_exists;
use function array_replace_recursive;
use function basename;
use function copy;
use function count;
use function explode;
use function fclose;
use function file_exists;
use function is_array;
use function is_dir;
use function mkdir;
use function rename;
use function str_replace;
use function stream_get_contents;
use function ucfirst;
use function yaml_parse;

/**
 * Loads and provides access to config.yml and messages.yml.
 * Registers customizable permissions dynamically on plugin load.
 * Inherits permissions to operators based on action scope.
 * Includes automatic versioning and migration system for both configs.
 */
final class ConfigManager {
	public const CURRENT_CONFIG_VERSION = 1;
	public const CURRENT_MESSAGES_VERSION = 1;

	private BVC $plugin;
	private Config $config;
	private Config $messages;
	private string $messagesFile = "messages.yml";

	/** @var array<string, string> */
	private array $permissions = [];

	/** @var array<string, mixed> */
	private array $performance = [];

	public function __construct(BVC $plugin) {
		$this->plugin = $plugin;
	}

	public function load() : void {
		$dataFolder = $this->plugin->getDataFolder();
		if (!is_dir($dataFolder)) {
			@mkdir($dataFolder, 0777, true);
		}

		// 1. Load and version-check config.yml
		$this->loadAndMigrateConfig();

		// 2. Load and version-check messages.yml
		$this->loadAndMigrateMessages();

		$this->permissions = (array) $this->config->get("permissions", []);
		$this->performance = (array) $this->config->get("performance", []);

		$this->plugin->debug("[ConfigManager] Configurations loaded. Permissions: " . count($this->permissions));
	}

	private function loadAndMigrateConfig() : void {
		$configFile = $this->plugin->getDataFolder() . "config.yml";
		if (!file_exists($configFile)) {
			$this->plugin->saveResource("config.yml", false);
			$this->config = new Config($configFile, Config::YAML, []);
			$this->plugin->debug("[ConfigManager] Created initial config.yml (v" . self::CURRENT_CONFIG_VERSION . ").");
			return;
		}

		$this->config = new Config($configFile, Config::YAML, []);
		$fileVersion = (int) $this->config->get("version", 0);

		if ($fileVersion < self::CURRENT_CONFIG_VERSION) {
			$this->plugin->getLogger()->info("Migrating config.yml from version {$fileVersion} to " . self::CURRENT_CONFIG_VERSION . "...");
			$backupFile = $this->plugin->getDataFolder() . "config.v{$fileVersion}.bak.yml";
			@copy($configFile, $backupFile);
			$this->plugin->getLogger()->info("Backup of older config.yml saved to: " . basename($backupFile));

			$resource = $this->plugin->getResource("config.yml");
			if ($resource !== null) {
				$defaultContent = stream_get_contents($resource);
				fclose($resource);
				$defaultData = yaml_parse((string) $defaultContent);
				if (is_array($defaultData)) {
					$currentData = $this->config->getAll();
					$merged = array_replace_recursive($defaultData, $currentData);
					$merged["version"] = self::CURRENT_CONFIG_VERSION;
					$this->config->setAll($merged);
					$this->config->save();
					$this->plugin->getLogger()->info("config.yml successfully migrated to version " . self::CURRENT_CONFIG_VERSION . ".");
				}
			}
		} else {
			$this->plugin->debug("[ConfigManager] config.yml is up to date (v{$fileVersion}).");
		}
	}

	private function loadAndMigrateMessages() : void {
		$this->messagesFile = (string) $this->config->get("messages_file", "messages.yml");
		$messagesPath = $this->plugin->getDataFolder() . $this->messagesFile;

		if (!file_exists($messagesPath)) {
			$this->plugin->saveResource("messages.yml", false);
			if ($this->messagesFile !== "messages.yml" && file_exists($this->plugin->getDataFolder() . "messages.yml")) {
				@rename($this->plugin->getDataFolder() . "messages.yml", $messagesPath);
			}
			$this->messages = new Config($messagesPath, Config::YAML, []);
			$this->plugin->debug("[ConfigManager] Created initial {$this->messagesFile} (v" . self::CURRENT_MESSAGES_VERSION . ").");
			return;
		}

		$this->messages = new Config($messagesPath, Config::YAML, []);
		$fileVersion = (int) $this->messages->get("version", 0);

		if ($fileVersion < self::CURRENT_MESSAGES_VERSION) {
			$this->plugin->getLogger()->info("Migrating {$this->messagesFile} from version {$fileVersion} to " . self::CURRENT_MESSAGES_VERSION . "...");
			$backupFile = $this->plugin->getDataFolder() . "messages.v{$fileVersion}.bak.yml";
			@copy($messagesPath, $backupFile);
			$this->plugin->getLogger()->info("Backup of older {$this->messagesFile} saved to: " . basename($backupFile));

			$resource = $this->plugin->getResource("messages.yml");
			if ($resource !== null) {
				$defaultContent = stream_get_contents($resource);
				fclose($resource);
				$defaultData = yaml_parse((string) $defaultContent);
				if (is_array($defaultData)) {
					$currentData = $this->messages->getAll();
					$merged = array_replace_recursive($defaultData, $currentData);
					$merged["version"] = self::CURRENT_MESSAGES_VERSION;
					$this->messages->setAll($merged);
					$this->messages->save();
					$this->plugin->getLogger()->info("{$this->messagesFile} successfully migrated to version " . self::CURRENT_MESSAGES_VERSION . ".");
				}
			}
		} else {
			$this->plugin->debug("[ConfigManager] {$this->messagesFile} is up to date (v{$fileVersion}).");
		}
	}

	/**
	 * Register all configured permissions with the server's permission handler.
	 * Permissions are read from the `permissions` section of config.yml.
	 *
	 * Operator inheritance model:
	 * - ROOT_USER (normal players + operators): basic voice commands & managing own settings.
	 * - ROOT_OPERATOR (operators only): administrative control over different players & world actions.
	 */
	public function registerPermissions() : void {
		$pm = PermissionManager::getInstance();
		$opRoot = $pm->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$userRoot = $pm->getPermission(DefaultPermissions::ROOT_USER);

		$operatorOnly = [
			'bvc.admin' => true,
			'bvc.eject' => true,
		];

		foreach ($this->permissions as $internal => $permissionName) {
			$permName = (string) $permissionName;
			$perm = $pm->getPermission($permName);
			if ($perm === null) {
				$perm = new Permission(
					$permName,
					"BVC: " . ucfirst(str_replace(['.', '_'], ' ', (string) $internal))
				);
				$pm->addPermission($perm);
			}

			$isOpOnly = isset($operatorOnly[$internal]);
			if ($isOpOnly) {
				$opRoot?->addChild($permName, true);
			} else {
				$userRoot?->addChild($permName, true);
			}
			$this->plugin->debug("[ConfigManager] Registered permission '{$permName}' (opOnly=" . ($isOpOnly ? "true" : "false") . ")");
		}
	}

	public function getPermission(string $internal, string $default = '') : string {
		return (string) ($this->permissions[$internal] ?? $default);
	}

	/**
	 * @return array<string, string>
	 */
	public function getPermissions() : array {
		return $this->permissions;
	}

	public function get(string $key, mixed $default = null) : mixed {
		$keys = explode(".", $key);
		$value = $this->config->getAll();
		foreach ($keys as $k) {
			if (!is_array($value) || !array_key_exists($k, $value)) {
				return $default;
			}
			$value = $value[$k];
		}
		return $value;
	}

	public function set(string $key, mixed $value) : void {
		$this->config->set($key, $value);
	}

	public function getConfig() : Config {
		return $this->config;
	}

	public function getMessages() : Config {
		return $this->messages;
	}

	public function getPerformance(string $key, mixed $default = null) : mixed {
		return $this->performance[$key] ?? $default;
	}

	/**
	 * Get a translated message with placeholder replacement.
	 * @param array<string, string> $placeholders
	 */
	public function message(string $key, array $placeholders = []) : string {
		$all = $this->messages->getAll();
		$msg = $all[$key] ?? null;
		if ($msg === null) {
			$this->plugin->debug("[ConfigManager] Missing message key '{$key}', using key as fallback.");
			$msg = $key;
		}
		foreach ($placeholders as $find => $replace) {
			$msg = str_replace("{" . $find . "}", (string) $replace, (string) $msg);
		}
		return (string) $msg;
	}
}
