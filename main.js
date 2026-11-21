// src/main.ts
import { world as world9, system as system17 } from "@minecraft/server";

// src/dto/coordinates.ts
var Coordinates = class _Coordinates {
  constructor(x, y, z) {
    this.x = x;
    this.y = y;
    this.z = z;
  }
  static fromMinecraftLocation(location) {
    return new _Coordinates(location.x, location.y, location.z);
  }
  toJSON() {
    return {
      x: this.x,
      y: this.y,
      z: this.z
    };
  }
};

// src/dto/orientation.ts
var Orientation = class _Orientation {
  constructor(x, y) {
    this.x = x;
    this.y = y;
  }
  static fromMinecraftRotation(rotation) {
    return new _Orientation(rotation.x, rotation.y);
  }
  toJSON() {
    return {
      x: this.x,
      y: this.y
    };
  }
};

// src/dto/player.ts
import { GameMode } from "@minecraft/server";
var Player = class _Player {
  constructor(name, dimension, coordinates, whispering, orientation, spectator = false, world_uuid = void 0) {
    this.name = name;
    this.dimension = dimension;
    this.coordinates = coordinates;
    this.whispering = whispering;
    this.orientation = orientation;
    this.spectator = spectator;
    this.world_uuid = world_uuid;
  }
  static fromMinecraftPlayer(player, worldUuid) {
    return new _Player(
      player.name,
      player.dimension.id.replace("minecraft:", ""),
      Coordinates.fromMinecraftLocation(player.location),
      player.isSneaking,
      Orientation.fromMinecraftRotation(player.getRotation()),
      player.getGameMode() === GameMode.Spectator,
      worldUuid
    );
  }
  /**
   * Create a player DTO with death dimension override.
   * Dead players are placed at origin (0,0,0) in the "death" dimension.
   */
  static fromMinecraftPlayerDead(player, worldUuid) {
    return new _Player(
      player.name,
      "death" /* DEATH */,
      new Coordinates(0, 0, 0),
      player.isSneaking,
      Orientation.fromMinecraftRotation(player.getRotation()),
      false,
      worldUuid
    );
  }
  /**
   * Create a phantom player DTO for a player who has disconnected.
   * Uses death dimension, extreme coordinates, and spectator=true to guarantee
   * the server's proximity checks reject all audio routing for this player.
   */
  static fromDisconnectedPlayer(name, worldUuid) {
    return new _Player(
      name,
      "death" /* DEATH */,
      new Coordinates(-1e4, -1e4, -1e4),
      false,
      new Orientation(0, 0),
      true,
      worldUuid
    );
  }
  toJSON() {
    return {
      name: this.name,
      dimension: this.dimension,
      coordinates: this.coordinates.toJSON(),
      // Keyed `deafen` on the wire: BVC servers of either version read that key.
      deafen: this.whispering,
      orientation: this.orientation.toJSON(),
      spectator: this.spectator,
      ...this.world_uuid && { world_uuid: this.world_uuid }
    };
  }
};

// src/dto/payload.ts
var Payload = class _Payload {
  constructor(game = "minecraft", players) {
    this.game = game;
    this.players = players;
  }
  /**
   * Create a payload from Minecraft players.
   * @param players Array of Minecraft players
   * @param deadPlayers Set of player IDs who are currently dead
   * @param worldUuid Optional world UUID for multi-world isolation
   */
  static fromPlayers(players, deadPlayers2 = /* @__PURE__ */ new Set(), worldUuid) {
    const playerDtos = players.map(
      (p) => deadPlayers2.has(p.id) ? Player.fromMinecraftPlayerDead(p, worldUuid) : Player.fromMinecraftPlayer(p, worldUuid)
    );
    return new _Payload("minecraft", playerDtos);
  }
  toJSON() {
    return {
      game: this.game,
      players: this.players.map((p) => p.toJSON())
    };
  }
  toJSONString() {
    return JSON.stringify(this.toJSON());
  }
};

// src/dto/audio.ts
var AudioPlayRequest = class {
  constructor(audio_file_id, coordinates, dimension, world_uuid) {
    this.audio_file_id = audio_file_id;
    this.coordinates = coordinates;
    this.dimension = dimension;
    this.world_uuid = world_uuid;
  }
  toJSON() {
    return {
      audio_file_id: this.audio_file_id,
      game: {
        game: "minecraft",
        coordinates: this.coordinates.toJSON(),
        dimension: this.dimension,
        world_uuid: this.world_uuid
      }
    };
  }
};

// src/audio/player_manager.ts
import { world, system, ItemStack } from "@minecraft/server";
var STORAGE_KEY = "bvc:active_discs";
var AudioPlayerManager = class {
  constructor() {
    this.players = /* @__PURE__ */ new Map();
    this.sender = null;
  }
  setSender(sender) {
    this.sender = sender;
  }
  locationKey(worldUuid, coordinates) {
    return `${worldUuid}:${coordinates.x}:${coordinates.y}:${coordinates.z}`;
  }
  insertDisc(locationKey, audioId, dimensionId, coordinates, worldUuid, actor) {
    const state = {
      audioId,
      eventId: null,
      isPlaying: true,
      dimensionId,
      coordinates,
      worldUuid,
      autoEjectRunId: null
    };
    this.players.set(locationKey, state);
    this.persistState();
    void this.sender?.start(state, actor);
  }
  ejectDisc(locationKey, actor) {
    const state = this.players.get(locationKey);
    if (!state) {
      return void 0;
    }
    const audioId = state.audioId;
    if (state.autoEjectRunId !== null) {
      system.clearRun(state.autoEjectRunId);
      state.autoEjectRunId = null;
    }
    void this.sender?.stop(state, actor);
    this.players.delete(locationKey);
    this.persistState();
    return audioId;
  }
  hasDisc(locationKey) {
    return this.players.has(locationKey);
  }
  getAudioId(locationKey) {
    return this.players.get(locationKey)?.audioId;
  }
  isPlaying(locationKey) {
    return this.players.get(locationKey)?.isPlaying ?? false;
  }
  restore() {
    try {
      const raw = world.getDynamicProperty(STORAGE_KEY);
      if (typeof raw !== "string") {
        console.info("[BVC] No saved disc state found");
        return;
      }
      const entries = JSON.parse(raw);
      let restored = 0;
      for (const [key, disc] of Object.entries(entries)) {
        if (this.players.has(key)) {
          continue;
        }
        this.players.set(key, {
          audioId: disc.audioId,
          eventId: null,
          isPlaying: false,
          dimensionId: disc.dimensionId,
          coordinates: new Coordinates(
            disc.coordinates.x,
            disc.coordinates.y,
            disc.coordinates.z
          ),
          worldUuid: disc.worldUuid,
          autoEjectRunId: null
        });
        restored++;
        console.info(`[BVC] Restored disc: ${key} audioId=${disc.audioId}`);
      }
      console.info(`[BVC] Restored ${restored} disc(s)`);
    } catch (e) {
      console.error("[BVC] Failed to restore disc state:", e);
    }
  }
  onBlockDestroyed(locationKey) {
    const state = this.players.get(locationKey);
    if (!state) {
      return;
    }
    if (state.autoEjectRunId !== null) {
      system.clearRun(state.autoEjectRunId);
    }
    void this.sender?.stop(state, null);
    this.dropDisc(state);
    this.players.delete(locationKey);
    this.persistState();
  }
  forceEject(locationKey) {
    if (!this.players.has(locationKey)) {
      return false;
    }
    this.autoEject(locationKey);
    return true;
  }
  autoEject(locationKey) {
    const state = this.players.get(locationKey);
    if (!state) {
      return;
    }
    state.autoEjectRunId = null;
    const dimension = world.getDimension(state.dimensionId);
    const block = dimension.getBlock({
      x: state.coordinates.x,
      y: state.coordinates.y,
      z: state.coordinates.z
    });
    if (!block) {
      state.isPlaying = false;
      this.persistState();
      return;
    }
    state.isPlaying = false;
    state.eventId = null;
    this.setBlockPlaying(state, false);
    const handed = this.pushDiscToContainer(state) || this.dropDisc(state);
    if (!handed) {
      this.persistState();
      return;
    }
    this.players.delete(locationKey);
    this.persistState();
  }
  dropDisc(state) {
    try {
      const dimension = world.getDimension(state.dimensionId);
      const disc = new ItemStack("minecraft:music_disc_5", 1);
      disc.nameTag = `BVC: ${state.audioId}`;
      disc.setDynamicProperty("bvc:audio_id", state.audioId);
      disc.setDynamicProperty("bvc:is_bvc_disc", true);
      dimension.spawnItem(disc, {
        x: state.coordinates.x + 0.5,
        y: state.coordinates.y + 1,
        z: state.coordinates.z + 0.5
      });
      return true;
    } catch (e) {
      console.warn("[BVC] Failed to drop disc:", e);
      return false;
    }
  }
  setBlockPlaying(state, playing) {
    try {
      const dimension = world.getDimension(state.dimensionId);
      const block = dimension.getBlock({
        x: state.coordinates.x,
        y: state.coordinates.y,
        z: state.coordinates.z
      });
      if (!block) {
        return;
      }
      block.setPermutation(
        block.permutation.withState("bvc:playing", playing)
      );
    } catch (e) {
      console.error("[BVC] Failed to set block playing state:", e);
    }
  }
  pushDiscToContainer(state) {
    try {
      const dimension = world.getDimension(state.dimensionId);
      const disc = new ItemStack("minecraft:music_disc_5", 1);
      disc.nameTag = `BVC: ${state.audioId}`;
      disc.setDynamicProperty("bvc:audio_id", state.audioId);
      disc.setDynamicProperty("bvc:is_bvc_disc", true);
      const block = dimension.getBlock({
        x: state.coordinates.x,
        y: state.coordinates.y,
        z: state.coordinates.z
      });
      if (block) {
        const inv = block.getComponent("minecraft:inventory");
        if (inv?.container) {
          inv.container.setItem(0, disc);
          return true;
        }
      }
      const below = dimension.getBlock({
        x: state.coordinates.x,
        y: state.coordinates.y - 1,
        z: state.coordinates.z
      });
      if (below && below.typeId.includes("hopper")) {
        const hopperInv = below.getComponent("minecraft:inventory");
        if (hopperInv?.container) {
          hopperInv.container.addItem(disc);
          return true;
        }
      }
    } catch (e) {
      console.error("[BVC] Failed to push disc to container:", e);
    }
    return false;
  }
  persistState() {
    const entries = {};
    for (const [key, state] of this.players) {
      entries[key] = {
        audioId: state.audioId,
        dimensionId: state.dimensionId,
        coordinates: state.coordinates.toJSON(),
        worldUuid: state.worldUuid,
        isPlaying: state.isPlaying
      };
    }
    world.setDynamicProperty(STORAGE_KEY, JSON.stringify(entries));
  }
};

// src/audio/components.ts
import { system as system2, EquipmentSlot, ItemStack as ItemStack2 } from "@minecraft/server";
var INPUT_NEIGHBORS = [
  { x: 0, y: 1, z: 0, facing: 0 },
  { x: 1, y: 0, z: 0, facing: 4 },
  { x: -1, y: 0, z: 0, facing: 5 },
  { x: 0, y: 0, z: 1, facing: 2 },
  { x: 0, y: 0, z: -1, facing: 3 }
];
var AudioComponentRegistry = class {
  constructor(audioManager2, getWorldUuid2) {
    this.audioManager = audioManager2;
    this.getWorldUuid = getWorldUuid2;
  }
  register() {
    system2.beforeEvents.startup.subscribe((event) => {
      event.blockComponentRegistry.registerCustomComponent("bvc:audio_player", {
        onPlayerInteract: (e, _params) => {
          this.onPlayerInteract(e);
        },
        onPlayerBreak: (e, _params) => {
          this.onPlayerBreak(e);
        },
        onTick: (e, _params) => {
          this.onTick(e);
        }
      });
    });
    system2.run(() => {
      console.info("[BVC] Restoring disc state...");
      this.audioManager.restore();
    });
  }
  onPlayerInteract(e) {
    const block = e.block;
    const player = e.player;
    if (!player) {
      return;
    }
    const worldUuid = this.getWorldUuid();
    const coordinates = new Coordinates(block.x, block.y, block.z);
    const locationKey = this.audioManager.locationKey(worldUuid, coordinates);
    if (this.audioManager.hasDisc(locationKey)) {
      const audioId = this.audioManager.ejectDisc(locationKey, player);
      this.setBlockPlaying(block, false);
      if (audioId) {
        try {
          const disc = new ItemStack2("minecraft:music_disc_5", 1);
          disc.nameTag = `BVC: ${audioId}`;
          disc.setDynamicProperty("bvc:audio_id", audioId);
          disc.setDynamicProperty("bvc:is_bvc_disc", true);
          const inventory = player.getComponent("minecraft:inventory");
          if (inventory?.container) {
            inventory.container.addItem(disc);
          }
        } catch (err) {
          console.error("[BVC] Failed to return disc to player:", err);
        }
      }
    } else {
      const equippable = player.getComponent("minecraft:equippable");
      if (!equippable) {
        return;
      }
      const mainHand = equippable.getEquipment(EquipmentSlot.Mainhand);
      if (!mainHand) {
        return;
      }
      const isBvcDisc = mainHand.getDynamicProperty("bvc:is_bvc_disc");
      if (!isBvcDisc) {
        return;
      }
      const audioId = mainHand.getDynamicProperty("bvc:audio_id");
      if (!audioId) {
        return;
      }
      this.audioManager.insertDisc(
        locationKey,
        audioId,
        block.dimension.id,
        coordinates,
        worldUuid,
        player
      );
      this.setBlockPlaying(block, true);
      equippable.setEquipment(EquipmentSlot.Mainhand, void 0);
    }
  }
  onPlayerBreak(e) {
    const block = e.block;
    const worldUuid = this.getWorldUuid();
    const coordinates = new Coordinates(block.x, block.y, block.z);
    const locationKey = this.audioManager.locationKey(worldUuid, coordinates);
    if (this.audioManager.hasDisc(locationKey)) {
      this.audioManager.onBlockDestroyed(locationKey);
    }
  }
  onTick(e) {
    const block = e.block;
    const worldUuid = this.getWorldUuid();
    const coordinates = new Coordinates(block.x, block.y, block.z);
    const locationKey = this.audioManager.locationKey(worldUuid, coordinates);
    const hasDisc = this.audioManager.hasDisc(locationKey);
    const playingState = block.permutation.getState("bvc:playing");
    if (hasDisc) {
      if (!this.audioManager.isPlaying(locationKey) && playingState) {
        this.audioManager.forceEject(locationKey);
      }
      return;
    }
    if (playingState) {
      console.warn(
        `[BVC] onTick: stale bvc:playing=true at (${block.x},${block.y},${block.z}), forcing false`
      );
      this.setBlockPlaying(block, false);
    }
    const audioId = this.pullDiscFromAdjacentHopper(block);
    if (!audioId) {
      return;
    }
    console.info(
      `[BVC] onTick: detected disc audioId=${audioId} at (${block.x},${block.y},${block.z})`
    );
    this.audioManager.insertDisc(
      locationKey,
      audioId,
      block.dimension.id,
      coordinates,
      worldUuid,
      null
    );
    this.setBlockPlaying(block, true);
  }
  setBlockPlaying(block, playing) {
    try {
      block.setPermutation(
        block.permutation.withState("bvc:playing", playing)
      );
    } catch (e) {
      console.error("[BVC] Failed to set bvc:playing state:", e);
    }
  }
  pullDiscFromAdjacentHopper(block) {
    for (const n of INPUT_NEIGHBORS) {
      try {
        const neighbor = block.dimension.getBlock({
          x: block.x + n.x,
          y: block.y + n.y,
          z: block.z + n.z
        });
        if (!neighbor || !neighbor.typeId.includes("hopper")) {
          continue;
        }
        const facing = neighbor.permutation.getState("facing_direction");
        if (facing !== n.facing) {
          continue;
        }
        const inv = neighbor.getComponent("minecraft:inventory");
        if (!inv?.container) {
          continue;
        }
        for (let slot = 0; slot < inv.container.size; slot++) {
          const item = inv.container.getItem(slot);
          if (!item) {
            continue;
          }
          const isBvc = item.getDynamicProperty("bvc:is_bvc_disc");
          if (!isBvc) {
            continue;
          }
          const audioId = item.getDynamicProperty("bvc:audio_id");
          if (!audioId) {
            continue;
          }
          inv.container.setItem(slot, void 0);
          return audioId;
        }
      } catch {
      }
    }
    return null;
  }
};

// src/audio/eject_command.ts
import { system as system3, CommandPermissionLevel as CommandPermissionLevel3, CustomCommandParamType as CustomCommandParamType3, CustomCommandStatus as CustomCommandStatus3 } from "@minecraft/server";
var EjectCommand = class _EjectCommand {
  static register(audioManager2, getWorldUuid2) {
    system3.beforeEvents.startup.subscribe((event) => {
      event.customCommandRegistry.registerCommand(
        {
          name: "bvc:eject",
          description: "Force-eject a BVC audio disc from a jukebox at coordinates",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel3.GameDirectors,
          mandatoryParameters: [
            { name: "x", type: CustomCommandParamType3.Integer },
            { name: "y", type: CustomCommandParamType3.Integer },
            { name: "z", type: CustomCommandParamType3.Integer }
          ]
        },
        (_origin, x, y, z) => {
          const key = audioManager2.locationKey(
            getWorldUuid2(),
            new Coordinates(x, y, z)
          );
          system3.run(() => {
            audioManager2.forceEject(key);
          });
          return { status: CustomCommandStatus3.Success };
        }
      );
    });
  }
};

// src/state/bvcs_listener.ts
import { system as system4, world as world3 } from "@minecraft/server";

// src/state/bvcs_codec.ts
var BVCS_PREFIX = "!bvcs:";
var BvcsCodec = class _BvcsCodec {
  static isBvcs(message) {
    return message.startsWith(BVCS_PREFIX);
  }
  static decode(message) {
    if (!message.startsWith(BVCS_PREFIX)) {
      return null;
    }
    const rest = message.slice(BVCS_PREFIX.length);
    const first = rest.indexOf(":");
    if (first < 0) {
      return null;
    }
    const second = rest.indexOf(":", first + 1);
    if (second < 0) {
      return null;
    }
    if (!/^\d+$/.test(rest.slice(0, first))) {
      return null;
    }
    const kind = rest.slice(first + 1, second);
    const fields = /* @__PURE__ */ new Map();
    for (const kv of rest.slice(second + 1).split(";")) {
      const eq = kv.indexOf("=");
      if (eq > 0) {
        fields.set(kv.slice(0, eq), kv.slice(eq + 1));
      }
    }
    if (kind === "q") {
      const m = fields.get("m");
      const d = fields.get("d");
      const r = fields.get("r");
      const g = fields.get("g");
      if (m === void 0 || d === void 0 || r === void 0) {
        return null;
      }
      return {
        kind: "q",
        muted: m === "1",
        deafened: d === "1",
        recording: r === "1",
        group: g !== void 0 && g !== "-" ? g : null
      };
    }
    if (kind === "p") {
      const t = fields.get("t");
      const v = fields.get("v");
      const h = fields.get("h");
      if (!t || v === void 0 || h === void 0) {
        return null;
      }
      const percent = Number(v);
      if (!Number.isFinite(percent)) {
        return null;
      }
      return {
        kind: "p",
        target: t,
        volume: percent / 100,
        heard: h === "1"
      };
    }
    if (kind === "gl") {
      const c = fields.get("c");
      if (c === void 0 || !/^\d+$/.test(c)) {
        return null;
      }
      return { kind: "gl", count: Number(c) };
    }
    if (kind === "g") {
      const id = fields.get("i");
      const encoded = fields.get("n");
      if (id === void 0 || encoded === void 0) {
        return null;
      }
      const name = _BvcsCodec.unescape(encoded);
      return name === null ? null : { kind: "g", id, name, protected: fields.get("p") === "1" };
    }
    return null;
  }
  // Mirrors BvcsCodec::unescape in common. The grammar delimits on `;`, `=` and
  // `:`, which a renamed group may contain.
  static unescape(value) {
    let out = "";
    for (let i = 0; i < value.length; i++) {
      if (value[i] !== "%") {
        out += value[i];
        continue;
      }
      const hex = value.slice(i + 1, i + 3);
      if (!/^[0-9a-fA-F]{2}$/.test(hex)) {
        return null;
      }
      out += String.fromCharCode(parseInt(hex, 16));
      i += 2;
    }
    return out;
  }
};

// src/state/bvcs_listener.ts
var BvcsListener = class {
  constructor(cacheFor, groups) {
    this.cacheFor = cacheFor;
    this.groups = groups;
  }
  buildHandler() {
    return (ev) => {
      if (!BvcsCodec.isBvcs(ev.message)) {
        return;
      }
      ev.cancel = true;
      const msg = BvcsCodec.decode(ev.message);
      if (!msg) {
        return;
      }
      const sender = ev.sender.name;
      system4.run(() => {
        if (msg.kind === "gl") {
          this.groups.beginList(msg.count);
          return;
        }
        if (msg.kind === "g") {
          this.groups.add({
            id: msg.id,
            name: msg.name,
            protected: msg.protected
          });
          return;
        }
        const cache = this.cacheFor(sender);
        if (msg.kind === "q") {
          cache.applyQueryState({
            id: sender,
            muted: msg.muted,
            deafened: msg.deafened,
            recording: msg.recording,
            current_group: msg.group
          });
        } else {
          cache.applyPreferences([
            {
              owner: sender,
              target: msg.target,
              volume: msg.volume,
              muted: !msg.heard
            }
          ]);
        }
      });
    };
  }
  register() {
    world3.beforeEvents.chatSend.subscribe(this.buildHandler());
  }
};

// src/state/state_cache.ts
import { ObservableBoolean, ObservableString } from "@minecraft/server-ui";
var PENDING_TTL_MS = 3e3;
var StateCache = class {
  // Resolves a share code to a group name for the status line. Injected because
  // the group list is server-wide and this cache is per player.
  constructor(groupNameFor = () => null) {
    this.groupNameFor = groupNameFor;
    this.status = new ObservableString("Syncing\u2026");
    this.muted = new ObservableBoolean(false, { clientWritable: true });
    this.deafened = new ObservableBoolean(false, {
      clientWritable: true
    });
    this.recording = new ObservableBoolean(false);
    // True until the first snapshot lands — the panel binds its controls'
    // `disabled` to this so an unconfirmed on/off is never actionable.
    this.controlsLocked = new ObservableBoolean(true);
    // True while the player has no group — the panel disables Leave on it.
    this.noGroup = new ObservableBoolean(true);
    this.syncing = false;
    this.hasSnapshot = false;
    this.currentGroup = null;
    this.pendingChanges = /* @__PURE__ */ new Map();
    // Per-target preference shadows, in PlayerPreference units (volume 0..1 gain,
    // heard = !muted) — the volumes view's sliders race the preference poll the
    // same way the panel toggles race the state poll.
    this.pendingVolumes = /* @__PURE__ */ new Map();
    this.pendingHeard = /* @__PURE__ */ new Map();
    this.preferences = /* @__PURE__ */ new Map();
    this.prefListeners = /* @__PURE__ */ new Set();
  }
  // While true, an Observable change came from a server snapshot, not the player —
  // the panel's subscribe handlers must NOT echo it back as an action (no feedback
  // loop). `setData` notifies subscribers synchronously, so this brackets safely.
  get isSyncing() {
    return this.syncing;
  }
  get synced() {
    return this.hasSnapshot;
  }
  get group() {
    return this.currentGroup;
  }
  // Records a player-initiated change and applies it optimistically. Snapshots
  // that contradict it (an in-flight poll, a pre-echo cache read) are held off
  // until the TTL lapses — snapshots carry no ordering, so a matching value
  // cannot be distinguished from a coincidence and never lifts the shadow early.
  markPending(field, value) {
    this.pendingChanges.set(field, {
      value,
      expiresAt: Date.now() + PENDING_TTL_MS
    });
    const observable = this.observableFor(field);
    if (observable.getData() !== value) {
      observable.setData(value);
    }
  }
  applyQueryState(qs) {
    this.syncing = true;
    this.hasSnapshot = true;
    this.applySelfState("muted", this.muted, qs.muted);
    this.applySelfState("deafened", this.deafened, qs.deafened);
    this.applySelfState("recording", this.recording, qs.recording);
    this.currentGroup = qs.current_group;
    this.noGroup.setData(qs.current_group === null);
    this.status.setData(this.groupStatusLine());
    this.controlsLocked.setData(false);
    this.syncing = false;
  }
  applySelfState(field, observable, snapshotValue) {
    const pending = this.pendingChanges.get(field);
    if (pending) {
      if (Date.now() < pending.expiresAt) {
        if (snapshotValue !== pending.value) {
          return;
        }
      } else {
        this.pendingChanges.delete(field);
      }
    }
    observable.setData(snapshotValue);
  }
  observableFor(field) {
    switch (field) {
      case "muted":
        return this.muted;
      case "deafened":
        return this.deafened;
      case "recording":
        return this.recording;
    }
  }
  applyPreferences(prefs) {
    if (prefs.length === 0) {
      return;
    }
    const shadowed = prefs.map((p) => this.shadowedPreference(p));
    for (const p of shadowed) {
      this.preferences.set(p.target, p);
    }
    this.syncing = true;
    for (const listener of this.prefListeners) {
      listener(shadowed);
    }
    this.syncing = false;
  }
  // Records a player-initiated volume change (0..1 gain) and applies it to the
  // stored preference optimistically, so a reopened volumes view seeds from the
  // player's choice rather than a not-yet-confirmed server value.
  markVolumePending(target, volume) {
    this.pendingVolumes.set(target, {
      value: volume,
      expiresAt: Date.now() + PENDING_TTL_MS
    });
    this.upsertPreference(target, { volume });
  }
  markHeardPending(target, heard) {
    this.pendingHeard.set(target, {
      value: heard,
      expiresAt: Date.now() + PENDING_TTL_MS
    });
    this.upsertPreference(target, { muted: !heard });
  }
  // The volumes view's commit baseline must not be rewritten by a feed apply
  // that merely reflected our own shadow — that would suppress the real send.
  hasPendingVolume(target) {
    const pending = this.pendingVolumes.get(target);
    return pending !== void 0 && Date.now() < pending.expiresAt;
  }
  upsertPreference(target, change) {
    const existing = this.preferences.get(target);
    this.preferences.set(target, {
      owner: existing?.owner ?? "",
      target,
      volume: change.volume ?? existing?.volume ?? 1,
      muted: change.muted ?? existing?.muted ?? false
    });
  }
  // While a target's shadow is unexpired, its pending fields override whatever
  // the snapshot claims (a contradiction is a stale poll or a failure the
  // expiry will surface); expired shadows are dropped.
  shadowedPreference(pref) {
    const now = Date.now();
    let out = pref;
    const volume = this.pendingVolumes.get(pref.target);
    if (volume) {
      if (now < volume.expiresAt) {
        out = { ...out, volume: volume.value };
      } else {
        this.pendingVolumes.delete(pref.target);
      }
    }
    const heard = this.pendingHeard.get(pref.target);
    if (heard) {
      if (now < heard.expiresAt) {
        out = { ...out, muted: !heard.value };
      } else {
        this.pendingHeard.delete(pref.target);
      }
    }
    return out;
  }
  // Notifies an open volumes view when fresh preferences arrive from a feed.
  // Returns the unsubscribe function.
  onPreferences(listener) {
    this.prefListeners.add(listener);
    return () => this.prefListeners.delete(listener);
  }
  preferenceFor(target) {
    return this.preferences.get(target);
  }
  adjustedTargets() {
    return [...this.preferences.keys()];
  }
  // A player reads the group's NAME here. `current_group` is the share code, which
  // is what the code field is for; falling back to it only covers the window before
  // the group list has arrived.
  groupStatusLine() {
    if (!this.currentGroup) {
      return "No group";
    }
    const name = this.groupNameFor(this.currentGroup);
    return `Group: ${name ?? this.currentGroup}`;
  }
  // Re-renders the status line against a group list that has since arrived.
  refreshStatus() {
    if (this.hasSnapshot) {
      this.status.setData(this.groupStatusLine());
    }
  }
};

// src/state/cache_store.ts
var StateCacheStore = class {
  // The group list is server-wide, so it lives here rather than in each cache.
  // A list arriving after a snapshot re-renders every open status line, which is
  // what turns `Group: AB12-CD34` into `Group: Sculk Striders`.
  constructor(groups) {
    this.groups = groups;
    this.caches = /* @__PURE__ */ new Map();
    groups.onChange(() => {
      for (const cache of this.caches.values()) {
        cache.refreshStatus();
      }
    });
  }
  for(playerName) {
    let cache = this.caches.get(playerName);
    if (!cache) {
      cache = new StateCache((id) => this.groups.nameFor(id));
      this.caches.set(playerName, cache);
    }
    return cache;
  }
  // A departed player's cache is stale (and any chatter can mint their own
  // entry via !bvcs:); evict on leave so the map tracks the live roster.
  evict(playerName) {
    this.caches.delete(playerName);
  }
};

// src/state/group_cache.ts
var GroupCache = class {
  constructor() {
    this.pending = [];
    this.settled = [];
    this.expected = 0;
    this.headerSeen = false;
    this.listeners = /* @__PURE__ */ new Set();
  }
  beginList(count) {
    this.pending = [];
    this.expected = count;
    this.headerSeen = true;
    if (count === 0) {
      this.settle();
    }
  }
  add(row) {
    this.pending.push(row);
    if (this.pending.length >= this.expected) {
      this.settle();
    }
  }
  // Fewer rows arrived than the header promised. The rows that did arrive are more
  // useful than none, so they settle as they stand.
  settlePartial() {
    if (this.headerSeen) {
      this.settle();
    }
  }
  rows() {
    return [...this.settled];
  }
  // The name behind a share code, for the panel's status line. Null while the
  // list has not arrived, which the caller renders as the code itself.
  nameFor(id) {
    return this.settled.find((row) => row.id === id)?.name ?? null;
  }
  isProtected(id) {
    return this.settled.find((row) => row.id === id)?.protected === true;
  }
  // Whether any header has been seen. False means the proxy never answered, which
  // the page reports as unavailable rather than as an empty server.
  answered() {
    return this.headerSeen;
  }
  onChange(listener) {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }
  settle() {
    this.settled = [...this.pending];
    for (const listener of this.listeners) {
      listener();
    }
  }
};

// src/state/group_source.ts
import { system as system5 } from "@minecraft/server";

// src/net/http_client.ts
var HttpClient = class {
  constructor() {
    this.loadPromise = null;
    this.loaded = false;
    this.module = null;
    this.warned = false;
  }
  isAvailable() {
    return this.loaded && this.module !== null;
  }
  async ensureLoaded() {
    if (this.loaded) {
      return this.module !== null;
    }
    if (!this.loadPromise) {
      this.loadPromise = import("@minecraft/server-net").then((m) => {
        this.module = m;
      }).catch((e) => {
        this.module = null;
        if (!this.warned) {
          console.warn(
            "[BVC] @minecraft/server-net not available; HTTP features disabled: " + e
          );
          this.warned = true;
        }
      }).then(() => {
        this.loaded = true;
      });
    }
    await this.loadPromise;
    return this.module !== null;
  }
  async request(url, method, body, headers, timeoutSec) {
    const ok = await this.ensureLoaded();
    if (!ok || !this.module) {
      return null;
    }
    try {
      const { HttpRequest, HttpHeader, HttpRequestMethod, http } = this.module;
      const req = new HttpRequest(url);
      if (body !== void 0) {
        req.setBody(body);
      }
      const methodEnum = HttpRequestMethod[method];
      req.setMethod(methodEnum);
      req.setHeaders(headers.map(([n, v]) => new HttpHeader(n, v)));
      req.setTimeout(timeoutSec);
      const res = await http.request(req);
      return { status: res.status, body: res.body };
    } catch (e) {
      console.error("[BVC] HTTP request failed:", e);
      return null;
    }
  }
};
var httpClient = new HttpClient();

// src/state/group_row_parser.ts
var GroupRowParser = class {
  // `Channel` carries players and creator as well; the panel reads neither.
  static parse(body) {
    try {
      const parsed = JSON.parse(body);
      if (!Array.isArray(parsed)) {
        return null;
      }
      const rows = [];
      for (const item of parsed) {
        if (item === null || typeof item !== "object") {
          continue;
        }
        const c = item;
        if (typeof c.id === "string" && typeof c.name === "string") {
          rows.push({
            id: c.id,
            name: c.name,
            protected: c.protected === true
          });
        }
      }
      return rows;
    } catch {
      return null;
    }
  }
};

// src/state/group_source.ts
var REQUEST_TIMEOUT_SEC = 2;
var NO_NET_WAIT_TICKS = 20;
var NetGroupSource = class {
  constructor(config, cache) {
    this.config = config;
    this.cache = cache;
  }
  // Net mode could answer the page without the cache, but the panel's status line
  // resolves a share code to a name through it, so the fetch fills it either way.
  warm(player) {
    void this.list(player);
  }
  async list(_player) {
    const response = await httpClient.request(
      `${this.config.bvcServer}/api/channel`,
      "Get",
      void 0,
      [
        ["Authorization", `Bearer ${this.config.accessToken}`],
        ["Accept", "application/json"]
      ],
      REQUEST_TIMEOUT_SEC
    );
    if (!response || response.status < 200 || response.status >= 300) {
      return null;
    }
    const rows = GroupRowParser.parse(response.body);
    if (rows !== null) {
      this.cache.beginList(rows.length);
      for (const row of rows) {
        this.cache.add(row);
      }
    }
    return rows;
  }
};
var NoNetGroupSource = class {
  constructor(sender, cache) {
    this.sender = sender;
    this.cache = cache;
  }
  warm(player) {
    void this.sender()?.requestGroups(player);
  }
  async list(player) {
    const sender = this.sender();
    if (!sender) {
      return null;
    }
    await sender.requestGroups(player);
    const settled = await this.settled();
    if (!settled) {
      this.cache.settlePartial();
    }
    return this.cache.answered() ? this.cache.rows() : null;
  }
  // Resolves true on the first complete list after the request, false on the cap.
  settled() {
    return new Promise((resolve) => {
      let done = false;
      const finish = (complete) => {
        if (done) {
          return;
        }
        done = true;
        off();
        resolve(complete);
      };
      const off = this.cache.onChange(() => {
        finish(true);
      });
      system5.runTimeout(() => {
        finish(false);
      }, NO_NET_WAIT_TICKS);
    });
  }
};

// src/state/state_source.ts
import { system as system6 } from "@minecraft/server";
var NET_POLL_TICKS = 20;
var REQUEST_TIMEOUT_SEC2 = 1;
var NetStateSource = class {
  constructor(config) {
    this.config = config;
    this.polls = /* @__PURE__ */ new Map();
  }
  start(player, cache, targets) {
    this.stop(player.name);
    const name = player.name;
    const handle = system6.runInterval(() => {
      void this.poll(name, cache, targets());
    }, NET_POLL_TICKS);
    this.polls.set(name, handle);
    void this.poll(name, cache, targets());
  }
  stop(playerName) {
    const handle = this.polls.get(playerName);
    if (handle !== void 0) {
      system6.clearRun(handle);
      this.polls.delete(playerName);
    }
  }
  async poll(name, cache, targets) {
    const headers = [
      ["Authorization", `Bearer ${this.config.accessToken}`],
      ["Accept", "application/json"]
    ];
    const stateResponse = await httpClient.request(
      `${this.config.bvcServer}/api/state?id=${encodeURIComponent(name)}&game=minecraft`,
      "Get",
      void 0,
      headers,
      REQUEST_TIMEOUT_SEC2
    );
    if (stateResponse && stateResponse.status >= 200 && stateResponse.status < 300) {
      const state = this.parseState(stateResponse.body);
      if (state) {
        cache.applyQueryState(state);
      }
    }
    if (targets.length === 0) {
      return;
    }
    const prefsResponse = await httpClient.request(
      `${this.config.bvcServer}/api/preferences?owner=${encodeURIComponent(name)}&game=minecraft&targets=${encodeURIComponent(targets.join(","))}`,
      "Get",
      void 0,
      headers,
      REQUEST_TIMEOUT_SEC2
    );
    if (prefsResponse && prefsResponse.status >= 200 && prefsResponse.status < 300) {
      cache.applyPreferences(this.parsePreferences(prefsResponse.body));
    }
  }
  tracksCurrentGroup() {
    return true;
  }
  parseState(body) {
    try {
      const parsed = JSON.parse(body);
      if (parsed === null || typeof parsed !== "object") {
        return null;
      }
      const s = parsed;
      if (typeof s.id !== "string" || typeof s.muted !== "boolean") {
        return null;
      }
      return {
        id: s.id,
        muted: s.muted === true,
        deafened: s.deafened === true,
        recording: s.recording === true,
        current_group: typeof s.current_group === "string" ? s.current_group : null
      };
    } catch {
      return null;
    }
  }
  parsePreferences(body) {
    try {
      const parsed = JSON.parse(body);
      if (!Array.isArray(parsed)) {
        return [];
      }
      const prefs = [];
      for (const item of parsed) {
        if (item === null || typeof item !== "object") {
          continue;
        }
        const p = item;
        if (typeof p.owner === "string" && typeof p.target === "string" && typeof p.volume === "number" && typeof p.muted === "boolean") {
          prefs.push({
            owner: p.owner,
            target: p.target,
            volume: p.volume,
            muted: p.muted
          });
        }
      }
      return prefs;
    } catch {
      return [];
    }
  }
};
var NoNetStateSource = class {
  constructor(sender) {
    this.sender = sender;
  }
  start(player, _cache, targets) {
    const sender = this.sender();
    if (sender) {
      void sender.requestSync(targets(), player);
    }
  }
  stop(_playerName) {
  }
  tracksCurrentGroup() {
    return false;
  }
};

// src/ui/panel.ts
import { system as system9 } from "@minecraft/server";
import {
  CustomForm as CustomForm3,
  ObservableString as ObservableString5
} from "@minecraft/server-ui";
import { DataDrivenScreenClosedReason as DataDrivenScreenClosedReason4 } from "@minecraft/server-ui";

// src/ui/form_show.ts
import { system as system7 } from "@minecraft/server";
import { DataDrivenScreenClosedReason } from "@minecraft/server-ui";
var USER_BUSY_RETRIES = 5;
var USER_BUSY_RETRY_TICKS = 10;
var FormShow = class _FormShow {
  static async withRetry(form) {
    let reason = await _FormShow.showOrClosed(form);
    for (let attempt = 0; reason === DataDrivenScreenClosedReason.UserBusy && attempt < USER_BUSY_RETRIES; attempt++) {
      await _FormShow.wait(USER_BUSY_RETRY_TICKS);
      reason = await _FormShow.showOrClosed(form);
    }
    return reason;
  }
  // show() REJECTS (PlayerLeftError / ServerShutdownError) when the viewer
  // disconnects with the form open. That is a normal way for a session to end
  // — fold it into ClientClosed so callers run their teardown instead of
  // leaking an unhandled rejection (and their subscriptions with it).
  static async showOrClosed(form) {
    try {
      return await form.show();
    } catch {
      return DataDrivenScreenClosedReason.ClientClosed;
    }
  }
  static wait(ticks) {
    return new Promise((resolve) => system7.runTimeout(resolve, ticks));
  }
};

// src/ui/group_list.ts
import { CustomForm, ObservableString as ObservableString3 } from "@minecraft/server-ui";
import { DataDrivenScreenClosedReason as DataDrivenScreenClosedReason2 } from "@minecraft/server-ui";

// src/control/join_reply.ts
var JoinReply = class {
  static forResult(result, name, isProtected) {
    if (result.outcome === "no_session") {
      return `\xA7c[BVC] Open the BVC app to join ${name}.`;
    }
    if (result.outcome === "forwarded" || isProtected && result.ok) {
      return `\xA7e[BVC] Your code for ${name} is in the BVC app.`;
    }
    if (!result.ok) {
      return "\xA7c[BVC] Group join failed; try again";
    }
    return `\xA7a[BVC] Joined \xA7f${name}`;
  }
};

// src/ui/searchable_rows.ts
import { ObservableBoolean as ObservableBoolean2, ObservableString as ObservableString2 } from "@minecraft/server-ui";
var SearchableRows = class {
  /// Adds the field and the rows to `form`. Returns the unsubscribes the caller
  /// must run when the form closes.
  static attach(form, rows, options) {
    const unsubscribes = [];
    const search = new ObservableString2("", { clientWritable: true });
    form.textField(options.placeholder, search, {
      description: options.description
    });
    form.divider();
    const visibility = /* @__PURE__ */ new Map();
    for (const row of rows) {
      const visible = new ObservableBoolean2(true);
      visibility.set(row.key, visible);
      form.button(row.label, row.onSelect, { visible });
    }
    const listener = search.subscribe((query) => {
      const needle = query.trim().toLowerCase();
      for (const [key, visible] of visibility) {
        visible.setData(key.toLowerCase().includes(needle));
      }
    });
    unsubscribes.push(() => {
      search.unsubscribe(listener);
    });
    return unsubscribes;
  }
};

// src/ui/group_list.ts
var GroupListView = class {
  constructor(sender, source, cache, panelTest, leaveDisabled) {
    this.sender = sender;
    this.source = source;
    this.cache = cache;
    this.panelTest = panelTest;
    this.leaveDisabled = leaveDisabled;
  }
  /// Shows the page until it closes. Resolves true when the panel should reopen.
  async show(player) {
    for (; ; ) {
      const exit = await this.page(player);
      if (exit !== "refresh") {
        return exit === "panel";
      }
    }
  }
  async page(player) {
    const rows = await this.rows(player);
    if (!player.isValid) {
      return null;
    }
    let exit = "panel";
    const unsubscribes = [];
    const form = new CustomForm(player, "Groups");
    const current = this.cache.group;
    if (rows === null) {
      form.label("Group list unavailable \u2014 press Refresh");
      form.divider();
    } else if (rows.length === 0) {
      form.label("No groups yet \u2014 press Create group");
      form.divider();
    } else {
      const searchable = rows.map((row) => ({
        key: row.name,
        // The current group is marked and inert: joining it again is a no-op the
        // server already absorbs, and a row that does nothing should say why.
        // A button renders § codes literally, so the marker is plain text.
        // `[Ask]` marks a protected group: a member admits the player in the BVC app.
        label: new ObservableString3(
          row.id === current ? `* ${row.name} (current)` : row.protected ? `[Ask] ${row.name}` : row.name
        ),
        onSelect: () => {
          if (row.id === current) {
            return;
          }
          void this.join(player, row);
          form.close();
        }
      }));
      unsubscribes.push(
        ...SearchableRows.attach(form, searchable, {
          placeholder: "Search",
          description: "Type to filter groups"
        })
      );
      form.divider();
    }
    const joinCode = new ObservableString3(current ?? "", {
      clientWritable: true
    });
    form.button("Create group", () => {
      void this.create(player, joinCode);
      form.close();
    }).textField("Group code", joinCode, {
      description: current ? "Your current group code \u2014 copy it to share, or paste another and press Join by code" : "Paste a share code, then press Join by code"
    }).button("Join by code", () => {
      const code = joinCode.getData().trim();
      if (code.length > 0) {
        const row = rows?.find((r) => r.id === code) ?? {
          id: code,
          name: code
        };
        void this.join(player, row);
      }
      form.close();
    }).button(
      "Leave group",
      () => {
        void this.sender.send({ kind: "group-leave" }, player);
        form.close();
      },
      { disabled: this.leaveDisabled }
    ).divider().button("Refresh", () => {
      exit = "refresh";
      form.close();
    }).button("\u2190 Back", () => {
      exit = "panel";
      form.close();
    }).closeButton();
    const reason = await FormShow.withRetry(form);
    for (const unsubscribe of unsubscribes) {
      unsubscribe();
    }
    return reason === DataDrivenScreenClosedReason2.ServerClosed ? exit : null;
  }
  async rows(player) {
    const rows = await this.source.list(player);
    if (this.panelTest.count === 0) {
      return rows;
    }
    const synthetic = [];
    for (let i = 0; i < this.panelTest.count; i++) {
      synthetic.push({ id: `paneltest-${i}`, name: `Test Group ${i + 1}` });
    }
    return [...rows ?? [], ...synthetic];
  }
  async join(player, row) {
    const result = await this.sender.send(
      { kind: "group-join", channel: row.id },
      player
    );
    player.sendMessage(
      JoinReply.forResult(result, row.name, row.protected === true)
    );
  }
  async create(player, joinCode) {
    const result = await this.sender.send({ kind: "group-create" }, player);
    if (!result.ok) {
      player.sendMessage("\xA7c[BVC] Group create failed; try again");
      return;
    }
    if (result.groupCode) {
      joinCode.setData(result.groupCode);
      player.sendMessage(
        `\xA7a[BVC] Group created \u2014 share code: \xA7f${result.groupCode}`
      );
    }
  }
};

// src/ui/player_list.ts
import { system as system8, world as world4 } from "@minecraft/server";
import {
  CustomForm as CustomForm2,
  ObservableBoolean as ObservableBoolean3,
  ObservableNumber,
  ObservableString as ObservableString4
} from "@minecraft/server-ui";
import { DataDrivenScreenClosedReason as DataDrivenScreenClosedReason3 } from "@minecraft/server-ui";

// src/control/jukebox.ts
var JUKEBOX_TARGET = "#jukebox";
var MAX_LEVEL = 150;

// src/ui/player_list.ts
var NEARBY_RADIUS_BLOCKS = 64;
var SLIDER_COMMIT_TICKS = 10;
var QUICK_ROWS = 5;
var JUKEBOX_LABEL = "Jukebox music";
var MUTED_VALUE = -1;
var FINE_TUNE_VALUE = -2;
var PRESET_ITEMS = [
  { label: "Muted", value: MUTED_VALUE },
  { label: "25%", value: 25 },
  { label: "50%", value: 50 },
  { label: "75%", value: 75 },
  { label: "100%", value: 100 },
  { label: "Fine-tune\u2026", value: FINE_TUNE_VALUE }
];
var FAR_AWAY = Number.POSITIVE_INFINITY;
var PlayerVolumesView = class {
  constructor(sender, cache, setTargets, panelTest) {
    this.sender = sender;
    this.cache = cache;
    this.setTargets = setTargets;
    this.panelTest = panelTest;
  }
  // Runs the page loop until the player exits the volumes surface entirely.
  async show(owner) {
    let page = { kind: "main" };
    while (page.kind !== "exit") {
      switch (page.kind) {
        case "main":
          page = await this.showMain(owner);
          break;
        case "list":
          page = await this.showList(owner);
          break;
        case "detail":
          page = await this.showDetail(owner, page.target, page.back);
          break;
      }
    }
  }
  // Proximity-scoped list: players within NEARBY_RADIUS_BLOCKS (same
  // dimension, excluding the owner) plus everyone the owner has already
  // adjusted, nearest first; synthetic test rows are appended when armed.
  targets(owner) {
    const found = /* @__PURE__ */ new Map();
    for (const other of world4.getAllPlayers()) {
      if (other.name === owner.name) {
        continue;
      }
      if (other.dimension.id !== owner.dimension.id) {
        continue;
      }
      const dx = other.location.x - owner.location.x;
      const dy = other.location.y - owner.location.y;
      const dz = other.location.z - owner.location.z;
      const distanceSq = dx * dx + dy * dy + dz * dz;
      if (distanceSq <= NEARBY_RADIUS_BLOCKS * NEARBY_RADIUS_BLOCKS) {
        found.set(other.name, distanceSq);
      }
    }
    for (const adjusted of this.cache.adjustedTargets()) {
      if (adjusted === JUKEBOX_TARGET) {
        continue;
      }
      if (!found.has(adjusted)) {
        found.set(adjusted, FAR_AWAY);
      }
    }
    for (let i = 1; i <= this.panelTest.count; i++) {
      const name = `Test${String(i).padStart(2, "0")}`;
      if (!found.has(name)) {
        found.set(name, (i * 8) ** 2);
      }
    }
    return [...found.entries()].map(([target, distanceSq]) => ({ target, distanceSq })).sort(
      (a, b) => a.distanceSq - b.distanceSq || a.target.localeCompare(b.target)
    );
  }
  // What the state feed is asked to cover while a page is open: the players on it plus the
  // jukebox, whose row is pinned rather than listed. Omitting it leaves the pinned row unseeded —
  // the net poll scopes /api/preferences to this list and the no-net sync request carries it.
  reportedTargets(targets) {
    return [JUKEBOX_TARGET, ...targets.map((t) => t.target)];
  }
  async showMain(owner) {
    const targets = this.targets(owner);
    this.setTargets(this.reportedTargets(targets));
    const quick = targets.slice(0, QUICK_ROWS);
    let next = { kind: "exit" };
    const unsubscribes = [];
    const form = new CustomForm2(owner, "Player volumes");
    form.label(JUKEBOX_LABEL);
    this.addPlayerRow(
      form,
      owner,
      JUKEBOX_TARGET,
      unsubscribes,
      void 0,
      () => {
        next = { kind: "detail", target: JUKEBOX_TARGET, back: "main" };
        form.close();
      }
    );
    form.divider();
    if (targets.length === 0) {
      form.label("No players nearby");
    } else {
      form.label("Nearest");
    }
    for (const t of quick) {
      this.addPlayerRow(form, owner, t.target, unsubscribes, void 0, () => {
        next = { kind: "detail", target: t.target, back: "main" };
        form.close();
      });
    }
    if (targets.length > 0) {
      form.divider().button(`All players (${targets.length})\u2026`, () => {
        next = { kind: "list" };
        form.close();
      });
    }
    form.closeButton();
    const reason = await FormShow.withRetry(form);
    for (const unsubscribe of unsubscribes) {
      unsubscribe();
    }
    return reason === DataDrivenScreenClosedReason3.ServerClosed ? next : { kind: "exit" };
  }
  async showList(owner) {
    const targets = this.targets(owner);
    this.setTargets(this.reportedTargets(targets));
    let next = { kind: "exit" };
    const unsubscribes = [];
    const form = new CustomForm2(owner, "All players");
    const jukeboxLabel = new ObservableString4(
      this.stateLabel(JUKEBOX_TARGET, false)
    );
    form.button(jukeboxLabel, () => {
      next = { kind: "detail", target: JUKEBOX_TARGET, back: "list" };
      form.close();
    });
    unsubscribes.push(
      this.cache.onPreferences((prefs) => {
        if (prefs.some((p) => p.target === JUKEBOX_TARGET)) {
          jukeboxLabel.setData(this.stateLabel(JUKEBOX_TARGET, false));
        }
      })
    );
    form.divider();
    if (targets.length === 0) {
      form.label("No players nearby");
    } else {
      const rows = targets.map((t) => {
        const label = new ObservableString4(this.stateLabel(t.target, false));
        unsubscribes.push(
          this.cache.onPreferences((prefs) => {
            if (prefs.some((p) => p.target === t.target)) {
              label.setData(this.stateLabel(t.target, false));
            }
          })
        );
        return {
          key: t.target,
          label,
          onSelect: () => {
            next = { kind: "detail", target: t.target, back: "list" };
            form.close();
          }
        };
      });
      unsubscribes.push(
        ...SearchableRows.attach(form, rows, {
          placeholder: "Search",
          description: "Type to filter players"
        })
      );
    }
    form.divider().button("\u2190 Back", () => {
      next = { kind: "main" };
      form.close();
    }).closeButton();
    const reason = await FormShow.withRetry(form);
    for (const unsubscribe of unsubscribes) {
      unsubscribe();
    }
    return reason === DataDrivenScreenClosedReason3.ServerClosed ? next : { kind: "exit" };
  }
  async showDetail(owner, target, back) {
    this.setTargets([target]);
    const pref = this.cache.preferenceFor(target);
    const status = new ObservableString4(this.stateLabel(target, true));
    const heard = new ObservableBoolean3(!(pref?.muted ?? false), {
      clientWritable: true
    });
    const volume = new ObservableNumber(Math.round((pref?.volume ?? 1) * 100), {
      clientWritable: true
    });
    let next = { kind: "exit" };
    const unsubscribes = [];
    let lastHeard = heard.getData();
    const heardListener = heard.subscribe((on) => {
      if (on === lastHeard) {
        return;
      }
      lastHeard = on;
      if (!this.cache.isSyncing) {
        this.cache.markHeardPending(target, on);
        void this.sender.send({ kind: "hear", target, on }, owner);
        status.setData(this.stateLabel(target, true));
      }
    });
    unsubscribes.push(() => heard.unsubscribe(heardListener));
    let lastCommittedVolume = volume.getData();
    let pendingCommit;
    const volumeListener = volume.subscribe((value) => {
      if (this.cache.isSyncing) {
        if (!this.cache.hasPendingVolume(target)) {
          lastCommittedVolume = value;
        }
        return;
      }
      const clamped = Math.max(0, Math.min(MAX_LEVEL, Math.round(value)));
      this.cache.markVolumePending(target, clamped / 100);
      if (pendingCommit !== void 0) {
        system8.clearRun(pendingCommit);
      }
      pendingCommit = system8.runTimeout(() => {
        pendingCommit = void 0;
        if (value === lastCommittedVolume) {
          return;
        }
        lastCommittedVolume = value;
        void this.sender.send(
          { kind: "volume", target, value: clamped },
          owner
        );
        status.setData(this.stateLabel(target, true));
      }, SLIDER_COMMIT_TICKS);
    });
    unsubscribes.push(() => volume.unsubscribe(volumeListener));
    unsubscribes.push(
      this.cache.onPreferences((prefs) => {
        const p = prefs.find((x) => x.target === target);
        if (!p) {
          return;
        }
        heard.setData(!p.muted);
        volume.setData(Math.round(p.volume * 100));
        status.setData(this.stateLabel(target, true));
      })
    );
    const form = new CustomForm2(owner, this.displayName(target)).label(status).divider().toggle("Hear", heard).slider("Volume", volume, 0, MAX_LEVEL, { step: 5 }).divider().button("\u2190 Back to players", () => {
      next = { kind: back };
      form.close();
    }).closeButton();
    const reason = await FormShow.withRetry(form);
    for (const unsubscribe of unsubscribes) {
      unsubscribe();
    }
    return reason === DataDrivenScreenClosedReason3.ServerClosed ? next : { kind: "exit" };
  }
  // One quick row per player: the dropdown label is JUST the name — the
  // control box itself displays the current preset ("100%"/"Muted"), so
  // repeating state in the label would render it twice. Its "Fine-tune…" item
  // navigates to the detail page via `onFineTune`.
  addPlayerRow(form, owner, target, unsubscribes, visible, onFineTune) {
    const value = new ObservableNumber(
      this.presetFromPref(this.cache.preferenceFor(target)),
      { clientWritable: true }
    );
    form.dropdown(
      this.displayName(target),
      value,
      PRESET_ITEMS,
      visible ? { visible } : {}
    );
    let lastSeen = value.getData();
    const listener = value.subscribe((v) => {
      if (v === lastSeen) {
        return;
      }
      lastSeen = v;
      if (this.cache.isSyncing) {
        return;
      }
      if (v === FINE_TUNE_VALUE) {
        onFineTune();
        return;
      }
      this.applyPreset(owner, target, v);
    });
    unsubscribes.push(() => value.unsubscribe(listener));
    unsubscribes.push(
      this.cache.onPreferences((prefs) => {
        const p = prefs.find((x) => x.target === target);
        if (!p) {
          return;
        }
        const preset = this.presetFromPref(p);
        if (preset !== value.getData()) {
          value.setData(preset);
        }
      })
    );
  }
  applyPreset(owner, target, preset) {
    const wasMuted = this.cache.preferenceFor(target)?.muted ?? false;
    if (preset === MUTED_VALUE) {
      this.cache.markHeardPending(target, false);
      void this.sender.send({ kind: "hear", target, on: false }, owner);
      return;
    }
    if (wasMuted) {
      this.cache.markHeardPending(target, true);
      void this.sender.send({ kind: "hear", target, on: true }, owner);
    }
    this.cache.markVolumePending(target, preset / 100);
    void this.sender.send({ kind: "volume", target, value: preset }, owner);
  }
  // Player rows only offer presets, so the current volume snaps to the nearest
  // one for display; the detail page keeps the exact value.
  presetFromPref(pref) {
    if (pref?.muted) {
      return MUTED_VALUE;
    }
    const volume = Math.round((pref?.volume ?? 1) * 100);
    let best = 100;
    let bestDistance = Number.POSITIVE_INFINITY;
    for (const candidate of [25, 50, 75, 100]) {
      const distance = Math.abs(candidate - volume);
      if (distance < bestDistance) {
        bestDistance = distance;
        best = candidate;
      }
    }
    return best;
  }
  // "Name ●●●○ 70%" while heard, "Name ✕ muted" while not. § color codes only
  // render on label widgets (buttons and dropdown labels print them
  // literally), so they are opt-in via `colored`.
  stateLabel(target, colored) {
    const name = this.displayName(target);
    const pref = this.cache.preferenceFor(target);
    if (pref?.muted) {
      return colored ? `\xA78${name} \u2715 muted\xA7r` : `${name} \u2715 muted`;
    }
    const volume = Math.round((pref?.volume ?? 1) * 100);
    const state = `${this.pips(volume)} ${volume}%`;
    return colored ? `\xA7a${name}\xA7r ${state}` : `${name} ${state}`;
  }
  // The jukebox rides the preference plane under a wire sentinel; every surface a player reads
  // shows its name instead.
  displayName(target) {
    return target === JUKEBOX_TARGET ? JUKEBOX_LABEL : target;
  }
  pips(volume) {
    const filled = Math.min(4, Math.max(0, Math.ceil(volume / 25)));
    return "\u25CF".repeat(filled) + "\u25CB".repeat(4 - filled);
  }
};

// src/ui/panel.ts
var SYNC_TIMEOUT_TICKS = 40;
var ControlPanel = class {
  constructor(getSender, cacheStore, getFeed, panelTest, getGroupSource) {
    this.getSender = getSender;
    this.cacheStore = cacheStore;
    this.getFeed = getFeed;
    this.panelTest = panelTest;
    this.getGroupSource = getGroupSource;
  }
  open(player) {
    if (!player.isValid) {
      return;
    }
    const sender = this.getSender();
    if (!sender) {
      player.sendMessage("\xA7c[BVC] Still starting up; try again in a moment");
      return;
    }
    void this.session(player, sender);
  }
  async session(player, sender) {
    const cache = this.cacheStore.for(player.name);
    let displayedTargets = cache.adjustedTargets();
    const feed = this.getFeed();
    const volumes = new PlayerVolumesView(
      sender,
      cache,
      (targets) => {
        displayedTargets = targets;
        feed?.start(player, cache, () => displayedTargets);
      },
      this.panelTest
    );
    feed?.start(player, cache, () => displayedTargets);
    this.getGroupSource()?.warm(player);
    const syncTimeout = system9.runTimeout(() => {
      if (!cache.synced) {
        cache.status.setData("State unavailable");
      }
    }, SYNC_TIMEOUT_TICKS);
    const unsubscribes = [];
    const subscribe = (observable, onPlayerChange) => {
      let lastSeen = observable.getData();
      const listener = observable.subscribe((value) => {
        if (value === lastSeen) {
          return;
        }
        lastSeen = value;
        if (!cache.isSyncing) {
          onPlayerChange(value);
        }
      });
      unsubscribes.push(() => observable.unsubscribe(listener));
    };
    subscribe(cache.muted, (on) => {
      cache.markPending("muted", on);
      void sender.send({ kind: "mute", on }, player);
    });
    subscribe(cache.deafened, (on) => {
      cache.markPending("deafened", on);
      void sender.send({ kind: "deafen", on }, player);
    });
    const recordLabel = new ObservableString5(
      cache.recording.getData() ? "Stop recording" : "Start recording"
    );
    const recordListener = cache.recording.subscribe((recording) => {
      recordLabel.setData(recording ? "Stop recording" : "Start recording");
    });
    unsubscribes.push(() => cache.recording.unsubscribe(recordListener));
    let reopenAfterVolumes = false;
    let reopenAfterGroups = false;
    let confirmRecordAfterClose = false;
    const form = new CustomForm3(player, "BVC Voice Controls").label(cache.status).divider().toggle("Muted", cache.muted, { disabled: cache.controlsLocked }).toggle("Deafened", cache.deafened, { disabled: cache.controlsLocked }).button(
      recordLabel,
      () => {
        if (cache.recording.getData()) {
          cache.markPending("recording", false);
          void sender.send({ kind: "record", on: false }, player);
          return;
        }
        confirmRecordAfterClose = true;
        form.close();
      },
      { disabled: cache.controlsLocked }
    ).divider().button("Groups\u2026", () => {
      reopenAfterGroups = true;
      form.close();
    }).button("Player volumes\u2026", () => {
      reopenAfterVolumes = true;
      form.close();
    }).closeButton();
    const closedReason = await FormShow.withRetry(form);
    if (reopenAfterVolumes && closedReason === DataDrivenScreenClosedReason4.ServerClosed) {
      await volumes.show(player);
    }
    if (reopenAfterGroups && closedReason === DataDrivenScreenClosedReason4.ServerClosed) {
      const source = this.getGroupSource();
      if (source) {
        const leaveDisabled = feed?.tracksCurrentGroup() ?? true ? cache.noGroup : false;
        await new GroupListView(
          sender,
          source,
          cache,
          this.panelTest,
          leaveDisabled
        ).show(player);
      } else {
        player.sendMessage("\xA7c[BVC] Still starting up; try again in a moment");
      }
    }
    system9.clearRun(syncTimeout);
    for (const unsubscribe of unsubscribes) {
      unsubscribe();
    }
    feed?.stop(player.name);
    if (closedReason === DataDrivenScreenClosedReason4.ServerClosed) {
      if (confirmRecordAfterClose) {
        await this.confirmStartRecording(player, sender, cache);
      }
      if ((reopenAfterVolumes || reopenAfterGroups || confirmRecordAfterClose) && player.isValid) {
        this.open(player);
      }
    }
  }
  async confirmStartRecording(player, sender, cache) {
    let confirmed = false;
    const form = new CustomForm3(player, "Start recording?").label("This records your voice session on your desktop app.").button("Start", () => {
      confirmed = true;
      form.close();
    }).closeButton();
    const reason = await FormShow.withRetry(form);
    if (reason === DataDrivenScreenClosedReason4.UserBusy) {
      player.sendMessage(
        "\xA7c[BVC] Could not open the recording confirmation; try again"
      );
      return;
    }
    if (!confirmed) {
      return;
    }
    cache.markPending("recording", true);
    const result = await sender.send({ kind: "record", on: true }, player);
    if (!result.ok) {
      cache.markPending("recording", false);
      player.sendMessage("\xA7c[BVC] Recording failed to start; try again");
    }
  }
};

// src/ui/panel_test.ts
var PanelTestConfig = class {
  constructor() {
    this.syntheticCount = 0;
  }
  set(count) {
    this.syntheticCount = Math.max(0, Math.min(100, Math.floor(count)));
  }
  get count() {
    return this.syntheticCount;
  }
};

// src/commands/disc.ts
import {
  system as system10,
  ItemStack as ItemStack3,
  CommandPermissionLevel,
  CustomCommandParamType,
  CustomCommandStatus
} from "@minecraft/server";
var DiscCommand = class _DiscCommand {
  static register() {
    system10.beforeEvents.startup.subscribe((event) => {
      event.customCommandRegistry.registerCommand(
        {
          name: "bvc:disc",
          description: "Give yourself a BVC audio disc",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel.Any,
          optionalParameters: [
            { name: "audio_id", type: CustomCommandParamType.String }
          ]
        },
        (origin, audioId) => {
          const player = origin.sourceEntity;
          if (!player || player.typeId !== "minecraft:player") {
            return {
              status: CustomCommandStatus.Failure,
              message: "This command can only be run by a player"
            };
          }
          if (!audioId) {
            return {
              status: CustomCommandStatus.Failure,
              message: "Usage: /bvc:disc <audio_id>"
            };
          }
          system10.run(() => {
            _DiscCommand.giveDisc(player, audioId);
          });
          return { status: CustomCommandStatus.Success };
        }
      );
    });
  }
  static giveDisc(player, audioId) {
    try {
      const inventory = player.getComponent("minecraft:inventory");
      if (!inventory || !inventory.container) {
        return;
      }
      const disc = new ItemStack3("minecraft:music_disc_5", 1);
      disc.nameTag = `BVC: ${audioId}`;
      disc.setDynamicProperty("bvc:audio_id", audioId);
      disc.setDynamicProperty("bvc:is_bvc_disc", true);
      inventory.container.addItem(disc);
      player.sendMessage(`Gave you a BVC audio disc: ${audioId}`);
      console.info(`[BVC] Gave ${player.name} a BVC audio disc: ${audioId}`);
    } catch (e) {
      console.error("[BVC] Failed to create audio disc:", e);
    }
  }
};

// src/commands/jukebox_test.ts
import { system as system11, world as world5 } from "@minecraft/server";
var _JukeboxTestCommands = class _JukeboxTestCommands {
  constructor(audioManager2, getWorldUuid2) {
    this.audioManager = audioManager2;
    this.getWorldUuid = getWorldUuid2;
  }
  register() {
    system11.afterEvents.scriptEventReceive.subscribe(
      (event) => {
        if (event.id === "bvc:insert") {
          this.onInsert(event.message.trim());
        } else if (event.id === "bvc:eject") {
          this.onEject(event.message.trim());
        }
      },
      { namespaces: ["bvc"] }
    );
    console.info(
      "[BVC jukebox-test] ready \u2014 scriptevent bvc:insert <audio_id> [x y z] | scriptevent bvc:eject [x y z]"
    );
  }
  onInsert(message) {
    const parts = _JukeboxTestCommands.words(message);
    const audioId = parts[0];
    if (!audioId) {
      console.error(
        "[BVC jukebox-test] usage: scriptevent bvc:insert <audio_id> [x y z]"
      );
      return;
    }
    const target = this.resolveTarget(parts.slice(1));
    if (!target) {
      return;
    }
    if (this.audioManager.hasDisc(target.locationKey)) {
      console.warn(
        `[BVC jukebox-test] a disc is already in the jukebox at ${target.locationKey}; eject first`
      );
      return;
    }
    this.audioManager.insertDisc(
      target.locationKey,
      audioId,
      _JukeboxTestCommands.DIMENSION,
      target.coordinates,
      this.getWorldUuid(),
      null
    );
    this.setPlaying(target.block, true);
    console.info(
      `[BVC jukebox-test] inserted ${audioId} at ${target.locationKey}`
    );
  }
  onEject(message) {
    const target = this.resolveTarget(_JukeboxTestCommands.words(message));
    if (!target) {
      return;
    }
    if (!this.audioManager.hasDisc(target.locationKey)) {
      console.warn(`[BVC jukebox-test] no disc at ${target.locationKey}`);
      return;
    }
    const audioId = this.audioManager.ejectDisc(target.locationKey, null);
    this.setPlaying(target.block, false);
    console.info(
      `[BVC jukebox-test] ejected ${audioId ?? "unknown"} at ${target.locationKey}`
    );
  }
  // The block has to be loaded and has to be ours: inserting against an arbitrary block would
  // leave manager state pointing at a jukebox that does not exist, and nothing would ever eject
  // it.
  resolveTarget(coords) {
    const coordinates = _JukeboxTestCommands.parseCoordinates(coords);
    if (!coordinates) {
      console.error("[BVC jukebox-test] coordinates must be three integers");
      return null;
    }
    const dimension = world5.getDimension(_JukeboxTestCommands.DIMENSION);
    let block;
    try {
      block = dimension.getBlock({
        x: coordinates.x,
        y: coordinates.y,
        z: coordinates.z
      });
    } catch (e) {
      console.error(
        `[BVC jukebox-test] cannot read the block, chunk unloaded? ${e}`
      );
      return null;
    }
    if (!block) {
      console.error(
        `[BVC jukebox-test] no block at (${coordinates.x},${coordinates.y},${coordinates.z}); is the chunk loaded?`
      );
      return null;
    }
    if (block.typeId !== _JukeboxTestCommands.BLOCK_ID) {
      console.error(
        `[BVC jukebox-test] block at (${coordinates.x},${coordinates.y},${coordinates.z}) is ${block.typeId}, not ${_JukeboxTestCommands.BLOCK_ID}`
      );
      return null;
    }
    return {
      block,
      coordinates,
      locationKey: this.audioManager.locationKey(
        this.getWorldUuid(),
        coordinates
      )
    };
  }
  static words(message) {
    return message.split(/\s+/).filter((part) => part.length > 0);
  }
  static parseCoordinates(parts) {
    if (parts.length === 0) {
      return _JukeboxTestCommands.DEFAULT_LOCATION;
    }
    if (parts.length !== 3) {
      return null;
    }
    const parsed = parts.map((part) => Number.parseInt(part, 10));
    if (parsed.some((value) => Number.isNaN(value))) {
      return null;
    }
    return new Coordinates(parsed[0], parsed[1], parsed[2]);
  }
  setPlaying(block, playing) {
    try {
      block.setPermutation(
        block.permutation.withState("bvc:playing", playing)
      );
    } catch (e) {
      console.error("[BVC jukebox-test] failed to set bvc:playing:", e);
    }
  }
};
_JukeboxTestCommands.DEFAULT_LOCATION = new Coordinates(0, 64, 0);
_JukeboxTestCommands.DIMENSION = "minecraft:overworld";
_JukeboxTestCommands.BLOCK_ID = "bvc:audio_player";
var JukeboxTestCommands = _JukeboxTestCommands;

// src/commands/control.ts
import {
  system as system12,
  world as world6,
  CommandPermissionLevel as CommandPermissionLevel2,
  CustomCommandParamType as CustomCommandParamType2,
  CustomCommandStatus as CustomCommandStatus2
} from "@minecraft/server";
var ControlCommands = class _ControlCommands {
  // The sender is resolved asynchronously (after config load), but commands must be
  // registered during startup — so the sender is provided lazily and read at
  // invocation time. `openPanel` opens the DDUI control panel for bare /bvc:panel.
  static register(getSender, openPanel, panelTest) {
    system12.beforeEvents.startup.subscribe((event) => {
      const registry = event.customCommandRegistry;
      const dispatch = (origin, action) => {
        const player = origin.sourceEntity;
        if (!player || player.typeId !== "minecraft:player") {
          return {
            status: CustomCommandStatus2.Failure,
            message: "This command can only be run by a player"
          };
        }
        const sender = getSender();
        if (!sender) {
          return {
            status: CustomCommandStatus2.Failure,
            message: "BVC is still starting up; try again in a moment"
          };
        }
        const acting = player;
        system12.run(() => {
          void (async () => {
            const result = await sender.send(action, acting);
            if (action.kind === "group-create") {
              if (result.groupCode) {
                acting.sendMessage(
                  `\xA7a[BVC] Group created \u2014 share code: \xA7f${result.groupCode}`
                );
              } else if (!result.ok) {
                acting.sendMessage("\xA7c[BVC] Group create failed; try again");
              }
            } else if (action.kind === "group-join" && result.outcome) {
              acting.sendMessage(
                JoinReply.forResult(result, action.channel, true)
              );
            } else if (!result.ok) {
              acting.sendMessage("\xA7c[BVC] Action failed; try again");
            }
          })();
        });
        return { status: CustomCommandStatus2.Success };
      };
      registry.registerCommand(
        {
          name: "bvc:panel",
          description: "Open the BVC voice control panel",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any
        },
        (origin) => {
          const player = origin.sourceEntity;
          if (!player || player.typeId !== "minecraft:player") {
            return {
              status: CustomCommandStatus2.Failure,
              message: "This command can only be run by a player"
            };
          }
          const acting = player;
          system12.run(() => openPanel(acting));
          return { status: CustomCommandStatus2.Success };
        }
      );
      const boolParam = (name) => ({
        name,
        type: CustomCommandParamType2.Boolean
      });
      const strParam = (name) => ({
        name,
        type: CustomCommandParamType2.String
      });
      const intParam = (name) => ({
        name,
        type: CustomCommandParamType2.Integer
      });
      registry.registerCommand(
        {
          name: "bvc:mute",
          description: "Mute or unmute your microphone",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [boolParam("on")]
        },
        (origin, on) => dispatch(origin, { kind: "mute", on })
      );
      registry.registerCommand(
        {
          name: "bvc:deafen",
          description: "Deafen or undeafen (mute everyone)",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [boolParam("on")]
        },
        (origin, on) => dispatch(origin, { kind: "deafen", on })
      );
      registry.registerCommand(
        {
          name: "bvc:record",
          description: "Start or stop recording your session",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [boolParam("on")]
        },
        (origin, on) => dispatch(origin, { kind: "record", on })
      );
      registry.registerCommand(
        {
          name: "bvc:volume",
          description: "Set your local volume for another player (0-150)",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [strParam("player"), intParam("level")]
        },
        (origin, player, level) => {
          const target = _ControlCommands.resolveTarget(player);
          if (!target) {
            return _ControlCommands.unknownPlayer(player);
          }
          return dispatch(origin, {
            kind: "volume",
            target,
            value: Math.max(0, Math.min(MAX_LEVEL, level))
          });
        }
      );
      registry.registerCommand(
        {
          name: "bvc:hear",
          description: "Choose whether you hear another player",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [strParam("player"), boolParam("on")]
        },
        (origin, player, on) => {
          const target = _ControlCommands.resolveTarget(player);
          if (!target) {
            return _ControlCommands.unknownPlayer(player);
          }
          return dispatch(origin, { kind: "hear", target, on });
        }
      );
      registry.registerCommand(
        {
          name: "bvc:jukeboxmute",
          description: "Choose whether you hear jukebox music",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [boolParam("muted")]
        },
        (origin, muted) => (
          // The plane carries "heard", the command reads as "mute", so the flag inverts here.
          dispatch(origin, {
            kind: "hear",
            target: JUKEBOX_TARGET,
            on: !muted
          })
        )
      );
      registry.registerCommand(
        {
          name: "bvc:jukeboxvolume",
          description: "Set how loud jukebox music plays (0-150)",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [intParam("level")]
        },
        (origin, level) => dispatch(origin, {
          kind: "volume",
          target: JUKEBOX_TARGET,
          value: Math.max(0, Math.min(MAX_LEVEL, level))
        })
      );
      registry.registerCommand(
        {
          name: "bvc:groupcreate",
          description: "Create a group and get its share code",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any
        },
        (origin) => dispatch(origin, { kind: "group-create" })
      );
      registry.registerCommand(
        {
          name: "bvc:groupjoin",
          description: "Join a group by its share code",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any,
          mandatoryParameters: [strParam("code")]
        },
        (origin, code) => dispatch(origin, { kind: "group-join", channel: code })
      );
      registry.registerCommand(
        {
          name: "bvc:groupleave",
          description: "Leave your current group",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.Any
        },
        (origin) => dispatch(origin, { kind: "group-leave" })
      );
      registry.registerCommand(
        {
          name: "bvc:paneltest",
          description: "Pad the volumes view with synthetic players (0 to clear)",
          cheatsRequired: false,
          permissionLevel: CommandPermissionLevel2.GameDirectors,
          mandatoryParameters: [intParam("count")]
        },
        (_origin, count) => {
          panelTest.set(count);
          return {
            status: CustomCommandStatus2.Success,
            message: `Volumes view padded with ${panelTest.count} synthetic players`
          };
        }
      );
    });
  }
  // Per-player preferences key on the target's EXACT gamertag everywhere
  // downstream (the desktop gain store, the sink's name remap, the player
  // cards) — a typed name must resolve to a real online player's exact name,
  // never pass through raw. This is what makes the CLI land on the same path
  // the DDUI volumes view uses (whose rows are always exact names).
  static resolveTarget(typed) {
    const lowered = typed.toLowerCase();
    for (const p of world6.getAllPlayers()) {
      if (p.name.toLowerCase() === lowered) {
        return p.name;
      }
    }
    return null;
  }
  static unknownPlayer(typed) {
    return {
      status: CustomCommandStatus2.Failure,
      message: `Unknown player: ${typed}`
    };
  }
};

// src/audio/sender/net_audio_sender.ts
import { system as system13 } from "@minecraft/server";
var NetAudioSender = class {
  constructor(config, onAutoEject, locationKeyOf) {
    this.config = config;
    this.onAutoEject = onAutoEject;
    this.locationKeyOf = locationKeyOf;
  }
  async start(state, _actor) {
    const request = new AudioPlayRequest(
      state.audioId,
      state.coordinates,
      state.dimensionId.replace("minecraft:", ""),
      state.worldUuid
    );
    const body = JSON.stringify(request.toJSON());
    const locationKey = this.locationKeyOf(state);
    try {
      const response = await httpClient.request(
        `${this.config.bvcServer}/api/audio/event`,
        "Post",
        body,
        [
          ["Content-Type", "application/json"],
          ["Authorization", `Bearer ${this.config.accessToken}`],
          ["Accept", "application/json"]
        ],
        5
      );
      if (!response) {
        state.isPlaying = false;
        return;
      }
      if (response.status < 200 || response.status >= 300) {
        state.isPlaying = false;
        console.warn(`[BVC] Play request failed: ${response.status}`);
        return;
      }
      const data = JSON.parse(response.body);
      state.isPlaying = true;
      state.eventId = data.event_id;
      const ticks = Math.ceil(data.duration_ms / 50);
      state.autoEjectRunId = system13.runTimeout(() => {
        this.onAutoEject(locationKey);
      }, ticks);
    } catch (e) {
      state.isPlaying = false;
      console.error("[BVC] Failed to start playback:", e);
    }
  }
  async stop(state, _actor) {
    const eventId = state.eventId;
    if (!eventId) {
      return;
    }
    state.isPlaying = false;
    state.eventId = null;
    try {
      const response = await httpClient.request(
        `${this.config.bvcServer}/api/audio/event/${eventId}`,
        "Delete",
        void 0,
        [
          ["Authorization", `Bearer ${this.config.accessToken}`],
          ["Accept", "application/json"]
        ],
        5
      );
      if (!response) {
        return;
      }
      if (response.status >= 200 && response.status < 300) {
        return;
      }
      console.warn(`[BVC] Stop request failed: ${response.status}`);
    } catch (e) {
      console.error("[BVC] Failed to stop playback:", e);
    }
  }
};

// src/audio/sender/no_net_audio_sender.ts
import { world as world7, system as system14 } from "@minecraft/server";

// src/audio/sender/protocol.ts
var JukeboxBusProtocol = class {
};
JukeboxBusProtocol.PLAY = "bvc:play:";
JukeboxBusProtocol.EJECT = "bvc:eject:";

// src/audio/sender/no_net_audio_sender.ts
var NoNetAudioSender = class {
  async start(state, actor) {
    await this.nextTick();
    const fulfiller = this.resolveFulfiller(state, actor);
    if (!fulfiller) {
      console.warn("[BVC] No fulfiller for jukebox play; audio not started");
      return;
    }
    const c = state.coordinates;
    fulfiller.playSound(
      `${JukeboxBusProtocol.PLAY}${state.audioId}:${state.dimensionId}`,
      {
        location: { x: c.x, y: c.y, z: c.z },
        volume: 0.1
      }
    );
  }
  async stop(state, actor) {
    await this.nextTick();
    const fulfiller = this.resolveFulfiller(state, actor);
    if (!fulfiller) {
      return;
    }
    const c = state.coordinates;
    fulfiller.playSound(`${JukeboxBusProtocol.EJECT}${state.dimensionId}`, {
      location: { x: c.x, y: c.y, z: c.z },
      volume: 0.1
    });
  }
  // Yields to a fresh tick so the chat send runs outside the block-component
  // callback context, where mutating/side-effecting APIs are not permitted.
  nextTick() {
    return new Promise((resolve) => system14.run(resolve));
  }
  resolveFulfiller(state, actor) {
    if (actor) {
      return actor;
    }
    return this.closestPlayer(state);
  }
  closestPlayer(state) {
    const c = state.coordinates;
    let best = null;
    let bestDistSq = Number.POSITIVE_INFINITY;
    let bestId = "";
    for (const p of world7.getAllPlayers()) {
      if (p.dimension.id !== state.dimensionId) {
        continue;
      }
      const dx = p.location.x - (c.x + 0.5);
      const dy = p.location.y - (c.y + 0.5);
      const dz = p.location.z - (c.z + 0.5);
      const distSq = dx * dx + dy * dy + dz * dz;
      if (distSq < bestDistSq || distSq === bestDistSq && p.id < bestId) {
        best = p;
        bestDistSq = distSq;
        bestId = p.id;
      }
    }
    return best;
  }
};

// src/control/codec.ts
var _ControlCodec = class _ControlCodec {
  static encodeCtl(a) {
    const p = _ControlCodec.PREFIX;
    switch (a.kind) {
      case "mute":
        return `${p}mute:${a.on ? 1 : 0}`;
      case "deafen":
        return `${p}deafen:${a.on ? 1 : 0}`;
      case "record":
        return `${p}record:${a.on ? 1 : 0}`;
      case "volume":
        return `${p}vol:${a.target}:${Math.round(a.value)}`;
      case "hear":
        return `${p}hear:${a.target}:${a.on ? 1 : 0}`;
      case "group-create":
        return `${p}group:create`;
      case "group-join":
        return `${p}group:join:${a.channel}`;
      case "group-leave":
        return `${p}group:leave`;
    }
  }
  // The panel's scoped snapshot request (no-net): the proxy answers with !bvcs:
  // rides for the self-state plus the named targets' preferences.
  static encodeSync(targets) {
    return `${_ControlCodec.PREFIX}sync:${targets.join(",")}`;
  }
  // The panel's group-list request (no-net): the proxy answers with a `gl` header
  // ride followed by one `g` ride per group.
  static encodeGroups() {
    return `${_ControlCodec.PREFIX}groups`;
  }
  // `game` labels the actor the same way the /api/position body does, so the server
  // builds this player's canonical `game:gamertag` key from a declared value instead
  // of assuming one. A BDS pack is always Minecraft.
  static toClientActionJson(a, actorId) {
    return {
      id: actorId,
      game: "minecraft",
      action: _ControlCodec.actionJson(a)
    };
  }
  // Unit variants serialize as bare strings; struct/tuple variants as { Variant: ... }.
  static actionJson(a) {
    switch (a.kind) {
      case "mute":
        return { SetMuted: a.on };
      case "deafen":
        return { SetDeafened: a.on };
      case "record":
        return { SetRecording: a.on };
      case "volume":
        return { SetVolume: { target: a.target, volume: a.value / 100 } };
      case "hear":
        return { SetHeard: { target: a.target, muted: !a.on } };
      case "group-create":
        return "CreateGroup";
      case "group-join":
        return { JoinGroup: { channel: a.channel } };
      case "group-leave":
        return "LeaveGroup";
    }
  }
};
_ControlCodec.PREFIX = "bvc:ctl:";
var ControlCodec = _ControlCodec;

// src/control/sender/net/net_control_sender.ts
var NetControlSender = class {
  constructor(config) {
    this.config = config;
    // Warn only on reachability transitions, not on every failed request — otherwise a
    // brief server outage plus a few commands floods the console. `null` = unknown yet.
    this.reachable = null;
  }
  async send(action, actor) {
    const body = JSON.stringify(
      ControlCodec.toClientActionJson(action, actor.name)
    );
    try {
      const response = await httpClient.request(
        `${this.config.bvcServer}/api/control`,
        "Post",
        body,
        [
          ["Content-Type", "application/json"],
          ["Authorization", `Bearer ${this.config.accessToken}`],
          ["Accept", "application/json"]
        ],
        5
      );
      const ok = !!response && response.status >= 200 && response.status < 300;
      if (response && response.status === 202) {
        this.markReachable();
        return { ok: true, outcome: "forwarded" };
      }
      if (response && response.status === 409) {
        this.markReachable();
        return { ok: false, outcome: "no_session" };
      }
      if (ok) {
        this.markReachable();
        return { ok, groupCode: this.parseGroupCode(response.body) };
      }
      const detail = response ? `status ${response.status}` : "no response";
      this.markUnreachable(detail);
      return { ok: false };
    } catch (e) {
      this.markUnreachable(e instanceof Error ? e.message : String(e));
      return { ok: false };
    }
  }
  parseGroupCode(body) {
    try {
      const parsed = JSON.parse(body);
      return typeof parsed === "string" && parsed.length > 0 ? parsed : void 0;
    } catch {
      return void 0;
    }
  }
  markUnreachable(detail) {
    if (this.reachable !== false) {
      console.warn(
        `[BVC] Control server unreachable (${detail}); suppressing further warnings until it recovers`
      );
    }
    this.reachable = false;
  }
  markReachable() {
    if (this.reachable === false) {
      console.info("[BVC] Control server reachable again");
    }
    this.reachable = true;
  }
};

// src/control/sender/no_net/no_net_control_sender.ts
import { system as system15 } from "@minecraft/server";
var NoNetControlSender = class {
  async send(action, actor) {
    await this.playCtl(ControlCodec.encodeCtl(action), actor);
    return { ok: true };
  }
  // The panel's scoped snapshot request: the proxy answers with !bvcs: rides.
  async requestSync(targets, actor) {
    await this.playCtl(ControlCodec.encodeSync(targets), actor);
  }
  // The panel's group-list request: the proxy answers with a header ride and one
  // ride per group.
  async requestGroups(actor) {
    await this.playCtl(ControlCodec.encodeGroups(), actor);
  }
  async playCtl(name, actor) {
    await this.nextTick();
    const c = actor.location;
    actor.playSound(name, {
      location: { x: c.x, y: c.y, z: c.z },
      volume: 0
    });
  }
  nextTick() {
    return new Promise((resolve) => system15.run(resolve));
  }
};

// src/config/server_admin_config.ts
var ServerAdminConfig = class {
  constructor() {
    this.loadPromise = null;
    this.loaded = false;
    this.module = null;
    this.warned = false;
    this.serverUrl = "";
    this.token = "";
    this.minPlayers = 1;
    this.world = "";
  }
  get bvcServer() {
    return this.serverUrl;
  }
  get accessToken() {
    return this.token;
  }
  get minimumPlayers() {
    return this.minPlayers;
  }
  /**
   * The world's display name, for the app's chat target picker.
   *
   * BDS scripting does not expose the level name, so `bvc_world_name` is the only real label
   * available. Unset, the world's uuid is sent rather than a friendly-looking constant: every
   * unnamed world reported the same `Minecraft world`, which the app could not tell apart and
   * could not tell was a placeholder. A uuid is unmistakably not a name, so the app matches
   * the shape and substitutes a label of its own.
   */
  worldNameOr(worldUuid) {
    return this.world || worldUuid;
  }
  isAvailable() {
    return this.loaded && this.module !== null;
  }
  async ensureLoaded() {
    if (this.loaded) {
      return this.module !== null;
    }
    if (!this.loadPromise) {
      this.loadPromise = import("@minecraft/server-admin").then((m) => {
        this.module = m;
        const variables = m.variables;
        const server = variables.get("bvc_server");
        if (typeof server === "string") {
          this.serverUrl = server;
        }
        const accessToken = variables.get("bvc_access_token");
        if (typeof accessToken === "string") {
          this.token = accessToken;
        }
        const worldName = variables.get("bvc_world_name");
        if (typeof worldName === "string") {
          this.world = worldName;
        }
        const minimum = variables.get("bvc_minimum_players");
        if (typeof minimum === "number" && minimum >= 1) {
          this.minPlayers = Math.floor(minimum);
        }
      }).catch((e) => {
        this.module = null;
        if (!this.warned) {
          console.warn(
            "[BVC] @minecraft/server-admin not available; server variables disabled: " + e
          );
          this.warned = true;
        }
      }).then(() => {
        this.loaded = true;
      });
    }
    await this.loadPromise;
    return this.module !== null;
  }
};
var serverAdminConfig = new ServerAdminConfig();

// src/chat/channel.ts
import { system as system16 } from "@minecraft/server";
var BACKOFF_MIN_TICKS = 40;
var BACKOFF_MAX_TICKS = 1200;
var LIVENESS_TICKS = 100;
var ChatChannel = class {
  constructor(serverUrl, accessToken, worldUuid, worldName, onSay) {
    this.serverUrl = serverUrl;
    this.accessToken = accessToken;
    this.worldUuid = worldUuid;
    this.worldName = worldName;
    this.onSay = onSay;
    this.client = null;
    this.connecting = false;
    this.stopped = false;
    this.backoff = BACKOFF_MIN_TICKS;
    this.liveness = null;
    this.retryPending = false;
  }
  get isOpen() {
    return this.client !== null && this.client.isOpen;
  }
  start() {
    this.stopped = false;
    void this.connect();
    this.liveness = system16.runInterval(() => {
      if (this.stopped || this.connecting || this.retryPending || this.isOpen) {
        return;
      }
      void this.connect();
    }, LIVENESS_TICKS);
  }
  stop() {
    this.stopped = true;
    if (this.liveness !== null) {
      system16.clearRun(this.liveness);
      this.liveness = null;
    }
    const current = this.client;
    this.client = null;
    this.discard(current);
  }
  /**
   * Reports a line a player typed in game.
   *
   * Nothing is queued while the socket is down. A backlog delivered on reconnect drops stale
   * lines into a conversation that has moved on, and no message is ever persisted.
   */
  report(author, text) {
    if (!this.isOpen) {
      return;
    }
    this.send({ t: "chat", author, text });
  }
  /**
   * Reports something the server said: a death, a join, a leave.
   *
   * Carries no author, which is what makes it render as a system line rather than as a player
   * speaking. Dropped while the socket is down for the same reason a chat line is.
   */
  event(text) {
    if (!this.isOpen) {
      return;
    }
    this.send({ t: "event", text });
  }
  async connect() {
    if (this.connecting || this.stopped) {
      return;
    }
    this.connecting = true;
    try {
      const net = await this.loadNet();
      if (!net) {
        this.stopped = true;
        return;
      }
      const url = this.serverUrl.replace(/^http/, "ws") + "/api/websocket/chat";
      const client = await net.websocket.connect(url, [
        new net.HttpHeader("Authorization", `Bearer ${this.accessToken}`)
      ]);
      if (this.stopped) {
        this.discard(client);
        return;
      }
      const previous = this.client;
      this.client = client;
      this.discard(previous);
      this.backoff = BACKOFF_MIN_TICKS;
      client.afterEvents.message.subscribe((e) => this.receive(e.message));
      client.afterEvents.close.subscribe((e) => {
        if (this.client !== client) {
          return;
        }
        console.warn("[BVC] chat channel closed: " + e.reason);
        this.client = null;
        this.scheduleRetry();
      });
      this.send({
        t: "hello",
        world: this.worldUuid,
        world_name: this.worldName,
        game: "minecraft"
      });
      console.info("[BVC] chat channel connected");
    } catch (e) {
      console.warn("[BVC] chat channel connect failed: " + e);
      this.scheduleRetry();
    } finally {
      this.connecting = false;
    }
  }
  receive(body) {
    try {
      const frame = JSON.parse(body);
      if (frame.t !== "say" || !frame.author || !frame.text) {
        return;
      }
      this.onSay(frame.author, frame.text);
    } catch {
      console.warn("[BVC] chat channel received an undecodable frame");
    }
  }
  send(frame) {
    try {
      this.client?.send(JSON.stringify(frame));
    } catch (e) {
      console.warn("[BVC] chat channel send failed: " + e);
    }
  }
  /**
   * Arranges one redial.
   *
   * At most one is ever outstanding. Every socket that dies asks for a retry, and a world
   * holding several sockets therefore asks several times for the one connection it wants;
   * honouring each request builds a socket per request and the count only climbs.
   */
  scheduleRetry() {
    if (this.stopped || this.retryPending) {
      return;
    }
    this.retryPending = true;
    const jitter = Math.floor(Math.random() * BACKOFF_MIN_TICKS);
    const delay = Math.min(this.backoff, BACKOFF_MAX_TICKS) + jitter;
    this.backoff = Math.min(this.backoff * 2, BACKOFF_MAX_TICKS);
    system16.runTimeout(() => {
      this.retryPending = false;
      void this.connect();
    }, delay);
  }
  /** Closes a socket this channel is done with. A closed one throws rather than reporting. */
  discard(client) {
    if (!client || !client.isOpen) {
      return;
    }
    try {
      client.close();
    } catch (e) {
      console.warn("[BVC] chat channel close failed: " + e);
    }
  }
  async loadNet() {
    try {
      return await import("@minecraft/server-net");
    } catch {
      return null;
    }
  }
};

// src/chat/listener.ts
import { world as world8 } from "@minecraft/server";

// src/chat/death_message.ts
import { EntityDamageCause } from "@minecraft/server";
var _DeathMessage = class _DeathMessage {
  static render(victim, source) {
    const killer = _DeathMessage.killerOf(source);
    const template = _DeathMessage.template(source?.cause, killer !== null);
    return template.replace("%1", victim).replace("%2", killer ?? _DeathMessage.UNNAMED);
  }
  /**
   * Templates carry `%1` for the victim and `%2` for the killer.
   *
   * `named` splits the causes that word themselves differently with and without a killer.
   * Reading `%2` when nothing killed the player produces "was slain by something", which is
   * worse than the plain form.
   */
  static template(cause, named) {
    switch (cause) {
      case EntityDamageCause.anvil:
        return "%1 was squashed by a falling anvil";
      case EntityDamageCause.blockExplosion:
        return "%1 blew up";
      case EntityDamageCause.campfire:
      case EntityDamageCause.soulCampfire:
      case EntityDamageCause.fire:
        return "%1 went up in flames";
      case EntityDamageCause.contact:
        return "%1 was pricked to death";
      case EntityDamageCause.drowning:
        return "%1 drowned";
      case EntityDamageCause.entityAttack:
        return named ? "%1 was slain by %2" : "%1 was slain";
      case EntityDamageCause.entityExplosion:
        return named ? "%1 was blown up by %2" : "%1 blew up";
      case EntityDamageCause.fall:
        return "%1 fell from a high place";
      case EntityDamageCause.fallingBlock:
        return "%1 was squashed by a falling block";
      case EntityDamageCause.fireTick:
        return "%1 burned to death";
      case EntityDamageCause.fireworks:
        return "%1 went off with a bang";
      case EntityDamageCause.flyIntoWall:
        return "%1 experienced kinetic energy";
      case EntityDamageCause.freezing:
      case EntityDamageCause.temperature:
        return "%1 froze to death";
      case EntityDamageCause.lava:
        return "%1 tried to swim in lava";
      case EntityDamageCause.lightning:
        return "%1 was struck by lightning";
      case EntityDamageCause.maceSmash:
        return named ? "%1 was smashed by %2" : "%1 died";
      case EntityDamageCause.magic:
        return named ? "%1 was killed by %2 using magic" : "%1 was killed by magic";
      case EntityDamageCause.magma:
        return "%1 discovered the floor was lava";
      case EntityDamageCause.projectile:
        return named ? "%1 was shot by %2" : "%1 was shot";
      case EntityDamageCause.ramAttack:
        return named ? "%1 was rammed to death by %2" : "%1 died";
      case EntityDamageCause.sonicBoom:
        return "%1 was obliterated by a sonically-charged shriek";
      case EntityDamageCause.stalactite:
        return "%1 was skewered by a falling stalactite";
      case EntityDamageCause.stalagmite:
        return "%1 was impaled on a stalagmite";
      case EntityDamageCause.starve:
        return "%1 starved to death";
      case EntityDamageCause.suffocation:
        return "%1 suffocated in a wall";
      case EntityDamageCause.thorns:
        return named ? "%1 was killed trying to hurt %2" : "%1 died";
      case EntityDamageCause.void:
        return "%1 fell out of the world";
      case EntityDamageCause.wither:
        return "%1 withered away";
      default:
        return named ? "%1 was killed by %2" : "%1 died";
    }
  }
  /**
   * Who to name, or null when nothing identifiable did it.
   *
   * The shooter is named ahead of the arrow: `damagingEntity` is the mob or player that fired,
   * and naming the projectile instead loses the only part anyone cares about. A projectile
   * with no shooter still reads better than nothing.
   */
  static killerOf(source) {
    if (!source) {
      return null;
    }
    return _DeathMessage.nameOf(source.damagingEntity) ?? _DeathMessage.nameOf(source.damagingProjectile);
  }
  /**
   * Every read here can throw: the killer may already have been removed by the time the death
   * is reported, and touching a removed entity raises rather than returning undefined. A death
   * line is not worth losing to that, so an unreadable killer is simply not named.
   */
  static nameOf(entity) {
    if (!entity) {
      return null;
    }
    try {
      if (entity.typeId === "minecraft:player") {
        return entity.name;
      }
      if (entity.nameTag) {
        return entity.nameTag;
      }
      return _DeathMessage.readableType(entity.typeId);
    } catch {
      return null;
    }
  }
  /** `minecraft:cave_spider` reads as `Cave Spider`. */
  static readableType(typeId) {
    const bare = typeId.includes(":") ? typeId.slice(typeId.indexOf(":") + 1) : typeId;
    return bare.split("_").filter((word) => word.length > 0).map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(" ");
  }
};
_DeathMessage.UNNAMED = "something";
var DeathMessage = _DeathMessage;

// src/chat/listener.ts
var ChatListener = class _ChatListener {
  constructor(channel) {
    this.channel = channel;
  }
  register() {
    world8.afterEvents.chatSend.subscribe((ev) => {
      if (_ChatListener.isRide(ev.message)) {
        return;
      }
      this.channel.report(ev.sender.name, ev.message);
    });
    world8.afterEvents.entityDie.subscribe(
      (ev) => {
        if (!world8.gameRules.showDeathMessages) {
          return;
        }
        const victim = ev.deadEntity;
        this.channel.event(DeathMessage.render(victim.name, ev.damageSource));
      },
      { entityTypes: ["minecraft:player"] }
    );
    world8.afterEvents.playerJoin.subscribe((ev) => {
      this.channel.event(`${ev.playerName} joined the game`);
    });
    world8.afterEvents.playerLeave.subscribe((ev) => {
      this.channel.event(`${ev.playerName} left the game`);
    });
  }
  /**
   * Broadcasts a line composed in the app, formatted to match vanilla so it is
   * indistinguishable from something typed in game.
   *
   * `world.sendMessage` does not fire `chatSend`, so this is never reported back and there is
   * nothing to suppress.
   */
  say(author, text) {
    world8.sendMessage(`<${author}> ${text}`);
  }
  static isRide(message) {
    // Only the internal !bvcs: codec is a protocol ride — !bvcp/!bvca were legacy
    // ride prefixes no longer sent by the BVC server, and !bvce has been migrated
    // to the /bvc:eject slash command.
    return message.startsWith("!bvcs:");
  }
};

// src/main.ts
var POLL_INTERVAL = 5;
var REQUEST_TIMEOUT = 1;
var FAILURE_THRESHOLD = 3;
var INITIAL_BACKOFF_MS = 1e4;
var MAX_BACKOFF_MS = 3e4;
var consecutiveFailures = 0;
var circuitOpenUntil = 0;
var deadPlayers = /* @__PURE__ */ new Set();
var cachedWorldUuid;
function getWorldUuid() {
  if (cachedWorldUuid) {
    return cachedWorldUuid;
  }
  const existing = world9.getDynamicProperty("bvc:world_uuid");
  if (typeof existing === "string") {
    cachedWorldUuid = existing;
    console.info("[BVC] Loaded world UUID: " + existing);
    return existing;
  }
  const uuid = [
    randomHex(8),
    randomHex(4),
    "4" + randomHex(3),
    (Math.floor(Math.random() * 4) + 8).toString(16) + randomHex(3),
    randomHex(12)
  ].join("-");
  world9.setDynamicProperty("bvc:world_uuid", uuid);
  cachedWorldUuid = uuid;
  console.info("[BVC] Generated world UUID: " + uuid);
  return uuid;
}
function randomHex(length) {
  let result = "";
  for (let i = 0; i < length; i++) {
    result += Math.floor(Math.random() * 16).toString(16);
  }
  return result;
}
var audioManager = new AudioPlayerManager();
var componentRegistry = new AudioComponentRegistry(
  audioManager,
  getWorldUuid
);
componentRegistry.register();
var jukeboxTestCommands = new JukeboxTestCommands(audioManager, getWorldUuid);
jukeboxTestCommands.register();
EjectCommand.register(audioManager, getWorldUuid);
var groupCache = new GroupCache();
var stateCacheStore = new StateCacheStore(groupCache);
var bvcsListener = new BvcsListener(
  (name) => stateCacheStore.for(name),
  groupCache
);
bvcsListener.register();
world9.afterEvents.playerLeave.subscribe((event) => {
  panelFeed?.stop(event.playerName);
  stateCacheStore.evict(event.playerName);
});
DiscCommand.register();
var controlSender = null;
var panelFeed = null;
var groupSource = null;
var panelTestConfig = new PanelTestConfig();
var controlPanel = new ControlPanel(
  () => controlSender,
  stateCacheStore,
  () => panelFeed,
  panelTestConfig,
  () => groupSource
);
ControlCommands.register(
  () => controlSender,
  (player) => controlPanel.open(player),
  panelTestConfig
);
serverAdminConfig.ensureLoaded().then(() => httpClient.ensureLoaded()).then((available) => {
  if (!available) {
    audioManager.setSender(new NoNetAudioSender());
    const noNetSender = new NoNetControlSender();
    controlSender = noNetSender;
    panelFeed = new NoNetStateSource(() => noNetSender);
    groupSource = new NoNetGroupSource(() => noNetSender, groupCache);
    console.warn(
      "[BVC] HTTP unavailable; using no-net jukebox bus (position polling and HTTP disc events disabled)"
    );
    return;
  }
  controlSender = new NetControlSender(serverAdminConfig);
  panelFeed = new NetStateSource(serverAdminConfig);
  groupSource = new NetGroupSource(serverAdminConfig, groupCache);
  audioManager.setSender(
    new NetAudioSender(
      serverAdminConfig,
      (key) => audioManager.forceEject(key),
      (state) => audioManager.locationKey(getWorldUuid(), state.coordinates)
    )
  );
  const bvcServer = serverAdminConfig.bvcServer;
  const accessToken = serverAdminConfig.accessToken;
  const minimumPlayers = serverAdminConfig.minimumPlayers;
  console.info("[BVC] Connecting to: " + bvcServer);
  system17.run(() => {
    let chatListener = null;
    const chatChannel = new ChatChannel(
      bvcServer,
      accessToken,
      getWorldUuid(),
      serverAdminConfig.worldNameOr(getWorldUuid()),
      (author, text) => chatListener?.say(author, text)
    );
    chatListener = new ChatListener(chatChannel);
    chatListener.register();
    chatChannel.start();
  });
  world9.afterEvents.entityDie.subscribe(
    (event) => {
      const deadEntity = event.deadEntity;
      if (deadEntity.typeId === "minecraft:player") {
        deadPlayers.add(deadEntity.id);
      }
    },
    { entityTypes: ["minecraft:player"] }
  );
  world9.afterEvents.playerSpawn.subscribe((event) => {
    deadPlayers.delete(event.player.id);
  });
  world9.afterEvents.playerLeave.subscribe((event) => {
    deadPlayers.delete(event.playerId);
    system17.runTimeout(async () => {
      try {
        const worldUuid = getWorldUuid();
        const phantom = Player.fromDisconnectedPlayer(
          event.playerName,
          worldUuid
        );
        const payload = new Payload("minecraft", [phantom]);
        await httpClient.request(
          `${bvcServer}/api/position`,
          "Post",
          payload.toJSONString(),
          [
            ["Content-Type", "application/json"],
            ["Authorization", `Bearer ${accessToken}`],
            ["Accept", "application/json"]
          ],
          REQUEST_TIMEOUT
        );
      } catch (error) {
        console.error("[BVC] Error sending disconnect phantom:", error);
      }
    }, 5);
  });
  system17.runInterval(async () => {
    const players = world9.getAllPlayers();
    if (players.length < minimumPlayers) {
      return;
    }
    const now = Date.now();
    if (consecutiveFailures >= FAILURE_THRESHOLD && now < circuitOpenUntil) {
      return;
    }
    try {
      const worldUuid = getWorldUuid();
      const payload = Payload.fromPlayers(players, deadPlayers, worldUuid);
      const response = await httpClient.request(
        `${bvcServer}/api/position`,
        "Post",
        payload.toJSONString(),
        [
          ["Content-Type", "application/json"],
          ["Authorization", `Bearer ${accessToken}`],
          ["Accept", "application/json"]
        ],
        REQUEST_TIMEOUT
      );
      if (response && response.status >= 200 && response.status < 300) {
        if (consecutiveFailures >= FAILURE_THRESHOLD) {
          console.info("[BVC] Connection restored");
        }
        consecutiveFailures = 0;
      } else {
        consecutiveFailures++;
        if (consecutiveFailures === FAILURE_THRESHOLD) {
          console.warn("[BVC] Backend unreachable, pausing requests");
        }
        if (consecutiveFailures >= FAILURE_THRESHOLD) {
          const backoff = Math.min(
            INITIAL_BACKOFF_MS * Math.pow(2, consecutiveFailures - FAILURE_THRESHOLD),
            MAX_BACKOFF_MS
          );
          circuitOpenUntil = Date.now() + backoff;
        }
      }
    } catch (error) {
      console.error("[BVC] Error creating player payload:", error);
    }
  }, POLL_INTERVAL);
});
