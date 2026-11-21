<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\form;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;

/**
 * Custom form for setting voice volume (uses jojoe77777 FormAPI).
 * All labels/titles sourced from messages.yml.
 */
final class VolumeForm extends CustomForm{

    private BVC $plugin;
    private ConfigManager $config;

    public function __construct(BVC $plugin, ConfigManager $config){
        $this->plugin = $plugin;
        $this->config = $config;
        parent::__construct(function(Player $player, mixed $data): void{
            $this->handleResponse($player, $data);
        });
        $this->setTitle($config->message('ui_volume_title'));
        $this->addLabel($config->message('ui_volume_content'));
        $this->addSlider($config->message('ui_volume_slider'), 0, 150, 1, 100, 'volume');
    }

    public function handleResponse(Player $player, mixed $data): void{
        if($data === null){
            return;
        }
        $volume = (int) ($data['volume'] ?? 100);
        $this->plugin->debug("[VolumeForm] Player '{$player->getName()}' set volume to {$volume}");
        $this->plugin->voiceService->setVolumeFor($player, $player, $volume);
        $player->sendMessage($this->config->message('volume_set', [
            'level' => (string) $volume,
            'target' => $player->getName(),
        ]));
    }
}
