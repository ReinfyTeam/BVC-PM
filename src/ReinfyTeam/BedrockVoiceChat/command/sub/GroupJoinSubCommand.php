<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;

/**
 * @property BVC $plugin
 */
final class GroupJoinSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'groupjoin', 'Join a voice group by share code');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.group', 'bvc.group'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('code'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $code = $args['code'] ?? null;
        if($code === null || trim((string) $code) === ''){
            $sender->sendMessage($this->plugin->config->message('group_join_usage'));
            return;
        }
        $this->plugin->voiceService->joinGroup($sender, (string) $code);
    }
}
