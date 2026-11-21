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
final class QuerySubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'query', 'Query voice state from the backend');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->config->getPermission('bvc.query', 'bvc.query'));
		$this->setPermissionMessage($this->plugin->config->message('no_permission'));
		$this->registerArgument(0, new TargetPlayerArgument(true, 'player'));
	}

	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$targetName = isset($args['player']) ? trim((string) $args['player']) : '';
		if ($targetName !== '') {
			if ($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0) {
				if (!Util::isAdmin($sender, $this->plugin)) {
					$sender->sendMessage($this->plugin->config->message('only_self_allowed'));
					return;
				}
			}
			$target = Util::resolveOnlinePlayer($targetName) ?? $targetName;
			$this->plugin->voiceService->getStatusFor($sender, $target);
			return;
		}

		if (!$sender instanceof Player) {
			$sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
			return;
		}
		$this->plugin->voiceService->getStatus($sender);
	}
}
