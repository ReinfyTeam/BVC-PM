<?php

/*
 *
 *  ____           _            __           _____
 * |  _ \    ___  (_)  _ __    / _|  _   _  |_   _|   ___    __ _   _ __ ___
 * | |_) |  / _ \ | | | '_ \  | |_  | | | |   | |    / _ \  / _` | | '_ ` _ \
 * |  _ <  |  __/ | | | | | | |  _| | |_| |   | |   |  __/ | (_| | | | | | | |
 * |_| \_\  \___| |_| |_| |_| |_|    \__, |   |_|    \___|  \__,_| |_| |_| |_|
 *                                   |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BedrockVoiceChat is a high-performance, fully asynchronous proximity voice chat
 * bridge for PocketMine-MP 5.36.0+ servers. Designed to handle 100+ concurrent players
 * without causing server tick drops or main-thread lag.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

$event = getenv("EVENT_NAME");
if ($event === "workflow_dispatch") {
	$rawVersion = trim((string) getenv("INPUT_VERSION"));
	$body = (string) getenv("INPUT_CHANGELOG");
} elseif ($event === "push") {
	$message = str_replace("\r\n", "\n", (string) getenv("RAW_MESSAGE"));
	[$subject, $body] = array_pad(explode("\n", $message, 2), 2, "");
	if (preg_match('/^[Rr]elease[:\s]+(v?[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?)$/D', $subject, $matches) !== 1) {
		fwrite(STDERR, "Commit subject must look like 'release: v1.2.3'.\n");
		exit(1);
	}
	$rawVersion = $matches[1];
} else {
	fwrite(STDERR, "Release metadata requires a push or workflow_dispatch event.\n");
	exit(1);
}

$version = str_starts_with($rawVersion, "v") ? substr($rawVersion, 1) : $rawVersion;
if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/D', $version) !== 1) {
	fwrite(STDERR, "Invalid release version; expected a version such as 1.2.3 or v1.4.0-BETA.\n");
	exit(1);
}
$target = (string) getenv("SHA");
$outputPath = (string) getenv("GITHUB_OUTPUT");
if (preg_match('/^[0-9a-f]{40}$/D', $target) !== 1 || $outputPath === "") {
	fwrite(STDERR, "Release metadata requires a commit SHA and GITHUB_OUTPUT path.\n");
	exit(1);
}

$prerelease = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1;
$tag = "v" . $version;
$title = "BedrockVoiceChat " . $tag;
$outputs = [
	"version" => $version,
	"tag" => $tag,
	"title" => $title,
	"target" => $target,
	"prerelease" => $prerelease ? "true" : "false",
	"make_latest" => $prerelease ? "false" : "true"
];
$content = "";
foreach ($outputs as $key => $value) {
	$content .= $key . "=" . $value . "\n";
}
$notes = "# " . $title . "\n\n" . (trim($body) !== "" ? trim($body) : "- Release " . $tag) . "\n";
if (file_put_contents("release-notes.md", $notes) === false || file_put_contents($outputPath, $content, FILE_APPEND) === false) {
	fwrite(STDERR, "Unable to write release metadata.\n");
	exit(1);
}
