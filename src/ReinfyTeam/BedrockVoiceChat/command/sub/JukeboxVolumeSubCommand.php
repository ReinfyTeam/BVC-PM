<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command\sub;

use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\BaseSubCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;

/**
 * @property BVC $plugin
 */
final class JukeboxVolumeSubCommand extends BaseSubCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'jukeboxvolume', 'Set jukebox volume');
    }

    protected function prepare(): void{
        $this->setPermission($this->plugin->config->getPermission('bvc', 'bvc.use'));
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));
        $this->registerArgument(0, new IntegerArgument('level'));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        $level = $args['level'] ?? null;
        if($level === null || !is_numeric($level)){
            $sender->sendMessage($this->plugin->config->message('jukebox_volume_usage'));
            return;
        }
        $this->plugin->voiceService->setJukeboxVolume($sender, (int) $level);
    }
}
