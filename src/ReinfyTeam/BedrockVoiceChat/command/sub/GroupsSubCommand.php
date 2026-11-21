<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;

/**
 * @property BVC $plugin
 */
final class GroupsSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'groups', 'List available voice groups');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.group', 'bvc.group'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $this->plugin->voiceService->listGroups($sender);
    }
}
