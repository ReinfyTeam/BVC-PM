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

use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\args\TargetPlayerArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function strcasecmp;
use function trim;

/**
 * @property BVC $plugin
 */
final class DiscSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'disc', 'Create a BVC music disc');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->config->getPermission('bvc.disc', 'bvc.disc'));
		$this->setPermissionMessage($this->plugin->config->message('no_permission'));
		$this->registerArgument(0, new RawStringArgument('audioId'));
		$this->registerArgument(1, new TargetPlayerArgument(true, 'player'));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$audioId = $args['audioId'] ?? null;
		if (!is_string($audioId)) {
			$audioId = null;
		}
		if ($audioId === null || trim($audioId) === '') {
			$sender->sendMessage($this->plugin->config->message('disc_usage'));
			return;
		}

		$targetName = '';
		if (isset($args['player']) && is_string($args['player'])) {
			$targetName = trim($args['player']);
		}
		if ($targetName !== '') {
			if ($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0) {
				if (!Util::isAdmin($sender, $this->plugin)) {
					$sender->sendMessage($this->plugin->config->message('only_self_allowed'));
					return;
				}
			}
			$targetPlayer = Util::getOnlinePlayer($targetName);
			if ($targetPlayer === null) {
				$sender->sendMessage($this->plugin->config->message('player_not_found', ['name' => $targetName]));
				return;
			}
			$this->plugin->audioService->giveBvcDisc($targetPlayer, (string) $audioId);
			if (!$sender instanceof Player || !$sender->getUniqueId()->equals($targetPlayer->getUniqueId())) {
				$sender->sendMessage($this->plugin->config->message('disc_created_other', ['target' => $targetPlayer->getName()]));
			}
			return;
		}

		if (!$sender instanceof Player) {
			$sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
			return;
		}
		$this->plugin->audioService->giveBvcDisc($sender, (string) $audioId);
	}
}
