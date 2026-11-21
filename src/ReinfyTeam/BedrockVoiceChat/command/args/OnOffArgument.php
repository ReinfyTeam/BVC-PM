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

namespace ReinfyTeam\BedrockVoiceChat\command\args;

use CortexPE\Commando\args\StringEnumArgument;
use pocketmine\command\CommandSender;
use function in_array;
use function strtolower;

class OnOffArgument extends StringEnumArgument {
	protected const VALUES = [
		'on' => true,
		'off' => false,
		'true' => true,
		'false' => false,
	];

	public function __construct(string $name = 'state', bool $optional = false) {
		parent::__construct($name, $optional);
	}

	public function getTypeName() : string {
		return 'on|off';
	}

	public function getEnumName() : string {
		return 'on_off';
	}

	public function canParse(string $testString, CommandSender $sender) : bool {
		return parent::canParse($testString, $sender) || in_array(strtolower($testString), ['1', '0', 'yes', 'no'], true);
	}

	public function parse(string $argument, CommandSender $sender) : bool {
		$lower = strtolower($argument);
		if (isset(self::VALUES[$lower])) {
			return (bool) self::VALUES[$lower];
		}
		return match ($lower) {
			'1', 'yes' => true,
			'0', 'no' => false,
			default => false,
		};
	}
}
