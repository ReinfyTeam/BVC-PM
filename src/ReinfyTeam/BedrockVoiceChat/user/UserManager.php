<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\user;

use pocketmine\player\Player;
use pocketmine\Server;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\config\ConfigManager;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\service\VoiceService;

/**
 * Manages Bedrock Voice Chat users, caching their voice state and offering query APIs.
 *
 * Example usage:
 *   $userManager = BVC::getInstance()->getUserManager();
 *   if ($userManager->isSpeaking($player)) { ... }
 *   if ($userManager->isMuted($player)) { ... }
 *   if ($userManager->isDeafened($player)) { ... }
 *   $user = $userManager->getUser($player);
 */
final class UserManager{

    private static ?self $instance = null;

    private BVC $plugin;
    private ConfigManager $config;
    private HttpClient $http;
    private ?VoiceService $voiceService = null;

    /** @var array<string, User> Keyed by lowercase player name */
    private array $users = [];

    public function __construct(BVC $plugin, ConfigManager $config, HttpClient $http, ?VoiceService $voiceService = null){
        self::$instance = $this;
        $this->plugin = $plugin;
        $this->config = $config;
        $this->http = $http;
        $this->voiceService = $voiceService;
    }

    public static function getInstance(): self{
        if(self::$instance === null){
            throw new \RuntimeException("UserManager is not initialized yet. Ensure BVC plugin is enabled.");
        }
        return self::$instance;
    }

    public function getPlugin(): BVC{
        return $this->plugin;
    }

    public function getConfig(): ConfigManager{
        return $this->config;
    }

    /**
     * Resolve a User instance by player object or name. Returns null if not cached yet.
     */
    public function getUser(Player|string $player): ?User{
        $key = $this->resolveKey($player);
        return $this->users[$key] ?? null;
    }

    /**
     * Get an existing User or create a new one if not yet cached.
     */
    public function getOrCreateUser(Player|string $player): User{
        $key = $this->resolveKey($player);
        if(!isset($this->users[$key])){
            $name = $player instanceof Player ? $player->getName() : $player;
            $uuid = $player instanceof Player ? $player->getUniqueId()->toString() : null;
            $this->users[$key] = new User($name, $uuid);
            $this->plugin->debug("[UserManager] Cached user for '{$name}'");
        }
        return $this->users[$key];
    }

    /**
     * Alias for getOrCreateUser.
     */
    public function get(Player|string $player): User{
        return $this->getOrCreateUser($player);
    }

    public function hasUser(Player|string $player): bool{
        return isset($this->users[$this->resolveKey($player)]);
    }

    public function removeUser(Player|string $player): void{
        $key = $this->resolveKey($player);
        if(isset($this->users[$key])){
            $this->plugin->debug("[UserManager] Removed user '{$key}' from cache");
            unset($this->users[$key]);
        }
    }

    /**
     * @return array<string, User>
     */
    public function getOnlineUsers(): array{
        $online = [];
        foreach(Server::getInstance()->getOnlinePlayers() as $player){
            $online[$player->getName()] = $this->getOrCreateUser($player);
        }
        return $online;
    }

    /**
     * @return array<string, User>
     */
    public function getAllUsers(): array{
        return $this->users;
    }

    /** Check whether a player's microphone is muted. */
    public function isMuted(Player|string $player): bool{
        return $this->getUser($player)?->isMuted() ?? false;
    }

    /** Check whether a player is deafened. */
    public function isDeafened(Player|string $player): bool{
        return $this->getUser($player)?->isDeafened() ?? false;
    }

    /** Check whether a player is currently transmitting audio / speaking. */
    public function isSpeaking(Player|string $player): bool{
        return $this->getUser($player)?->isSpeaking() ?? false;
    }

    /** Alias for isSpeaking. */
    public function isTalking(Player|string $player): bool{
        return $this->isSpeaking($player);
    }

    /** Check whether a player is recording their voice session. */
    public function isRecording(Player|string $player): bool{
        return $this->getUser($player)?->isRecording() ?? false;
    }

    /** Check whether a player has an active BVC session. */
    public function isConnected(Player|string $player): bool{
        return $this->getUser($player)?->isConnected() ?? false;
    }

    /** Get a player's current voice group name/code, or null if not in a group. */
    public function getGroup(Player|string $player): ?string{
        return $this->getUser($player)?->getGroup();
    }

    /** Get a player's voice volume setting (0.0 - 1.5). */
    public function getVolume(Player|string $player): float{
        return $this->getUser($player)?->getVolume() ?? 1.0;
    }

    public function setMuted(Player|string $player, bool $muted, bool $sendToBackend = true): void{
        $this->getOrCreateUser($player)->setMuted($muted, $sendToBackend);
    }

    public function setDeafened(Player|string $player, bool $deafened, bool $sendToBackend = true): void{
        $this->getOrCreateUser($player)->setDeafened($deafened, $sendToBackend);
    }

    public function setSpeaking(Player|string $player, bool $speaking): void{
        $this->getOrCreateUser($player)->setSpeaking($speaking);
    }

    public function setTalking(Player|string $player, bool $talking): void{
        $this->setSpeaking($player, $talking);
    }

    public function setRecording(Player|string $player, bool $recording, bool $sendToBackend = true): void{
        $this->getOrCreateUser($player)->setRecording($recording, $sendToBackend);
    }

    public function setVolume(Player|string $player, float|int $volume, bool $sendToBackend = true): void{
        $this->getOrCreateUser($player)->setVolume($volume, $sendToBackend);
    }

    /**
     * Asynchronously query the BVC backend for a player's real-time voice state.
     * Updates the cached User and invokes $callback when finished.
     *
     * @param (\Closure(User $user, bool $success, string $error): void)|null $callback
     */
    public function fetchState(Player|string $player, ?\Closure $callback = null): void{
        $playerName = $player instanceof Player ? $player->getName() : $player;
        $user = $this->getOrCreateUser($player);

        if(!$this->voiceService?->isConfigured()){
            if($callback !== null){
                $callback($user, false, "Backend is not configured");
            }
            return;
        }

        $url = $this->voiceService->baseUrl() . '/api/state?id=' . rawurlencode($playerName) . '&game=minecraft';
        $this->http->request('GET', $url, $this->voiceService->headers(), null,
            function(int $status, string $body, string $error) use ($user, $callback): void{
                if($status < 200 || $status >= 300){
                    $user->setConnected(false);
                    if($callback !== null){
                        $callback($user, false, $error !== '' ? $error : "HTTP {$status}");
                    }
                    return;
                }
                $state = json_decode($body, true);
                if(!is_array($state)){
                    if($callback !== null){
                        $callback($user, false, "Invalid JSON state");
                    }
                    return;
                }
                $user->setMuted((bool) ($state['muted'] ?? false));
                $user->setDeafened((bool) ($state['deafened'] ?? false));
                $user->setRecording((bool) ($state['recording'] ?? false));
                $user->setSpeaking((bool) ($state['speaking'] ?? $state['talking'] ?? false));
                $user->setConnected(true);
                $group = $state['current_group'] ?? null;
                $user->setGroup(is_string($group) && $group !== '' ? $group : null);
                $user->setLastSyncTime(time());

                if($callback !== null){
                    $callback($user, true, "");
                }
            }, $this->voiceService->httpTimeout());
    }

    /**
     * Refresh states for all online players asynchronously.
     *
     * @param (\Closure(int $syncedCount): void)|null $onComplete
     */
    public function syncAll(?\Closure $onComplete = null): void{
        $players = Server::getInstance()->getOnlinePlayers();
        if(count($players) === 0){
            if($onComplete !== null){
                $onComplete(0);
            }
            return;
        }
        $remaining = count($players);
        $synced = 0;
        foreach($players as $player){
            $this->fetchState($player, function(User $user, bool $success) use (&$remaining, &$synced, $onComplete): void{
                if($success){
                    $synced++;
                }
                $remaining--;
                if($remaining <= 0 && $onComplete !== null){
                    $onComplete($synced);
                }
            });
        }
    }

    private function resolveKey(Player|string $player): string{
        return strtolower($player instanceof Player ? $player->getName() : $player);
    }
}
