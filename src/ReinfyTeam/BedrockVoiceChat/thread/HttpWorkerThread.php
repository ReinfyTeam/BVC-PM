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
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function curl_setopt_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function strtoupper;
use function usleep;

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
			$data = $this->toThread->shift();
			if ($data !== null) {
				if (!is_array($data)) {
					continue;
				}
				$result = $this->executeRequest($data);
				$this->fromThread[] = $result;
			}
			// Prevent 100% CPU usage in an idle loop
			usleep(50000); // 50ms
		}
	}

	public function stop() : void {
		$this->running = false;
	}

	/**
	 * @param array<mixed, mixed> $data
	 * @return array<string, mixed>
	 */
	private function executeRequest(array $data) : array {
		$seqValue = $data['seq'] ?? 0;
		$seq = is_int($seqValue) ? $seqValue : 0;
		$methodValue = $data['method'] ?? null;
		$method = is_string($methodValue) ? $methodValue : 'GET';
		$urlValue = $data['url'] ?? null;
		$url = is_string($urlValue) ? $urlValue : '';
		$headersValue = $data['headers'] ?? null;
		$headers = is_array($headersValue) ? $headersValue : [];
		$body = $data['body'] ?? null;
		if ($body !== null && !is_string($body)) {
			$body = '';
		}
		$timeoutValue = $data['timeout'] ?? 5;
		$timeout = is_int($timeoutValue) ? $timeoutValue : 5;

		$ch = curl_init($url);
		if ($ch === false) {
			return ['seq' => $seq, 'httpCode' => 0, 'body' => '', 'error' => 'curl_init() failed'];
		}

		$headerLines = [];
		foreach ($headers as $name => $value) {
			if (!is_string($name) || !is_string($value) || $name === '' || $value === '') {
				continue;
			}
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
		curl_close($ch);

		return [
			'seq' => $seq,
			'httpCode' => $httpCode,
			'body' => is_string($result) ? $result : '',
			'error' => $error,
		];
	}
}
