<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\form\MainForm;

/**
 * @property BVC $plugin
 */
final class UISubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'ui', 'Open the in-game voice settings UI');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.ui', 'bvc.ui'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $form = new MainForm($this->plugin, $this->plugin->config);
        $sender->sendForm($form);
    }
}
