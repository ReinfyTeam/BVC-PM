<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class HelpSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'help', 'Show available BVC subcommands');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc', 'bvc.use'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $isAdmin = Util::isAdmin($sender, $this->plugin);
        $sender->sendMessage($this->plugin->config->message('help_title'));
        $sender->sendMessage($this->plugin->config->message('help_mute'));
        $sender->sendMessage($this->plugin->config->message('help_deafen'));
        $sender->sendMessage($this->plugin->config->message('help_record'));
        $sender->sendMessage($this->plugin->config->message('help_volume'));
        $sender->sendMessage($this->plugin->config->message('help_hear'));
        $sender->sendMessage($this->plugin->config->message('help_jukeboxmute'));
        $sender->sendMessage($this->plugin->config->message('help_jukeboxvolume'));
        $sender->sendMessage($this->plugin->config->message('help_group'));
        $sender->sendMessage($this->plugin->config->message('help_status'));
        $sender->sendMessage($this->plugin->config->message('help_disc'));
        if($isAdmin){
            $sender->sendMessage($this->plugin->config->message('help_eject'));
        }
        $sender->sendMessage($this->plugin->config->message('help_query'));
        $sender->sendMessage($this->plugin->config->message('help_pref'));
        $sender->sendMessage($this->plugin->config->message('help_ui'));
        $sender->sendMessage($this->plugin->config->message('help_footer'));
    }
}
