# BedrockVoiceChat (BVC) — Developer API Documentation

Comprehensive guide and API reference for integrating with the **BedrockVoiceChat** PocketMine-MP plugin.

---

## 1. Getting Started

### 1.1 Plugin Dependency Setup

In your plugin's `plugin.yml`, declare BedrockVoiceChat as a dependency or soft-dependency:

```yaml
# Require BedrockVoiceChat (hard dependency)
depend:
  - BedrockVoiceChat

# Or allow running without it (soft dependency)
softdepend:
  - BedrockVoiceChat
```

### 1.2 Accessing the BVC API

The primary entry point is the singleton instance `BVC::getInstance()`:

```php
use ReinfyTeam\BedrockVoiceChat\BVC;

// Check if BVC is loaded and enabled
$bvcPlugin = $this->getServer()->getPluginManager()->getPlugin("BedrockVoiceChat");
if ($bvcPlugin instanceof BVC && $bvcPlugin->isEnabled()) {
    $bvc = BVC::getInstance();
    
    // Access core services
    $userManager     = $bvc->getUserManager();     // User state tracking & queries
    $voiceService    = $bvc->voiceService;        // Backend voice control (mute, deafen, volume, etc.)
    $audioService    = $bvc->audioService;        // Jukebox audio playback & discs
    $positionService = $bvc->positionService;     // Spatial audio & positional updates
    $configManager   = $bvc->config;              // Plugin configuration & messages
}
```

---

## 2. Architecture & Threading Model

- **Asynchronous & Non-Blocking**: All HTTP communication with the BVC backend runs on a dedicated worker thread (`HttpWorkerThread`). Calling voice or audio methods will **never** freeze or lag the PocketMine-MP main server tick.
- **In-Memory Caching**: Player states (`User`) are cached in memory. Reading voice state properties (`isMuted()`, `isSpeaking()`, `isDeafened()`) is instant and synchronous.
- **Event-Driven**: All voice state mutations and audio lifecycle steps dispatch native PocketMine events that other plugins can listen to, inspect, modify, or cancel.

---

## 3. User & Voice State Management (`UserManager` & `User`)

The `UserManager` tracks cached voice states for all players connected to the server.

### 3.1 Resolving Users

```php
use ReinfyTeam\BedrockVoiceChat\BVC;
use pocketmine\player\Player;

$userManager = BVC::getInstance()->getUserManager();

// Get cached User by Player object or username (returns ?User)
$user = $userManager->getUser($player); // or $userManager->getUser("Steve");

// Get or create cached User (guaranteed User instance)
$user = $userManager->getOrCreateUser($player);
```

### 3.2 Querying Voice State (`User`)

```php
if ($user !== null) {
    $isMuted       = $user->isMuted();         // Microphone muted?
    $isDeafened    = $user->isDeafened();      // Audio deafened?
    $isSpeaking    = $user->isSpeaking();      // Currently transmitting audio? (alias: isTalking())
    $isRecording   = $user->isRecording();     // Recording active?
    $isConnected   = $user->isConnected();     // Connected to BVC desktop voice client?
    $isHearingAll  = $user->isHearingOthers(); // Hearing other players?
    $volumeFloat   = $user->getVolume();       // Volume float (0.0 to 1.5)
    $volumePercent = $user->getVolumePercent();// Volume percentage (0 to 150)
    $groupCode     = $user->getGroup();        // Active voice group share code or null
    $player        = $user->getPlayer();       // Underlying online Player instance or null
}
```

### 3.3 Mutating Voice State Programmatically

Setting `$sendToBackend = true` immediately dispatches the control payload asynchronously to the BVC server and the player's desktop app:

```php
// Mute / unmute microphone
$user->setMuted(true, sendToBackend: true);

// Deafen / undeafen audio
$user->setDeafened(true, sendToBackend: true);

// Start / stop voice recording
$user->setRecording(true, sendToBackend: true);

// Set volume (0 to 150%)
$user->setVolume(120, sendToBackend: true);

// Toggle hearing other players
$user->setHearingOthers(false, sendToBackend: true);
```

### 3.4 Asynchronous State Refresh from Backend

To pull the latest voice state directly from the BVC backend:

```php
$userManager->fetchState($player, function(User $user, bool $success, string $error): void {
    if ($success) {
        // State successfully updated and cached
        echo "{$user->getName()} connected: " . ($user->isConnected() ? "yes" : "no");
    } else {
        echo "Failed to fetch state: {$error}";
    }
});

// Or refresh all online players in batch:
$userManager->syncAll(function(int $syncedCount): void {
    echo "Synced {$syncedCount} online players.";
});
```

---

## 4. Voice Control Service (`VoiceService`)

`VoiceService` provides high-level control methods for manipulating player voice settings, voice groups, and jukebox global settings.

```php
$voiceService = BVC::getInstance()->voiceService;
```

### 4.1 Player Voice Controls

| Method | Description |
|---|---|
| `toggleMute(Player $player, bool $value): void` | Toggles microphone mute for a player |
| `toggleMuteFor(CommandSender $sender, Player $target, bool $value): void` | Sets target mute on behalf of a sender/admin |
| `toggleDeafen(Player $player, bool $value): void` | Toggles deafen (incoming voice) for a player |
| `toggleDeafenFor(CommandSender $sender, Player $target, bool $value): void` | Sets target deafen on behalf of a sender/admin |
| `toggleRecording(Player $player, bool $value): void` | Starts or stops recording for a player |
| `toggleRecordingFor(CommandSender $sender, Player $target, bool $value): void` | Sets target recording on behalf of a sender/admin |
| `setVolume(Player $player, string $targetName, int $level): void` | Sets caller's listening volume for a specific player (0-150) |
| `setVolumeFor(CommandSender $sender, Player $target, int $level): void` | Sets target's own master voice volume (0-150) |
| `toggleHear(Player $player, string $targetName, bool $value): void` | Mutes/unmutes audio received from a specific player |
| `toggleHearingAll(Player $player, bool $value): void` | Toggles hearing audio from all players |

### 4.2 Group Management

| Method | Description |
|---|---|
| `createGroup(Player $player): void` | Requests creation of a new isolated voice group |
| `joinGroup(Player $player, string $shareCode): void` | Joins an existing group using its share code |
| `leaveGroup(Player $player): void` | Leaves the player's current voice group |
| `listGroups(Player $player): void` | Fetches and displays available groups to the player |

### 4.3 Jukebox Global Audio Controls

| Method | Description |
|---|---|
| `toggleJukeboxMute(CommandSender $sender, bool $muted): void` | Toggles hearing audio emitted by in-game jukeboxes |
| `setJukeboxVolume(CommandSender $sender, int $level): void` | Sets listening volume for jukebox audio (0-150) |

---

## 5. Audio & Jukebox Disc Service (`AudioService`)

`AudioService` manages spatial audio discs, jukebox interactions, and audio events.

```php
$audioService = BVC::getInstance()->audioService;
```

### 5.1 Creating and Giving Custom BVC Discs

```php
// Gives a custom BVC music disc bound to a specific audio ID or URL
$audioService->giveBvcDisc($player, "custom_song_01");
```

### 5.2 Querying and Managing Active Jukeboxes

```php
// Check all currently playing jukebox discs
$discs = $audioService->getActiveDiscs(); // array<string, AudioDisc>

// Check if a jukebox at a given location key is currently active
$key = Util::locationKey($world, $x, $y, $z);
if ($audioService->hasDiscAt($key)) {
    // Eject disc programmatically
    $audioService->ejectBvcDisc($key);
}

// Eject all active BVC jukebox discs server-wide
$audioService->ejectAllDiscs();
```

---

## 6. Custom Events Reference

BedrockVoiceChat dispatches custom events under the `ReinfyTeam\BedrockVoiceChat\event\` namespace. Other plugins can listen to these events to intercept actions, enforce rules (e.g. safe zones, admin mutes), or trigger side-effects.

### 6.1 Event Summary Table

| Event Class | Cancellable | Fired When | Key Data Methods |
|---|:---:|---|---|
| [`PlayerVoiceMuteEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceMuteEvent.php) | **Yes** | Player microphone mute is toggled | `getPlayer()`, `isMuted()`, `setMuted(bool)`, `getInitiator()` |
| [`PlayerVoiceDeafenEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceDeafenEvent.php) | **Yes** | Player deafen is toggled | `getPlayer()`, `isDeafened()`, `setDeafened(bool)`, `getInitiator()` |
| [`PlayerVoiceRecordEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceRecordEvent.php) | **Yes** | Voice recording is started or stopped | `getPlayer()`, `isRecording()`, `setRecording(bool)`, `getInitiator()` |
| [`PlayerVoiceVolumeChangeEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceVolumeChangeEvent.php) | **Yes** | Voice volume is adjusted | `getPlayer()`, `getTarget()`, `getOldVolume()`, `getNewVolume()`, `setNewVolume(int)` |
| [`PlayerVoiceHearChangeEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceHearChangeEvent.php) | **Yes** | Hearing state for player or `#all` changes | `getPlayer()`, `getTarget()`, `isHeard()`, `setHeard(bool)` |
| [`PlayerVoiceJoinGroupEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceJoinGroupEvent.php) | **Yes** | Player creates or joins a voice group | `getPlayer()`, `getGroup()`, `setGroup(string)` |
| [`PlayerVoiceLeaveGroupEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceLeaveGroupEvent.php) | **Yes** | Player leaves a voice group | `getPlayer()`, `getGroup()` |
| [`PlayerVoiceStateUpdateEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/PlayerVoiceStateUpdateEvent.php) | No | Backend voice state sync completes | `getPlayer()`, `getUser()` |
| [`JukeboxPlayAudioDiscEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/JukeboxPlayAudioDiscEvent.php) | **Yes** | BVC disc inserted into jukebox | `getPlayer()`, `getJukebox()`, `getAudioId()`, `getPosition()` |
| [`JukeboxEjectAudioDiscEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/JukeboxEjectAudioDiscEvent.php) | **Yes** | BVC disc ejected from jukebox | `getPlayer()`, `getJukebox()`, `getAudioId()`, `getPosition()` |
| [`JukeboxMuteEvent`](file:///C:/Users/Rods/Desktop/PocketMine-MP/plugins_sources/BedrockVoiceChat/src/ReinfyTeam/BedrockVoiceChat/event/JukeboxMuteEvent.php) | **Yes** | Jukebox hearing muted or unmuted | `getSender()`, `isMuted()`, `setMuted(bool)` |

---

## 7. Event Listening & Cancellation Examples

### 7.1 Listening to Events

Register a listener in your plugin's `onEnable()`:

```php
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use ReinfyTeam\BedrockVoiceChat\event\PlayerVoiceMuteEvent;
use ReinfyTeam\BedrockVoiceChat\event\PlayerVoiceStateUpdateEvent;

class VoiceListener implements Listener {

    /**
     * Prevent players from unmuting in a silent / test zone.
     */
    public function onVoiceMute(PlayerVoiceMuteEvent $event): void {
        $player = $event->getPlayer();
        $isMuting = $event->isMuted();

        // If player is attempting to unmute while in a silent arena:
        if (!$isMuting && $player->getWorld()->getFolderName() === "silence_arena") {
            $event->cancel();
            $player->sendMessage("§cYou cannot unmute your microphone in this area!");
        }
    }

    /**
     * Detect when a player starts or stops speaking.
     */
    public function onStateUpdate(PlayerVoiceStateUpdateEvent $event): void {
        $player = $event->getPlayer();
        $user = $event->getUser();

        if ($user->isSpeaking()) {
            // Player is actively speaking right now
            $player->sendTip("§a[Voice Transmitting...]");
        }
    }
}
```

### 7.2 Triggering / Firing Events Manually

Other plugins can instantiate and call BVC events directly to notify third-party integrations:

```php
use ReinfyTeam\BedrockVoiceChat\event\PlayerVoiceMuteEvent;

$event = new PlayerVoiceMuteEvent($player, true, $moderator);
$event->call();

if (!$event->isCancelled()) {
    // Event passed all listener checks; proceed with action
    BVC::getInstance()->voiceService->toggleMute($player, true);
}
```

---

## 8. Practical Integration Recipes

### Recipe 1: Mini-Game Team Isolation (Auto Group Assignment)

Automatically move players to isolated voice groups during mini-games:

```php
use pocketmine\player\Player;
use ReinfyTeam\BedrockVoiceChat\BVC;

class MiniGameManager {
    public function onGameStart(array $redTeam, array $blueTeam): void {
        $voiceService = BVC::getInstance()->voiceService;
        
        // Join red team to group channel
        foreach ($redTeam as $player) {
            $voiceService->joinGroup($player, "red_team_channel_code");
        }

        // Join blue team to group channel
        foreach ($blueTeam as $player) {
            $voiceService->joinGroup($player, "blue_team_channel_code");
        }
    }

    public function onGameEnd(array $allPlayers): void {
        $voiceService = BVC::getInstance()->voiceService;
        foreach ($allPlayers as $player) {
            $voiceService->leaveGroup($player);
        }
    }
}
```

### Recipe 2: Moderation Plugin (Enforcing Server-Side Mute)

Block unmuting if the player is server-muted by your moderation plugin:

```php
use pocketmine\event\Listener;
use ReinfyTeam\BedrockVoiceChat\event\PlayerVoiceMuteEvent;

class MuteEnforcerListener implements Listener {
    public function onVoiceMute(PlayerVoiceMuteEvent $event): void {
        $player = $event->getPlayer();
        
        // If your punishment plugin has marked this player as muted:
        if ($this->punishmentPlugin->isMuted($player->getName())) {
            if (!$event->isMuted()) { // Player trying to UNMUTE
                $event->cancel();
                $player->sendMessage("§cYou are muted by server staff and cannot use voice chat.");
            }
        }
    }
}
```

### Recipe 3: Jukebox Protection & Custom DJ Systems

Restrict jukebox playback to players with specific VIP ranks:

```php
use pocketmine\event\Listener;
use ReinfyTeam\BedrockVoiceChat\event\JukeboxPlayAudioDiscEvent;

class DjProtectionListener implements Listener {
    public function onPlayDisc(JukeboxPlayAudioDiscEvent $event): void {
        $player = $event->getPlayer();
        
        if (!$player->hasPermission("myplugin.dj.allowed")) {
            $event->cancel();
            $player->sendMessage("§cOnly DJs can play custom audio discs in jukeboxes!");
        }
    }
}
```
