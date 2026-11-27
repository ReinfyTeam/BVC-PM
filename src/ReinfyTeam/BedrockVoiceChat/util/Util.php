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

namespace ReinfyTeam\BedrockVoiceChat\util;

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\World;
use ReinfyTeam\BedrockVoiceChat\BVC;
use function bin2hex;
use function chr;
use function method_exists;
use function ord;
use function random_bytes;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function substr;
use function trim;

/**
 * Static utility helpers shared across BVC classes.
 */
final class Util {
	/** Build a unique location key for a block position in a world. */
	public static function locationKey(World $world, int $x, int $y, int $z) : string {
		return $world->getFolderName() . ':' . $x . ':' . $y . ':' . $z;
	}

	/** Parse a "on|off|true|false|1|0" string into a bool, or null if invalid. */
	public static function parseBoolean(?string $value) : ?bool {
		if ($value === null) {
			return null;
		}
		return match (strtolower(trim($value))) {
			'1', 'true', 'on', 'yes' => true,
			'0', 'false', 'off', 'no' => false,
			default => null
		};
	}

	/** Resolve an online player by name (case-insensitive). Returns the canonical name or null. */
	public static function resolveOnlinePlayer(string $name) : ?string {
		foreach (Server::getInstance()->getOnlinePlayers() as $player) {
			if (strcasecmp($player->getName(), $name) === 0) {
				return $player->getName();
			}
		}
		return null;
	}

	/** Resolve an online Player instance by name (case-insensitive). */
	public static function getOnlinePlayer(string $name) : ?Player {
		$resolved = self::resolveOnlinePlayer($name);
		return $resolved !== null ? Server::getInstance()->getPlayerExact($resolved) : null;
	}

	/** Check whether a command sender is considered an administrator or operator. */
	public static function isAdmin(CommandSender $sender, BVC $plugin) : bool {
		if (!$sender instanceof Player) {
			return true;
		}
		if ($sender->getServer()->isOp($sender->getName())) {
			return true;
		}
		$adminPerm = $plugin->getConfigManager()->getPermission('bvc.admin', 'bvc.admin');
		return $sender->hasPermission($adminPerm);
	}

	/** Generate a random UUID v4 string. */
	public static function generateUuid() : string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
	}

	/** Map a PMMP world folder name to a BVC dimension name, using an optional override map.
	 * @param array<string, string> $dimensionMap
	 */
	public static function dimensionFor(World $world, array $dimensionMap) : string {
		$folder = $world->getFolderName();
		if (isset($dimensionMap[$folder])) {
			return (string) $dimensionMap[$folder];
		}
		$lower = strtolower($folder);
		if (str_contains($lower, 'nether')) {
			return 'nether';
		}
		if (str_contains($lower, 'end')) {
			return 'the_end';
		}
		return 'overworld';
	}

	/** Extract BVC audio ID from a record item's NBT tag. */
	public static function getBvcAudioId(?object $item) : ?string {
		if ($item === null || !method_exists($item, 'getNamedTag')) {
			return null;
		}
		$tag = $item->getNamedTag();
		/** @var \pocketmine\nbt\tag\CompoundTag $tag */
		if ($tag->getByte('bvc:is_bvc_disc', 0) !== 1) {
			return null;
		}
		$audioId = $tag->getString('bvc:audio_id', '');
		return $audioId !== '' ? $audioId : null;
	}

	/** Validate that a position is within reasonable Minecraft bounds. */
	public static function isValidPosition(float $x, float $y, float $z) : bool {
		return $x >= -3.0e7 && $x <= 3.0e7
			&& $y >= -3.0e7 && $y <= 3.0e7
			&& $z >= -3.0e7 && $z <= 3.0e7;
	}
}
