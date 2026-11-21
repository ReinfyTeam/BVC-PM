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
 * Zuri attempts to enforce "vanilla Minecraft" mechanics, as well as preventing
 * players from abusing weaknesses in Minecraft or its protocol, making your server
 * more safe. Organized in different sections, various checks are performed to test
 * players doing, covering a wide range including flying and speeding, fighting
 * hacks, fast block breaking and nukers, inventory hacks, chat spam and other types
 * of malicious behaviour.
 *
 * @author ReinfyTeam
 * @link https://github.com/ReinfyTeam/
 *
 *
 */

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\http;

use pmmp\thread\ThreadSafeArray;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\thread\HttpWorkerThread;
use function is_array;
use function is_int;
use function is_string;

/**
 * Asynchronous HTTP client backed by a dedicated worker thread.
 *
 * Every request is dispatched to HttpWorkerThread (a dedicated background
 * thread). Completion callbacks run on the main thread via a polling task,
 * so they are free to use the PocketMine API. This never blocks the server's
 * main thread and can handle 100+ players without stalling.
 */
final class HttpClient {
	/** @var array<int, callable(int, string, string): void> */
	private static array $pendingCallbacks = [];
	private static int $nextSeq = 0;

	private BVC $plugin;
	private ThreadSafeArray $toThread;
	private ThreadSafeArray $fromThread;
	private ?HttpWorkerThread $worker = null;
	/** @var TaskHandler<ClosureTask>|null */
	private $poller = null;

	public function __construct(BVC $plugin) {
		$this->plugin = $plugin;
	}

	public function start() : void {
		$this->toThread = new ThreadSafeArray();
		$this->fromThread = new ThreadSafeArray();

		$this->worker = new HttpWorkerThread($this->toThread, $this->fromThread);
		$this->worker->start();

		// Poll results back on the main thread every tick
		$this->poller = $this->plugin->getScheduler()->scheduleRepeatingTask(
			new \pocketmine\scheduler\ClosureTask(function() : void {
				$this->pollResults();
			}),
			1
		);
	}

	public function stop() : void {
		if ($this->poller !== null) {
			$this->poller->cancel();
			$this->poller = null;
		}
		if ($this->worker !== null) {
			$this->worker->stop();
			$this->worker->join();
			$this->worker = null;
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @param callable(int, string, string): void $callback receives HTTP status, body and cURL error
	 */
	public function request(string $method, string $url, array $headers, ?string $body, callable $callback, int $timeout = 5) : void {
		$seq = ++self::$nextSeq;
		self::$pendingCallbacks[$seq] = $callback;

		$this->plugin->debug("[HttpClient] Dispatched request #{$seq}: {$method} {$url} (timeout: {$timeout}s)");

		$this->toThread[] = [
			'seq' => $seq,
			'method' => $method,
			'url' => $url,
			'headers' => $headers,
			'body' => $body,
			'timeout' => $timeout,
		];
	}

	private function pollResults() : void {
		while (true) {
			$result = $this->fromThread->shift();
			if ($result === null) {
				break;
			}
			if (!is_array($result)) {
				continue;
			}
			$seqValue = $result['seq'] ?? 0;
			$seq = is_int($seqValue) ? $seqValue : 0;
			$callback = self::$pendingCallbacks[$seq] ?? null;
			unset(self::$pendingCallbacks[$seq]);
			if ($callback !== null) {
				$statusValue = $result['httpCode'] ?? 0;
				$status = is_int($statusValue) ? $statusValue : 0;
				$errorValue = $result['error'] ?? '';
				$error = is_string($errorValue) ? $errorValue : '';
				$bodyValue = $result['body'] ?? '';
				$body = is_string($bodyValue) ? $bodyValue : '';
				$this->plugin->debug("[HttpClient] Finished request #{$seq}: status={$status}" . ($error !== '' ? " error={$error}" : ""));
				try {
					($callback)(
						$status,
						$body,
						$error
					);
				} catch(\Throwable $e) {
					$this->plugin->getLogger()->error('BVC HTTP callback failed: ' . $e->getMessage());
				}
			}
		}
	}
}
