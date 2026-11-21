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
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;
use function is_int;

/**
 * @property BVC $plugin
 */
final class EjectSubCommand extends BaseSubCommand {
	public function __construct(BVC $plugin) {
		parent::__construct($plugin, 'eject', 'Eject a BVC disc at coordinates');
	}

	protected function prepare() : void {
		$this->setPermission($this->plugin->config->getPermission('bvc.eject', 'bvc.eject'));
		$this->setPermissionMessage($this->plugin->config->message('no_permission'));
		$this->registerArgument(0, new IntegerArgument('x'));
		$this->registerArgument(1, new IntegerArgument('y'));
		$this->registerArgument(2, new IntegerArgument('z'));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void {
		if (!Util::isAdmin($sender, $this->plugin) && !$sender->hasPermission($this->plugin->config->getPermission('bvc.eject', 'bvc.eject'))) {
			$sender->sendMessage($this->plugin->config->message('no_permission'));
			return;
		}
		$x = $args['x'] ?? null;
		$y = $args['y'] ?? null;
		$z = $args['z'] ?? null;
		if ($x === null || $y === null || $z === null || !is_int($x) || !is_int($y) || !is_int($z)) {
			$sender->sendMessage($this->plugin->config->message('eject_usage'));
			return;
		}
		$world = $sender instanceof Player ? $sender->getWorld() : $this->plugin->getServer()->getWorldManager()->getDefaultWorld();
		if ($world === null) {
			$sender->sendMessage($this->plugin->config->message('world_not_found'));
			return;
		}
		$this->plugin->audioService->forceEjectAt($world, (int) $x, (int) $y, (int) $z);
		$sender->sendMessage($this->plugin->config->message('eject_done', [
			'location' => $x . ', ' . $y . ', ' . $z
		]));
	}
}
