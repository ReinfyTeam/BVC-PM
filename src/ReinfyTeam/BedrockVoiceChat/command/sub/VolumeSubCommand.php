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

use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\args\TargetPlayerArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function is_int;
use function is_string;
use function strcasecmp;

/**
 * @property BVC $plugin
 */
final class VolumeSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'volume', 'Set voice volume');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->getConfigManager()->getPermission('bvc.volume', 'bvc.volume'));
		$this->setPermissionMessage($this->plugin->getConfigManager()->message('no_permission'));
		$this->registerArgument(0, new IntegerArgument('level'));
		$this->registerArgument(0, new TargetPlayerArgument(false, 'player'));
		$this->registerArgument(1, new IntegerArgument('level', true));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$level = $args['level'] ?? null;
		$targetRaw = $args['player'] ?? null;

		if (!is_int($level)) {
			$sender->sendMessage($this->plugin->getConfigManager()->message('volume_usage'));
			return;
		}

		// Case 1: single argument -> /bvc volume <level> for caller
		if (!is_string($targetRaw) || $targetRaw === '') {
			if (!$sender instanceof Player) {
				$sender->sendMessage($this->plugin->getConfigManager()->message('player_must_be_ingame'));
				return;
			}
			$this->plugin->getVoiceService()->setVolumeFor($sender, $sender, $level);
			return;
		}

		// Case 2: two arguments -> /bvc volume <player> <level>
		$targetName = $targetRaw;
		if ($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0) {
			if (!Util::isAdmin($sender, $this->plugin)) {
				$sender->sendMessage($this->plugin->getConfigManager()->message('only_self_allowed'));
				return;
			}
		}

		$target = Util::getOnlinePlayer($targetName);
		if ($target === null) {
			$sender->sendMessage($this->plugin->getConfigManager()->message('player_not_found', ['name' => $targetName]));
			return;
		}

		$this->plugin->getVoiceService()->setVolumeFor($sender, $target, $level);
	}
}
