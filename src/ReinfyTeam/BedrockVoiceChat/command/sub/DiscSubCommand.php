<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\args\TargetPlayerArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class DiscSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'disc', 'Create a BVC music disc');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.disc', 'bvc.disc'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('audioId'));
        $this->registerArgument(1, new TargetPlayerArgument(true, 'player'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $audioId = $args['audioId'] ?? null;
        if($audioId === null || trim((string) $audioId) === ''){
            $sender->sendMessage($this->plugin->config->message('disc_usage'));
            return;
        }

        $targetName = isset($args['player']) ? trim((string) $args['player']) : '';
        if($targetName !== ''){
            if($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0){
                if(!Util::isAdmin($sender, $this->plugin)){
                    $sender->sendMessage($this->plugin->config->message('only_self_allowed'));
                    return;
                }
            }
            $targetPlayer = Util::getOnlinePlayer($targetName);
            if($targetPlayer === null){
                $sender->sendMessage($this->plugin->config->message('player_not_found', ['name' => $targetName]));
                return;
            }
            $this->plugin->audioService->giveBvcDisc($targetPlayer, (string) $audioId);
            if(!$sender instanceof Player || !$sender->getUniqueId()->equals($targetPlayer->getUniqueId())){
                $sender->sendMessage($this->plugin->config->message('disc_created_other', ['target' => $targetPlayer->getName()]));
            }
            return;
        }

        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $this->plugin->audioService->giveBvcDisc($sender, (string) $audioId);
    }
}
