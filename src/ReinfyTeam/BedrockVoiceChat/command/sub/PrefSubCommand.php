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
use ReinfyTeam\BedrockVoiceChat\command\args\OnOffArgument;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function is_bool;
use function is_int;
use function is_string;
use function strcasecmp;

/**
 * @property BVC $plugin
 */
final class PrefSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'pref', 'Set voice preferences');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->config->getPermission('bvc.pref', 'bvc.pref'));
		$this->setPermissionMessage($this->plugin->config->message('no_permission'));
		$this->registerArgument(0, new IntegerArgument('volume'));
		$this->registerArgument(0, new TargetPlayerArgument(false, 'player'));
		$this->registerArgument(1, new OnOffArgument('heard', true));
		$this->registerArgument(1, new IntegerArgument('volume', true));
		$this->registerArgument(2, new OnOffArgument('heard', true));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		$volume = $args['volume'] ?? null;
		$heard = $args['heard'] ?? null;
		$targetRaw = $args['player'] ?? null;

		if (!is_int($volume) || !is_bool($heard)) {
			$sender->sendMessage($this->plugin->config->message('pref_usage'));
			return;
		}

		// Case 1: /bvc pref <volume 0-150> <heard on|off> for caller
		if (!is_string($targetRaw) || $targetRaw === '') {
			if (!$sender instanceof Player) {
				$sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
				return;
			}
			$this->plugin->voiceService->setVolume($sender, $sender->getName(), $volume);
			$this->plugin->voiceService->toggleHearingAll($sender, $heard);
			return;
		}

		// Case 2: /bvc pref <player> <volume> <heard>
		$targetName = $targetRaw;
		if ($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0) {
			if (!Util::isAdmin($sender, $this->plugin)) {
				$sender->sendMessage($this->plugin->config->message('only_self_allowed'));
				return;
			}
		}

		if (!$sender instanceof Player) {
			$sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
			return;
		}

		$this->plugin->voiceService->setVolume($sender, $targetName, $volume);
		$this->plugin->voiceService->toggleHear($sender, $targetName, $heard);
	}
}
