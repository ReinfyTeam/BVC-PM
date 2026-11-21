<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\thread;

use pocketmine\thread\Thread;
use pocketmine\thread\ThreadSafeArray;
use ReinfyTeam\BedrockVoiceChat\http\HttpClient;
use ReinfyTeam\BedrockVoiceChat\util\Util;

/**
 * Dedicated background worker thread for all BVC HTTP requests.
 *
 * This thread runs cURL requests off the main server thread, ensuring that
 * network latency never blocks the game loop. Requests are submitted via
 * $toThread and results are returned via $fromThread. The main thread polls
 * $fromThread on a repeating task.
 *
 * @phpstan-import-type Request from HttpClient
 */
final class HttpWorkerThread extends Thread{

    /** @var array<string, mixed> */
    private array $requests = [];
    private bool $running = true;

    public function __construct(
        private ThreadSafeArray $toThread,
        private ThreadSafeArray $fromThread
    ){}

    public function onRun(): void{
        while($this->running){
            $data = $this->toThread->shift();
            if($data !== null){
                $result = $this->executeRequest($data);
                $this->fromThread[] = $result;
            }
            // Prevent 100% CPU usage in an idle loop
            usleep(50000); // 50ms
        }
    }

    public function stop(): void{
        $this->running = false;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function executeRequest(array $data): array{
        $seq = $data['seq'] ?? 0;
        $method = $data['method'] ?? 'GET';
        $url = $data['url'] ?? '';
        $headers = $data['headers'] ?? [];
        $body = $data['body'] ?? null;
        $timeout = $data['timeout'] ?? 5;

        $ch = curl_init($url);
        if($ch === false){
            return ['seq' => $seq, 'httpCode' => 0, 'body' => '', 'error' => 'curl_init() failed'];
        }

        $headerLines = [];
        foreach($headers as $name => $value){
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min(3, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'BVC-PMMP-Plugin/2.0.0'
        ]);
        if($body !== null){
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if($result === false){
            $error = curl_error($ch);
        }else{
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
