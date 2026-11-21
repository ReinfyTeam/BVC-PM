<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\util;

use pocketmine\block\Jukebox;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Data carrier for jukebox right-click interactions.
 */
final class PlayerInteractData{

    public Player $player;
    public Jukebox $block;
    public World $world;
    public Vector3 $position;
    public \pocketmine\event\player\PlayerInteractEvent $event;

    public function __construct(
        Player $player,
        Jukebox $block,
        World $world,
        Vector3 $position,
        \pocketmine\event\player\PlayerInteractEvent $event
    ){
        $this->player = $player;
        $this->block = $block;
        $this->world = $world;
        $this->position = $position;
        $this->event = $event;
    }
}
