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
use function trim;

/**
 * Custom form for creating a BVC audio disc (uses jojoe77777 FormAPI).
 * All labels/titles sourced from messages.yml.
 */
final class DiscForm extends CustomForm {
	private BVC $plugin;

	public function __construct(BVC $plugin, ConfigManager $config) {
		$this->plugin = $plugin;
		parent::__construct(function(Player $player, mixed $data) : void {
			$this->handleResponse($player, $data);
		});
		$this->setTitle($config->message('ui_disc_title'));
		$this->addLabel($config->message('ui_disc_content'));
		$this->addInput($config->message('ui_disc_audio_id'), $config->message('ui_disc_placeholder'), null, 'audioId');
	}

	public function handleResponse(Player $player, mixed $data) : void {
		if ($data === null) {
			return;
		}
		if (!is_array($data)) {
			return;
		}
		$audioIdValue = $data['audioId'] ?? '';
		if (!is_string($audioIdValue)) {
			$audioIdValue = '';
		}
		$audioId = trim($audioIdValue);
		if ($audioId === '') {
			return;
		}
		$this->plugin->debug("[DiscForm] Player '{$player->getName()}' submitted audio ID: {$audioId}");
		$this->plugin->audioService->giveBvcDisc($player, $audioId);
	}
}
