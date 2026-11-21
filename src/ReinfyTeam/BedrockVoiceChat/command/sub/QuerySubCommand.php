<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\TargetPlayerArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class QuerySubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'query', 'Query voice state from the backend');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.query', 'bvc.query'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new TargetPlayerArgument(true, 'player'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $targetName = isset($args['player']) ? trim((string) $args['player']) : '';
        if($targetName !== ''){
            if($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0){
                if(!Util::isAdmin($sender, $this->plugin)){
                    $sender->sendMessage($this->plugin->config->message('only_self_allowed'));
                    return;
                }
            }
            $target = Util::resolveOnlinePlayer($targetName) ?? $targetName;
            $this->plugin->voiceService->getStatusFor($sender, $target);
            return;
        }

        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $this->plugin->voiceService->getStatus($sender);
    }
}
