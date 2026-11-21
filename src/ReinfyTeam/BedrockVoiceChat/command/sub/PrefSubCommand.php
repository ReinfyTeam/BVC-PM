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
final class PrefSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'pref', 'Set voice preferences');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc.pref', 'bvc.pref'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('first'));
        $this->registerArgument(1, new RawStringArgument('second'));
        $this->registerArgument(2, new RawStringArgument('third', true));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $first = $args['first'] ?? null;
        $second = $args['second'] ?? null;
        $third = $args['third'] ?? null;

        // Case 1: /bvc pref <volume 0-150> <heard on|off> for caller
        if($third === null && is_numeric($first)){
            if(!$sender instanceof Player){
                $sender->sendMessage($this->plugin->config->message('player_must_be_ingame'));
                return;
            }
            $heardBool = Util::parseBoolean((string) $second);
            if($heardBool === null){
                $sender->sendMessage($this->plugin->config->message('pref_usage'));
                return;
            }
            $volume = (int) $first;
            $this->plugin->voiceService->setVolume($sender, $sender->getName(), $volume);
            $this->plugin->voiceService->toggleHearingAll($sender, $heardBool);
            return;
        }

        // Case 2: /bvc pref <player> <volume> <heard>
        if($first === null || $second === null || $third === null || !is_numeric($second)){
            $sender->sendMessage($this->plugin->config->message('pref_usage'));
            return;
        }

        $targetName = (string) $first;
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

        $heardBool = Util::parseBoolean((string) $third);
        if($heardBool === null){
            $sender->sendMessage($this->plugin->config->message('pref_usage'));
            return;
        }

        $volume = (int) $second;
        $this->plugin->voiceService->setVolume($sender, $targetName, $volume);
        $this->plugin->voiceService->toggleHear($sender, $targetName, $heardBool);
    }
}
