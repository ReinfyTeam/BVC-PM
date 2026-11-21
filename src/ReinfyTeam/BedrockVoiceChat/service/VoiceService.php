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

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\model\PlayerState;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function filter_var;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function rawurlencode;
use function rtrim;
use function strcasecmp;
use function time;
use function trim;

/**
 * Core voice chat control logic: mute, deafen, record, volume, hear, groups, status.
 * All HTTP calls are asynchronous via HttpClient (dedicated worker thread).
 * Aligned with the BVC protocol in main.js.
 */
final class VoiceService {
	private BVC $plugin;
	private ConfigManager $config;
	private HttpClient $http;
	/** @var array<string, PlayerState> */
	private array $playerStates = [];

	public function __construct(BVC $plugin, ConfigManager $config, HttpClient $http) {
		$this->plugin = $plugin;
		$this->config = $config;
		$this->http = $http;
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

	public function httpTimeout() : int {
		$timeout = $this->config->get('http_timeout_seconds', 8);
		if (!is_int($timeout)) {
			$timeout = 8;
		}
		return max(1, $timeout);
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

	public function getPlayerState(Player $player) : PlayerState {
		$uuid = $player->getUniqueId()->toString();
		if (!isset($this->playerStates[$uuid])) {
			$this->playerStates[$uuid] = new PlayerState($player->getName());
		}
		return $this->playerStates[$uuid];
	}

	public function removePlayerState(Player $player) : void {
		unset($this->playerStates[$player->getUniqueId()->toString()]);
	}

	public function requireBackend(?CommandSender $sender) : bool {
		if (!$this->isConfigured()) {
			$sender?->sendMessage($this->config->message('backend_not_configured'));
			return false;
		}
		return true;
	}

	/**
	 * Send a control action to the BVC backend for a specific target player, reporting results to $sender.
	 *
	 * @param array<string, mixed>|string $action
	 * @param array<string, string> $placeholders
	 */
	public function sendControlFor(
		CommandSender $sender,
		string $targetPlayerName,
		array|string $action,
		string $successMessageKey,
		array $placeholders = []
	) : void {
		if (!$this->requireBackend($sender)) {
			return;
		}
		$body = json_encode([
			'id' => $targetPlayerName,
			'game' => 'minecraft',
			'action' => $action
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($body)) {
			$sender->sendMessage($this->config->message('control_encode_error'));
			return;
		}
		$this->plugin->debug("[VoiceService] Dispatched control to {$targetPlayerName}: " . $body);
		$this->http->request('POST', $this->baseUrl() . '/api/control', $this->headers(true), $body,
			function(int $status, string $responseBody, string $error) use ($sender, $action, $successMessageKey, $placeholders, $targetPlayerName) : void {
				$this->plugin->debug("[VoiceService] Control response for {$targetPlayerName}: HTTP {$status}" . ($error !== '' ? " error={$error}" : ""));
				if ($status === 202) {
					$sender->sendMessage($this->config->message('request_forwarded'));
					return;
				}
				if ($status === 409) {
					$sender->sendMessage($this->config->message('no_active_session'));
					return;
				}
				if ($status >= 200 && $status < 300) {
					if ($action === 'CreateGroup') {
						$code = json_decode($responseBody, true);
						if (is_string($code) && $code !== '') {
							$sender->sendMessage($this->config->message('group_created', ['code' => $code]));
							return;
						}
					}
					$sender->sendMessage($this->config->message($successMessageKey, $placeholders));
					return;
				}
				$detail = $status > 0 ? 'HTTP ' . $status : ($error !== '' ? $error : 'no response');
				$sender->sendMessage($this->config->message('control_failed', ['detail' => $detail]));
			}, $this->httpTimeout());
	}

	/**
	 * Send a control action to the BVC backend for a player's own voice session.
	 *
	 * @param array<string, mixed>|string $action
	 * @param array<string, string> $placeholders
	 */
	public function sendControl(Player $player, array|string $action, string $successMessageKey, array $placeholders = []) : void {
		$this->sendControlFor($player, $player->getName(), $action, $successMessageKey, $placeholders);
	}

	public function toggleMute(Player $player, bool $value) : void {
		$state = $this->getPlayerState($player);
		$state->setMuted($value);
		$this->plugin->userManager->getOrCreateUser($player)->setMuted($value);
		$this->sendControl($player, ['SetMuted' => $value], 'mute_toggled', ['state' => $value ? 'enabled' : 'disabled']);
	}

	public function toggleMuteFor(CommandSender $sender, Player $target, bool $value) : void {
		$state = $this->getPlayerState($target);
		$state->setMuted($value);
		$this->plugin->userManager->getOrCreateUser($target)->setMuted($value);
		if ($sender instanceof Player && $sender->getUniqueId()->equals($target->getUniqueId())) {
			$this->sendControl($target, ['SetMuted' => $value], 'mute_toggled', ['state' => $value ? 'enabled' : 'disabled']);
		} else {
			$this->sendControlFor($sender, $target->getName(), ['SetMuted' => $value], 'other_player_mute_toggled', [
				'state' => $value ? 'enabled' : 'disabled',
				'target' => $target->getName(),
			]);
			$target->sendMessage($this->config->message('mute_toggled', ['state' => $value ? 'enabled' : 'disabled']));
		}
	}

	public function toggleDeafen(Player $player, bool $value) : void {
		$state = $this->getPlayerState($player);
		$state->setDeafened($value);
		$this->plugin->userManager->getOrCreateUser($player)->setDeafened($value);
		$this->sendControl($player, ['SetDeafened' => $value], 'deafen_toggled', ['state' => $value ? 'enabled' : 'disabled']);
	}

	public function toggleDeafenFor(CommandSender $sender, Player $target, bool $value) : void {
		$state = $this->getPlayerState($target);
		$state->setDeafened($value);
		$this->plugin->userManager->getOrCreateUser($target)->setDeafened($value);
		if ($sender instanceof Player && $sender->getUniqueId()->equals($target->getUniqueId())) {
			$this->sendControl($target, ['SetDeafened' => $value], 'deafen_toggled', ['state' => $value ? 'enabled' : 'disabled']);
		} else {
			$this->sendControlFor($sender, $target->getName(), ['SetDeafened' => $value], 'other_player_deafen_toggled', [
				'state' => $value ? 'enabled' : 'disabled',
				'target' => $target->getName(),
			]);
			$target->sendMessage($this->config->message('deafen_toggled', ['state' => $value ? 'enabled' : 'disabled']));
		}
	}

	public function toggleRecording(Player $player, bool $value) : void {
		$state = $this->getPlayerState($player);
		$state->setRecording($value);
		$this->plugin->userManager->getOrCreateUser($player)->setRecording($value);
		$this->sendControl($player, ['SetRecording' => $value], 'record_toggled', ['state' => $value ? 'enabled' : 'disabled']);
	}

	public function toggleRecordingFor(CommandSender $sender, Player $target, bool $value) : void {
		$state = $this->getPlayerState($target);
		$state->setRecording($value);
		$this->plugin->userManager->getOrCreateUser($target)->setRecording($value);
		if ($sender instanceof Player && $sender->getUniqueId()->equals($target->getUniqueId())) {
			$this->sendControl($target, ['SetRecording' => $value], 'record_toggled', ['state' => $value ? 'enabled' : 'disabled']);
		} else {
			$this->sendControlFor($sender, $target->getName(), ['SetRecording' => $value], 'other_player_record_toggled', [
				'state' => $value ? 'enabled' : 'disabled',
				'target' => $target->getName(),
			]);
			$target->sendMessage($this->config->message('record_toggled', ['state' => $value ? 'enabled' : 'disabled']));
		}
	}

	public function setVolume(Player $player, string $targetName, int $level) : void {
		$target = Util::resolveOnlinePlayer($targetName);
		if ($target === null) {
			$player->sendMessage($this->config->message('player_not_found', ['name' => $targetName]));
			return;
		}
		$level = max(0, min(150, $level));
		$this->sendControl($player, ['SetVolume' => ['target' => $target, 'volume' => $level / 100]],
			'volume_set', ['level' => (string) $level, 'target' => $target]);
	}

	public function setVolumeFor(CommandSender $sender, Player $target, int $level) : void {
		$level = max(0, min(150, $level));
		if ($sender instanceof Player && $sender->getUniqueId()->equals($target->getUniqueId())) {
			$this->sendControl($target, ['SetVolume' => ['target' => $target->getName(), 'volume' => $level / 100]],
				'volume_set', ['level' => (string) $level, 'target' => $target->getName()]);
		} else {
			$this->sendControlFor($sender, $target->getName(), ['SetVolume' => ['target' => $target->getName(), 'volume' => $level / 100]],
				'other_player_volume_set', ['level' => (string) $level, 'target' => $target->getName()]);
			$target->sendMessage($this->config->message('volume_set', [
				'level' => (string) $level,
				'target' => $target->getName()
			]));
		}
	}

	public function toggleHear(Player $player, string $targetName, bool $value) : void {
		$target = Util::resolveOnlinePlayer($targetName);
		if ($target === null) {
			$player->sendMessage($this->config->message('player_not_found', ['name' => $targetName]));
			return;
		}
		$state = $this->getPlayerState($player);
		$state->setHearingOthers($value);
		$this->sendControl($player, ['SetHeard' => ['target' => $target, 'muted' => !$value]],
			'hear_toggled', ['state' => $value ? 'can now hear' : 'will no longer hear', 'target' => $target]);
	}

	public function toggleHearingAll(Player $player, bool $value) : void {
		$state = $this->getPlayerState($player);
		$state->setHearingOthers($value);
		$this->sendControl($player, ['SetHeard' => ['target' => '#all', 'muted' => !$value]],
			'hear_toggled', ['state' => $value ? 'can now hear everyone' : 'will no longer hear anyone', 'target' => 'everyone']);
	}

	public function toggleJukeboxMute(CommandSender $sender, bool $muted) : void {
		if (!$this->requireBackend($sender)) {
			return;
		}
		$id = $sender instanceof Player ? $sender->getName() : '#console';
		$this->sendControlFor($sender, $id, ['SetHeard' => ['target' => '#jukebox', 'muted' => $muted]],
			'jukebox_mute_toggled', ['state' => $muted ? 'enabled' : 'disabled']);
	}

	public function setJukeboxVolume(CommandSender $sender, int $level) : void {
		if (!$this->requireBackend($sender)) {
			return;
		}
		$level = max(0, min(150, $level));
		$id = $sender instanceof Player ? $sender->getName() : '#console';
		$this->sendControlFor($sender, $id, ['SetVolume' => ['target' => '#jukebox', 'volume' => $level / 100]],
			'jukebox_volume_set', ['level' => (string) $level]);
	}

	public function createGroup(Player $player) : void {
		$this->sendControl($player, 'CreateGroup', 'group_create_sent');
	}

	public function joinGroup(Player $player, string $shareCode) : void {
		$this->sendControl($player, ['JoinGroup' => ['channel' => $shareCode]], 'group_join_sent');
	}

	public function leaveGroup(Player $player) : void {
		$this->sendControl($player, 'LeaveGroup', 'group_leave_sent');
	}

	public function listGroups(Player $player) : void {
		if (!$this->requireBackend($player)) {
			return;
		}
		$this->http->request('GET', $this->baseUrl() . '/api/channel', $this->headers(), null,
			function(int $status, string $body, string $error) use ($player) : void {
				if ($status < 200 || $status >= 300) {
					$player->sendMessage($this->config->message('control_failed', ['detail' => 'HTTP ' . $status]));
					return;
				}
				$rows = json_decode($body, true);
				if (!is_array($rows)) {
					$player->sendMessage($this->config->message('control_failed', ['detail' => 'invalid group data']));
					return;
				}
				if ($rows === []) {
					$player->sendMessage($this->config->message('groups_none'));
					return;
				}
				$player->sendMessage($this->config->message('groups_title'));
				foreach ($rows as $row) {
					if (is_array($row) && isset($row['id'], $row['name'])) {
						$idValue = $row['id'];
						$nameValue = $row['name'];
						if (!is_string($idValue) || !is_string($nameValue)) {
							continue;
						}
						$protected = ($row['protected'] ?? false) === true ? ' §c[Invite required]' : '';
						$player->sendMessage($this->config->message('group_line', [
							'name' => $nameValue,
							'id' => $idValue,
							'protected' => $protected,
						]));
					}
				}
			}, $this->httpTimeout());
	}

	public function getStatus(Player $player) : void {
		$this->getStatusFor($player, $player->getName());
	}

	public function getStatusFor(CommandSender $sender, string $targetPlayerName) : void {
		if (!$this->requireBackend($sender)) {
			return;
		}
		$url = $this->baseUrl() . '/api/state?id=' . rawurlencode($targetPlayerName) . '&game=minecraft';
		$this->http->request('GET', $url, $this->headers(), null,
			function(int $status, string $body, string $error) use ($sender, $targetPlayerName) : void {
				if ($status < 200 || $status >= 300) {
					$sender->sendMessage($this->config->message('control_failed', ['detail' => 'HTTP ' . $status]));
					return;
				}
				$state = json_decode($body, true);
				if (!is_array($state)) {
					$sender->sendMessage($this->config->message('control_failed', ['detail' => 'invalid state data']));
					return;
				}

				// Sync with UserManager
				$user = $this->plugin->userManager->getOrCreateUser($targetPlayerName);
				$user->setMuted((bool) ($state['muted'] ?? false));
				$user->setDeafened((bool) ($state['deafened'] ?? false));
				$user->setRecording((bool) ($state['recording'] ?? false));
				$user->setSpeaking((bool) ($state['speaking'] ?? $state['talking'] ?? false));
				$user->setConnected(true);
				$group = $state['current_group'] ?? null;
				$user->setGroup(is_string($group) && $group !== '' ? $group : null);
				$user->setLastSyncTime(time());

				$isSelf = $sender instanceof Player && strcasecmp($sender->getName(), $targetPlayerName) === 0;
				$sender->sendMessage($this->config->message($isSelf ? 'voice_status_title' : 'voice_status_title_other', ['target' => $targetPlayerName]));
				$sender->sendMessage($this->config->message('status_muted', ['value' => ($state['muted'] ?? false) ? 'Yes' : 'No']));
				$sender->sendMessage($this->config->message('status_deafened', ['value' => ($state['deafened'] ?? false) ? 'Yes' : 'No']));
				$sender->sendMessage($this->config->message('status_recording', ['value' => ($state['recording'] ?? false) ? 'Yes' : 'No']));
				$group = $state['current_group'] ?? null;
				$sender->sendMessage($this->config->message('status_group', ['value' => (is_string($group) && $group !== '' ? $group : 'None')]));
			}, $this->httpTimeout());
	}
}
