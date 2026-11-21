<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\form;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;

/**
 * Custom form for creating a BVC audio disc (uses jojoe77777 FormAPI).
 * All labels/titles sourced from messages.yml.
 */
final class DiscForm extends CustomForm{

    private BVC $plugin;
    private ConfigManager $config;

    public function __construct(BVC $plugin, ConfigManager $config){
        $this->plugin = $plugin;
        $this->config = $config;
        parent::__construct(function(Player $player, mixed $data): void{
            $this->handleResponse($player, $data);
        });
        $this->setTitle($config->message('ui_disc_title'));
        $this->addLabel($config->message('ui_disc_content'));
        $this->addInput($config->message('ui_disc_audio_id'), $config->message('ui_disc_placeholder'), null, 'audioId');
    }

    public function handleResponse(Player $player, mixed $data): void{
        if($data === null){
            return;
        }
        $audioId = trim((string) ($data['audioId'] ?? ''));
        if($audioId === ''){
            return;
        }
        $this->plugin->debug("[DiscForm] Player '{$player->getName()}' submitted audio ID: {$audioId}");
        $this->plugin->audioService->giveBvcDisc($player, $audioId);
    }
}
