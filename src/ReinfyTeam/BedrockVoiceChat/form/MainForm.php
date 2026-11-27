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

namespace ReinfyTeam\BedrockVoiceChat\form;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\service\VoiceService;
use function intval;
use function is_float;
use function is_int;
use function is_string;

/**
 * Main in-game UI form opened by /bvc ui (uses jojoe77777 FormAPI).
 * All labels/titles/buttons sourced from messages.yml.
 */
final class MainForm extends SimpleForm {
	private BVC $plugin;
	private ConfigManager $config;

	public function __construct(BVC $plugin, ConfigManager $config) {
		$this->plugin = $plugin;
		$this->config = $config;
		parent::__construct(function(Player $player, mixed $data) : void {
			$this->handleResponse($player, $data);
		});
		$this->setTitle($config->message('ui_title'));
		$this->setContent($config->message('ui_content'));
		$this->setupButtons();
	}

	private function setupButtons() : void {
		$this->addButton($this->config->message('ui_btn_microphone'));
		$this->addButton($this->config->message('ui_btn_deafen'));
		$this->addButton($this->config->message('ui_btn_recording'));
		$this->addButton($this->config->message('ui_btn_volume'));
		$this->addButton($this->config->message('ui_btn_hearing'));
		$this->addButton($this->config->message('ui_btn_groups'));
		$this->addButton($this->config->message('ui_btn_status'));
		$this->addButton($this->config->message('ui_btn_disc'));
		$this->addButton($this->config->message('ui_btn_close'));
	}

	public function handleResponse(Player $player, mixed $data) : void {
		if ($data === null) {
			return; // Closed
		}
		$voiceService = $this->plugin->getVoiceService();
		if (!is_int($data)) {
			if (is_string($data) || is_float($data)) {
				$data = intval($data);
			} else {
				$data = 0;
			}
		}
		$index = $data;
		$this->plugin->debug("debug_mainform_button", ['player' => $player->getName(), 'index' => (string) $index]);

		match ($index) {
			0 => $this->toggleBool($player, $voiceService, 'mute'),
			1 => $this->toggleBool($player, $voiceService, 'deafen'),
			2 => $this->toggleBool($player, $voiceService, 'record'),
			3 => $this->openVolumeForm($player),
			4 => $this->toggleHearing($player, $voiceService),
			5 => $voiceService->listGroups($player),
			6 => $voiceService->getStatus($player),
			7 => $this->openDiscForm($player),
			default => null, // Close button
		};
	}

	private function toggleBool(Player $player, VoiceService $voiceService, string $type) : void {
		$state = $voiceService->getPlayerState($player);
		$current = match ($type) {
			'mute' => $state->muted,
			'deafen' => $state->deafened,
			'record' => $state->recording,
			default => false
		};
		$newValue = !$current;
		$this->plugin->debug("debug_mainform_toggle", ['player' => $player->getName(), 'type' => $type, 'state' => ($newValue ? 'on' : 'off')]);
		match ($type) {
			'mute' => $voiceService->toggleMute($player, $newValue),
			'deafen' => $voiceService->toggleDeafen($player, $newValue),
			'record' => $voiceService->toggleRecording($player, $newValue),
			default => null,
		};
	}

	private function openVolumeForm(Player $player) : void {
		$form = new VolumeForm($this->plugin, $this->config);
		$player->sendForm($form);
	}

	private function toggleHearing(Player $player, VoiceService $voiceService) : void {
		$state = $voiceService->getPlayerState($player);
		$newValue = !$state->hearingOthers;
		$this->plugin->debug("debug_mainform_hearing", ['player' => $player->getName(), 'state' => ($newValue ? 'on' : 'off')]);
		$voiceService->toggleHearingAll($player, $newValue);
	}

	private function openDiscForm(Player $player) : void {
		$form = new DiscForm($this->plugin, $this->config);
		$player->sendForm($form);
	}
}
