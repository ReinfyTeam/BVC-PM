<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class HearSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'hear', 'Toggle hearing voice audio');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.hear', 'bvc.hear'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('first'));
        $this->registerArgument(1, new RawStringArgument('second', true));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $first = $args['first'] ?? null;
        $second = $args['second'] ?? null;

        if($first === null){
            $sender->sendMessage($this->plugin->config->message('hear_usage'));
            return;
        }

        // Case 1: single argument -> /bvc hear <on|off> for caller
        if($second === null){
            $parsed = Util::parseBoolean((string) $first);
            if($parsed === null){
                $sender->sendMessage($this->plugin->config->message('hear_usage'));
                return;
            }
            if(!$sender instanceof Player){
                $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
                return;
            }
            $this->plugin->voiceService->toggleHearingAll($sender, $parsed);
            return;
        }

        // Case 2: two arguments -> /bvc hear <player> <on|off>
        $targetName = (string) $first;
        $parsed = Util::parseBoolean((string) $second);
        if($parsed === null){
            $sender->sendMessage($this->plugin->config->message('hear_usage'));
            return;
        }

        if($sender instanceof Player && strcasecmp($sender->getName(), $targetName) !== 0){
            if(!Util::isAdmin($sender, $this->plugin)){
                $sender->sendMessage($this->plugin->config->message('only_self_allowed'));
                return;
            }
        }

        if(!$sender instanceof Player){
            $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
            return;
        }

        $this->plugin->voiceService->toggleHear($sender, $targetName, $parsed);
    }
}
