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
final class MuteSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'mute', 'Mute or unmute the microphone');
        $this->setAliases([]);
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.mute', 'bvc.mute'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('state'));
        $this->registerArgument(1, new TargetPlayerArgument(true, 'player'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $stateRaw = $args['state'] ?? null;
        $parsed = Util::parseBoolean($stateRaw !== null ? (string) $stateRaw : null);
        if($parsed === null){
            $sender->sendMessage($this->plugin->config->message('boolean_usage', ['sub' => 'mute']));
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
            $target = Util::getOnlinePlayer($targetName);
            if($target === null){
                $sender->sendMessage($this->plugin->config->message('player_not_found', ['name' => $targetName]));
                return;
            }
            $this->plugin->voiceService->toggleMuteFor($sender, $target, $parsed);
            return;
        }

        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }
        $this->plugin->voiceService->toggleMute($sender, $parsed);
    }
}
