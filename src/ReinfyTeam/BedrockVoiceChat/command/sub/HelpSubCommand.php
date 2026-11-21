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

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class HelpSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'help', 'Show available BVC subcommands');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->getConfigManager()->getPermission('bvc', 'bvc.use'));
		$this->setPermissionMessage($this->plugin->getConfigManager()->message('no_permission'));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$isAdmin = Util::isAdmin($sender, $this->plugin);
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_title'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_mute'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_deafen'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_record'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_volume'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_hear'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_jukeboxmute'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_jukeboxvolume'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_group'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_status'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_disc'));
		if ($isAdmin) {
			$sender->sendMessage($this->plugin->getConfigManager()->message('help_eject'));
		}
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_query'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_pref'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_ui'));
		$sender->sendMessage($this->plugin->getConfigManager()->message('help_footer'));
	}
}
