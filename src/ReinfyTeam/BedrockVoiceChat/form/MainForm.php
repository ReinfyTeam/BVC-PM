<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\form;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\service\VoiceService;

/**
 * Main in-game UI form opened by /bvc ui (uses jojoe77777 FormAPI).
 * All labels/titles/buttons sourced from messages.yml.
 */
final class MainForm extends SimpleForm{

    private BVC $plugin;
    private ConfigManager $config;

    public function __construct(BVC $plugin, ConfigManager $config){
        $this->plugin = $plugin;
        $this->config = $config;
        parent::__construct(function(Player $player, mixed $data): void{
            $this->handleResponse($player, $data);
        });
        $this->setTitle($config->message('ui_title'));
        $this->setContent($config->message('ui_content'));
        $this->setupButtons();
    }

    private function setupButtons(): void{
        $this->addButton($this->config->message('ui_btn_microphone'));
        $this->addButton($this->config->message('ui_btn_deafen'));
        $this->addButton($this->config->message('ui_btn_recording'));
        $this->addButton($this->config->message('ui_btn_volume'));
        $this->addButton($this->config->message('ui_btn_hearing'));
        $this->addButton($this->config->message('ui_btn_groups'));
        $this->addButton($this->config->message('ui_btn_status'));
        $this->addButton($this->config->message('ui_btn_disc'));
        $this->addButton($this->config->message('ui_btn_close'));
    }

    public function handleResponse(Player $player, mixed $data): void{
        if($data === null){
            return; // Closed
        }
        $voiceService = $this->plugin->voiceService;
        $index = (int) $data;
        $this->plugin->debug("[MainForm] Player '{$player->getName()}' selected button index {$index}");

        match($index){
            0 => $this->toggleBool($player, $voiceService, 'mute'),
            1 => $this->toggleBool($player, $voiceService, 'deafen'),
            2 => $this->toggleBool($player, $voiceService, 'record'),
            3 => $this->openVolumeForm($player),
            4 => $this->toggleHearing($player, $voiceService),
            5 => $voiceService->listGroups($player),
            6 => $voiceService->getStatus($player),
            7 => $this->openDiscForm($player),
            default => null, // Close button
        };
    }

    private function toggleBool(Player $player, VoiceService $voiceService, string $type): void{
        $state = $voiceService->getPlayerState($player);
        $current = match($type){
            'mute' => $state->muted,
            'deafen' => $state->deafened,
            'record' => $state->recording,
            default => false
        };
        $newValue = !$current;
        $this->plugin->debug("[MainForm] Player '{$player->getName()}' toggling {$type} -> " . ($newValue ? 'on' : 'off'));
        match($type){
            'mute' => $voiceService->toggleMute($player, $newValue),
            'deafen' => $voiceService->toggleDeafen($player, $newValue),
            'record' => $voiceService->toggleRecording($player, $newValue),
            default => null,
        };
    }

    private function openVolumeForm(Player $player): void{
        $form = new VolumeForm($this->plugin, $this->config);
        $player->sendForm($form);
    }

    private function toggleHearing(Player $player, VoiceService $voiceService): void{
        $state = $voiceService->getPlayerState($player);
        $newValue = !$state->hearingOthers;
        $this->plugin->debug("[MainForm] Player '{$player->getName()}' toggling hearingOthers -> " . ($newValue ? 'on' : 'off'));
        $voiceService->toggleHearingAll($player, $newValue);
    }

    private function openDiscForm(Player $player): void{
        $form = new DiscForm($this->plugin, $this->config);
        $player->sendForm($form);
    }
}
