# BedrockVoiceChat

A fully asynchronous integration for fast, stable proximity voice chat with bedrockvoicechat.com on PocketMine-MP servers.

## Build

The `Build and validate BedrockVoiceChat plugin` workflow builds the PHAR with DevTools `@v4` and uploads it as the `BedrockVoiceChat` workflow artifact. The build uses the Commando, PacketHandler, and libFormAPI virion revisions pinned in this repository's submodules.

The workflow applies `patches/simple-packet-handler-devtools.patch` to PacketHandler so DevTools can shade it safely. To build locally, initialize the submodules and apply that patch first. Copy the three folders from `virions/` into the server's `virions/` directory, put this plugin folder in `plugins/`, install DevTools as `plugins/DevTools.phar`, and run `/devtools build BedrockVoiceChat`.

```text
git submodule update --init --recursive
git -C virions/PacketHandler apply ../../patches/simple-packet-handler-devtools.patch
```

PHPStan runs at its maximum level through `composer analyze` and the PHPStan workflow.
