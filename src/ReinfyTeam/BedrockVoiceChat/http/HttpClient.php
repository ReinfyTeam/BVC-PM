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

namespace ReinfyTeam\BedrockVoiceChat\http;

use gameparrot\libpmcurl\Curl;
use pocketmine\utils\InternetRequestResult;
use ReinfyTeam\BedrockVoiceChat\BVC;

/**
 * Asynchronous HTTP client backed by libpmcurl (curl_multi).
 *
 * Every request is dispatched through libpmcurl's dedicated background thread
 * using curl_multi, so multiple requests run in parallel with keep-alive
 * connections. Completion callbacks run on the main thread via PocketMine's
 * tick sleeper, so they are free to use the PocketMine API. This never blocks
 * the server's main thread.
 */
final class HttpClient {
	private BVC $plugin;

	/** @var array<int, callable(int, string, string): void> */
	private array $pendingCallbacks = [];

	private int $nextSeq = 0;

	public function __construct(BVC $plugin) {
		$this->plugin = $plugin;
		Curl::getInstance(); // Initialize the libpmcurl singleton / background thread
	}

	/**
	 * @param array<string, string> $headers
	 * @param callable(int, string, string): void $callback receives HTTP status, body and cURL error
	 */
	public function request(string $method, string $url, array $headers, ?string $body, callable $callback, int $timeout = 5) : void {
		$seq = ++$this->nextSeq;
		$this->pendingCallbacks[$seq] = $callback;

		$this->plugin->debug("debug_http_dispatched", ['seq' => (string) $seq, 'method' => $method, 'url' => $url, 'timeout' => (string) $timeout]);

		if ($method === 'GET' || $method === 'HEAD') {
			Curl::getInstance()->getRequest($url, function(?InternetRequestResult $result, ?string $error) use ($seq) : void {
				$this->handleResponse($seq, $result, $error);
			}, $headers, $timeout);
		} else {
			// POST, PUT, PATCH, DELETE, and any other method
			Curl::getInstance()->postRequest($url, function(?InternetRequestResult $result, ?string $error) use ($seq) : void {
				$this->handleResponse($seq, $result, $error);
			}, $headers, $body ?? '', $timeout);
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @param callable(int, string, string): void $callback
	 */
	public function get(string $url, array $headers, callable $callback, int $timeout = 5) : void {
		$this->request('GET', $url, $headers, null, $callback, $timeout);
	}

	/**
	 * @param array<string, string> $headers
	 * @param callable(int, string, string): void $callback
	 */
	public function post(string $url, array $headers, ?string $body, callable $callback, int $timeout = 5) : void {
		$this->request('POST', $url, $headers, $body, $callback, $timeout);
	}

	/**
	 * @param array<string, string> $headers
	 * @param callable(int, string, string): void $callback
	 */
	public function delete(string $url, array $headers, callable $callback, int $timeout = 5) : void {
		$this->request('DELETE', $url, $headers, null, $callback, $timeout);
	}

	/**
	 * Handle a completed libpmcurl response and invoke the stored callback.
	 */
	private function handleResponse(int $seq, ?InternetRequestResult $result, ?string $error) : void {
		$callback = $this->pendingCallbacks[$seq] ?? null;
		unset($this->pendingCallbacks[$seq]);

		if ($callback === null) {
			return;
		}

		if ($result === null) {
			// Network error
			$callback(0, '', $error ?? 'Unknown error');
			return;
		}

		$status = $result->getCode();
		$body = $result->getBody();
		$callback($status, $body, '');
	}

	public function stop() : void {
		$this->pendingCallbacks = [];
	}
}
