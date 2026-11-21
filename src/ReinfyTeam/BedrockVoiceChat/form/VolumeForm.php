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

namespace ReinfyTeam\BedrockVoiceChat\form;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use function is_array;
use function is_float;
use function is_int;

/**
 * Custom form for setting voice volume (uses jojoe77777 FormAPI).
 * All labels/titles sourced from messages.yml.
 */
final class VolumeForm extends CustomForm {
	private BVC $plugin;
	private ConfigManager $config;

	public function __construct(BVC $plugin, ConfigManager $config) {
		$this->plugin = $plugin;
		$this->config = $config;
		parent::__construct(function(Player $player, mixed $data) : void {
			$this->handleResponse($player, $data);
		});
		$this->setTitle($config->message('ui_volume_title'));
		$this->addLabel($config->message('ui_volume_content'));
		$this->addSlider($config->message('ui_volume_slider'), 0, 150, 1, 100, 'volume');
	}

	public function handleResponse(Player $player, mixed $data) : void {
		if ($data === null) {
			return;
		}
		if (!is_array($data)) {
			return;
		}
		$volumeValue = $data['volume'] ?? 100;
		if (!is_int($volumeValue) && !is_float($volumeValue)) {
			$volumeValue = 100;
		}
		$volume = (int) $volumeValue;
		$this->plugin->debug("[VolumeForm] Player '{$player->getName()}' set volume to {$volume}");
		$this->plugin->getVoiceService()->setVolumeFor($player, $player, $volume);
		$player->sendMessage($this->config->message('volume_set', [
			'level' => (string) $volume,
			'target' => $player->getName(),
		]));
	}
}
