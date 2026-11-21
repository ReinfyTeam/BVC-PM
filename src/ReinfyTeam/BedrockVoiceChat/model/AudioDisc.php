<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\model;

use pocketmine\world\World;

/**
 * Tracks an active BVC audio disc placed in a jukebox.
 */
final class AudioDisc{

    public string $key;
    public ?string $eventId = null;
    public string $audioId;
    public World $world;
    public int $x;
    public int $y;
    public int $z;

    public function __construct(string $key, string $audioId, World $world, int $x, int $y, int $z){
        $this->key = $key;
        $this->audioId = $audioId;
        $this->world = $world;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public function hasEvent(): bool{
        return $this->eventId !== null;
    }
}
