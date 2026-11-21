<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\command;

use CortexPE\Commando\BaseCommand;
use pocketmine\command\CommandSender;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\command\sub\DeafenSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\DiscSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\EjectSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\GroupCreateSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\GroupJoinSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\GroupLeaveSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\GroupsSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\HearSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\HelpSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\JukeboxMuteSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\JukeboxVolumeSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\MuteSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\PrefSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\QuerySubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\RecordSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\StatusSubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\UISubCommand;
use ReinfyTeam\BedrockVoiceChat\command\sub\VolumeSubCommand;

/**
 * Main /bvc command using Commando framework with subcommands.
 *
 * @property BVC $plugin
 */
final class BVCCommand extends BaseCommand{

    public function __construct(BVC $plugin){
        parent::__construct($plugin, 'bvc', 'Bedrock Voice Chat control commands', []);
    }

    protected function prepare(): void{
        $perm = $this->plugin->config->getPermission('bvc', 'bvc.use');
        $this->setPermission($perm);
        $this->setPermissionMessage($this->plugin->config->message('no_permission'));

        $this->registerSubCommand(new HelpSubCommand($this->plugin));
        $this->registerSubCommand(new MuteSubCommand($this->plugin));
        $this->registerSubCommand(new DeafenSubCommand($this->plugin));
        $this->registerSubCommand(new RecordSubCommand($this->plugin));
        $this->registerSubCommand(new VolumeSubCommand($this->plugin));
        $this->registerSubCommand(new HearSubCommand($this->plugin));
        $this->registerSubCommand(new JukeboxMuteSubCommand($this->plugin));
        $this->registerSubCommand(new JukeboxVolumeSubCommand($this->plugin));
        $this->registerSubCommand(new GroupCreateSubCommand($this->plugin));
        $this->registerSubCommand(new GroupJoinSubCommand($this->plugin));
        $this->registerSubCommand(new GroupLeaveSubCommand($this->plugin));
        $this->registerSubCommand(new GroupsSubCommand($this->plugin));
        $this->registerSubCommand(new StatusSubCommand($this->plugin));
        $this->registerSubCommand(new QuerySubCommand($this->plugin));
        $this->registerSubCommand(new PrefSubCommand($this->plugin));
        $this->registerSubCommand(new DiscSubCommand($this->plugin));
        $this->registerSubCommand(new EjectSubCommand($this->plugin));
        $this->registerSubCommand(new UISubCommand($this->plugin));
    }

    public function onRun(CommandSender $sender, string $aliasUsed, array $args): void{
        // Default: show help if no subcommand matched
        $sender->sendMessage($this->plugin->config->message('help_title'));
        $sender->sendMessage($this->plugin->config->message('help_hint'));
    }
}
