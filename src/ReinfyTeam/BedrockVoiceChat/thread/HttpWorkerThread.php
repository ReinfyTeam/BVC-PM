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

namespace ReinfyTeam\BedrockVoiceChat\thread;

use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\Thread;
use Throwable;
use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function strtoupper;

/**
 * Dedicated background worker thread for all BVC HTTP requests.
 *
 * This thread runs cURL requests off the main server thread, ensuring that
 * network latency never blocks the game loop. Requests are submitted via
 * $toThread and results are returned via $fromThread. The main thread polls
 * $fromThread on a repeating task.
 */
final class HttpWorkerThread extends Thread {
	private bool $running = true;

	public function __construct(
		private ThreadSafeArray $toThread,
		private ThreadSafeArray $fromThread
	) {
	}

	public function onRun() : void {
		while ($this->running) {
			$data = $this->toThread->synchronized(function() : mixed {
				while ($this->running && count($this->toThread) === 0) {
					$this->toThread->wait(50000); // 50ms wait with timeout
				}
				return $this->toThread->shift();
			});

			if ($data !== null) {
				if (!($data instanceof ThreadSafeArray) && !is_array($data)) {
					continue;
				}
				try {
					$result = $this->executeRequest($data);
				} catch (Throwable $e) {
					$seqVal = $data['seq'] ?? 0;
					$result = ThreadSafeArray::fromArray([
						'seq' => is_int($seqVal) ? $seqVal : 0,
						'httpCode' => 0,
						'body' => '',
						'error' => 'Worker thread exception: ' . $e->getMessage(),
					]);
				}
				$this->fromThread->synchronized(function() use ($result) : void {
					$this->fromThread[] = $result;
				});
			}
		}
	}

	public function stop() : void {
		$this->running = false;
		$this->toThread->synchronized(function() : void {
			$this->toThread->notify();
		});
	}

	/**
	 * @param ThreadSafeArray|array<mixed, mixed> $data
	 */
	private function executeRequest(ThreadSafeArray|array $data) : ThreadSafeArray {
		$seqValue = $data['seq'] ?? 0;
		$seq = is_int($seqValue) ? $seqValue : 0;
		$methodValue = $data['method'] ?? null;
		$method = is_string($methodValue) ? $methodValue : 'GET';
		$urlValue = $data['url'] ?? null;
		$url = is_string($urlValue) ? $urlValue : '';
		$headersValue = $data['headers'] ?? null;
		$body = $data['body'] ?? null;
		if ($body !== null && !is_string($body)) {
			$body = '';
		}
		$timeoutValue = $data['timeout'] ?? 5;
		$timeout = is_int($timeoutValue) ? $timeoutValue : 5;

		$headers = [];
		if ($headersValue instanceof ThreadSafeArray || is_array($headersValue)) {
			foreach ($headersValue as $name => $value) {
				if (is_string($name) && is_string($value) && $name !== '' && $value !== '') {
					$headers[$name] = $value;
				}
			}
		}

		$ch = curl_init($url);
		if ($ch === false) {
			return ThreadSafeArray::fromArray([
				'seq' => $seq,
				'httpCode' => 0,
				'body' => '',
				'error' => 'curl_init() failed',
			]);
		}

		$headerLines = [];
		foreach ($headers as $name => $value) {
			$headerLines[] = $name . ': ' . $value;
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$methodUpper = strtoupper($method);
		if ($methodUpper !== '') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUpper);
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, max(1, $timeout)));
		curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_NOSIGNAL, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'BVC-PMMP-Plugin/2.0.0');
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$result = curl_exec($ch);
		if ($result === false) {
			$error = curl_error($ch);
		} else {
			$error = '';
		}
		$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		return ThreadSafeArray::fromArray([
			'seq' => $seq,
			'httpCode' => $httpCode,
			'body' => is_string($result) ? $result : '',
			'error' => $error,
		]);
	}
}
