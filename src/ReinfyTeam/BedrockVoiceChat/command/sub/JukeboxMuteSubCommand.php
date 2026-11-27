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

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\command\args\OnOffArgument;
use function is_bool;

/**
 * @property BVC $plugin
 */
final class JukeboxMuteSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'jukeboxmute', 'Toggle jukebox audio');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->getConfigManager()->getPermission('bvc', 'bvc.use'));
		$this->setPermissionMessage($this->plugin->getConfigManager()->message('no_permission'));
		$this->registerArgument(0, new OnOffArgument('state'));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$state = $args['state'] ?? null;
		if (!is_bool($state)) {
			$sender->sendMessage($this->plugin->getConfigManager()->message('jukebox_mute_usage'));
			return;
		}
		$this->plugin->getVoiceService()->toggleJukeboxMute($sender, $state);
	}
}
