# BedrockVoiceChat (BVC)

[![PocketMine-MP API](https://img.shields.io/badge/PMMP%20API-5.0.0-blue.svg)](https://pmmp.io/)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-LGPL--3.0-green.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max%20passed-brightgreen.svg)](phpstan.neon.dist)

**BedrockVoiceChat** is a high-performance, fully asynchronous proximity voice chat bridge for **PocketMine-MP (API 5.0.0)** servers. Designed to handle 100+ concurrent players without causing server tick drops or main-thread lag.

---

## ✨ Features

- 🎙️ **Proximity 3D Spatial Audio**: Voice audio volume scales realistically based on distance, head direction, and dimensions (Overworld, Nether, The End).
- ⚡ **Dedicated Asynchronous Worker Thread**: All network communication with the BVC backend runs on an isolated background thread (`HttpWorkerThread`). Your server main thread stays at 20 TPS.
- 👥 **Private Voice Groups**: Players can dynamically create and join isolated voice channels with share codes for teams, guilds, and parties.
- 📻 **Custom Jukebox Music Discs**: Create custom BVC audio discs (`/bvc disc <audio_id>`), insert them into vanilla jukeboxes, and enjoy synchronized spatial music with automatic ejection upon playback completion.
- 📱 **Interactive In-Game UI**: Intuitive Bedrock form UI (`/bvc ui`) with volume sliders, microphone toggles, deafen toggles, group management, and disc creation.
- ⌨️ **Native Bedrock Slash Commands**: Built using the **Commando** framework with native client autocomplete suggestions, hard enums (`on`/`off`), and type-safe validation.
- 🔌 **Rich Extensible Developer API**: 11 custom PocketMine events (`PlayerVoiceMuteEvent`, `JukeboxPlayAudioDiscEvent`, etc.) and public service getters for seamless third-party plugin integration. See [API.md](API.md) for full developer documentation.

---

## 📋 Requirements

| Component | Minimum Version | Notes |
|---|---|---|
| **PocketMine-MP** | `5.0.0` or higher | Full API 5 compatibility |
| **PHP** | `8.2` or higher | Built with strict typing (`declare(strict_types=1)`) |
| **BVC Desktop App** | Latest | Players need the BedrockVoiceChat desktop companion client |
| **BVC Voice Server** | Latest | Self-hosted or hosted BVC voice proxy |

---

## 🚀 Installation

1. Download the latest `BedrockVoiceChat.phar` from the [Releases](https://github.com/ReinfyTeam/BedrockVoiceChat/releases) page (or [build from source](#-building-from-source)).
2. Place the `.phar` file into your PocketMine-MP server's `plugins/` directory.
3. Start the server once to generate the default configuration files under `plugin_data/BVC/`.
4. Open `plugin_data/BVC/config.yml` and enter your BVC server credentials:
   ```yaml
   server_url: "https://your-bvc-server.example.com"
   access_token: "YOUR_SECRET_BEARER_TOKEN"
   ```
5. Restart your server or run `/bvc` to verify the connection.

---

## ⚙️ Configuration (`config.yml`)

```yaml
# BVC backend base URL. Do not include a trailing slash.
server_url: "https://voice.myserver.net"

# Bearer token issued by your BVC voice server.
access_token: "YOUR_SECRET_TOKEN"

# Stable instance UUID. Generated automatically on first boot if blank.
world_uuid: ""

# Server display name shown in the BVC desktop client.
world_name: "My Bedrock SMP"

# Position updates frequency (5 ticks = 250 ms = 4 updates/sec).
poll_interval_ticks: 5
minimum_players: 1

# Timeout in seconds for background HTTP requests.
http_timeout_seconds: 8

# Maps PocketMine world folder names to BVC dimension IDs.
dimension_map:
  world: "overworld"
  nether: "nether"
  the_end: "the_end"

# Automatically eject custom discs from jukeboxes when playback ends.
auto_eject_discs: true

# Customizable permissions.
permissions:
  bvc: "bvc.use"
  bvc.admin: "bvc.admin"
  bvc.ui: "bvc.ui"
  bvc.mute: "bvc.mute"
  bvc.deafen: "bvc.deafen"
  bvc.record: "bvc.record"
  bvc.volume: "bvc.volume"
  bvc.hear: "bvc.hear"
  bvc.disc: "bvc.disc"
  bvc.group: "bvc.group"
  bvc.status: "bvc.status"
  bvc.eject: "bvc.eject"
  bvc.query: "bvc.query"
  bvc.pref: "bvc.pref"

# Performance optimization for high-population servers.
performance:
  position_batch_size: 50
  enable_position_polling: true
  poll_interval_ticks: 5
```

---

## 🎮 Commands & Permissions

All commands are accessed via the root `/bvc` slash command:

| Subcommand | Syntax | Description | Default Permission |
|---|---|---|---|
| `help` | `/bvc help` | Display available voice chat commands | `bvc.use` (All) |
| `ui` | `/bvc ui` | Open the graphical voice control form | `bvc.ui` (All) |
| `mute` | `/bvc mute <on\|off> [player]` | Mute or unmute the microphone | `bvc.mute` (All / Admin for others) |
| `deafen` | `/bvc deafen <on\|off> [player]` | Deafen or undeafen incoming voice audio | `bvc.deafen` (All / Admin for others) |
| `record` | `/bvc record <on\|off> [player]` | Start or stop audio recording | `bvc.record` (All / Admin for others) |
| `volume` | `/bvc volume <0-150>`<br>`/bvc volume <player> <0-150>` | Adjust your voice volume or another player's volume | `bvc.volume` (All / Admin for others) |
| `hear` | `/bvc hear <on\|off>`<br>`/bvc hear <player> <on\|off>` | Toggle hearing everyone or a specific player | `bvc.hear` (All / Admin for others) |
| `jukeboxmute` | `/bvc jukeboxmute <on\|off>` | Toggle hearing jukebox spatial music | `bvc.use` (All) |
| `jukeboxvolume` | `/bvc jukeboxvolume <0-150>` | Adjust local jukebox music volume | `bvc.use` (All) |
| `groupcreate` | `/bvc groupcreate` | Create a new isolated voice chat group | `bvc.group` (All) |
| `groupjoin` | `/bvc groupjoin <share_code>` | Join an existing voice group via share code | `bvc.group` (All) |
| `groupleave` | `/bvc groupleave` | Leave the current voice group | `bvc.group` (All) |
| `groups` | `/bvc groups` | List available voice groups on the server | `bvc.group` (All) |
| `status` | `/bvc status [player]` | Display cached voice status for self or player | `bvc.status` (All / Admin for others) |
| `pref` | `/bvc pref <vol> <heard>`<br>`/bvc pref <player> <vol> <heard>` | Set volume & hearing preferences in one command | `bvc.pref` (All / Admin for others) |
| `disc` | `/bvc disc <audio_id> [player]` | Create a custom BVC music disc | `bvc.disc` (Op / Admin) |
| `eject` | `/bvc eject <x> <y> <z>` | Force eject a BVC disc from coordinates | `bvc.eject` (Op / Admin) |
| `query` | `/bvc query [player]` | Query voice state directly from backend | `bvc.query` (Op / Admin) |

---

## 📻 Custom Jukebox Music Discs

BedrockVoiceChat supports synchronized, proximity-based music disc playback directly through Minecraft jukeboxes:

1. **Obtain a Disc**: An admin or operator runs `/bvc disc <audio_id>` (e.g. `/bvc disc mysong_01`).
2. **Insert into Jukebox**: Right-click any in-game Jukebox with the disc.
3. **Playback**: The plugin contacts the BVC backend to start a spatial audio event centered at the jukebox's exact block position. Nearby players will hear the audio in full 3D stereo.
4. **Ejection**: Right-clicking the playing jukebox ejects the disc and cancels the audio event. If `auto_eject_discs: true` is configured, the disc automatically pops out when the track finishes playing.

---

## 🔌 Developer API & Events

BedrockVoiceChat provides an extensive developer API and a complete suite of custom PocketMine events.

```php
use ReinfyTeam\BedrockVoiceChat\BVC;

// Access core services via public getters
$bvc = BVC::getInstance();
$userManager  = $bvc->getUserManager();
$voiceService = $bvc->getVoiceService();
$audioService = $bvc->getAudioService();

// Check if a player is speaking
if ($userManager->getUser($player)?->isSpeaking()) {
    $player->sendTip("§a[Transmitting audio...]");
}

// Mute player programmatically
$voiceService->toggleMute($player, true);
```

### Custom Events Available

| Event Class | Cancellable | Description |
|---|:---:|---|
| `PlayerVoiceMuteEvent` | **Yes** | Triggered when a player's mic is muted/unmuted |
| `PlayerVoiceDeafenEvent` | **Yes** | Triggered when a player deafens/undeafens incoming audio |
| `PlayerVoiceRecordEvent` | **Yes** | Triggered when voice recording is started/stopped |
| `PlayerVoiceVolumeChangeEvent` | **Yes** | Triggered when voice volume is adjusted |
| `PlayerVoiceHearChangeEvent` | **Yes** | Triggered when hearing state for a player or `#all` changes |
| `PlayerVoiceJoinGroupEvent` | **Yes** | Triggered when a player creates or joins a voice group |
| `PlayerVoiceLeaveGroupEvent` | **Yes** | Triggered when a player leaves their voice group |
| `PlayerVoiceStateUpdateEvent` | No | Triggered when player voice state finishes syncing from backend |
| `JukeboxPlayAudioDiscEvent` | **Yes** | Triggered when a custom BVC disc starts playing in a jukebox |
| `JukeboxEjectAudioDiscEvent` | **Yes** | Triggered when a custom BVC disc is ejected from a jukebox |
| `JukeboxMuteEvent` | **Yes** | Triggered when jukebox audio hearing is toggled |

For full code recipes, event listener examples, and architecture details, refer to **[API.md](API.md)**.

---

## 🛠️ Building from Source

To compile the plugin into a `.phar` package locally:

```bash
# 1. Clone repository
git clone https://github.com/ReinfyTeam/BedrockVoiceChat.git
cd BedrockVoiceChat

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Run static analysis checks
php vendor/bin/phpstan analyse

# 4. Build PHAR
php -d phar.readonly=0 tools/build.php --output BedrockVoiceChat.phar
```

---

## 📄 License

This project is licensed under the **GNU Lesser General Public License v3.0 (LGPL-3.0)**. See the [LICENSE](LICENSE) file for more details.

Developed with ❤️ by the **[ReinfyTeam](https://github.com/ReinfyTeam)**.
