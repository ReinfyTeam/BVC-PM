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

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use function is_array;
use function is_string;
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
		$this->plugin->debug("debug_disc_submitted", ['player' => $player->getName(), 'audio_id' => $audioId]);
		$this->plugin->getAudioService()->giveBvcDisc($player, $audioId);
	}
}
