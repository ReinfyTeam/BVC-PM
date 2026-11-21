<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * @property BVC $plugin
 */
final class JukeboxMuteSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'jukeboxmute', 'Toggle jukebox audio');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc', 'bvc.use'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new RawStringArgument('state'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $state = $args['state'] ?? null;
        $parsed = Util::parseBoolean($state !== null ? (string) $state : null);
        if($parsed === null){
            $sender->sendMessage($this->plugin->config->message('jukebox_mute_usage'));
            return;
        }
        $this->plugin->voiceService->toggleJukeboxMute($sender, $parsed);
    }
}
