<?php

declare(strict_types=1);

namespace ReinfyTeam\BedrockVoiceChat\http;

use pocketmine\plugin\PluginBase;
use pocketmine\thread\ThreadSafeArray;
use ReinfyTeam\BedrockVoiceChat\BVC;
use ReinfyTeam\BedrockVoiceChat\thread\HttpWorkerThread;

/**
 * Asynchronous HTTP client backed by a dedicated worker thread.
 *
 * Every request is dispatched to HttpWorkerThread (a dedicated background
 * thread). Completion callbacks run on the main thread via a polling task,
 * so they are free to use the PocketMine API. This never blocks the server's
 * main thread and can handle 100+ players without stalling.
 */
final class HttpClient{

    /** @var array<int, callable(int, string, string): void> */
    private static array $pendingCallbacks = [];
    private static int $nextSeq = 0;

    private BVC $plugin;
    private ThreadSafeArray $toThread;
    private ThreadSafeArray $fromThread;
    private ?HttpWorkerThread $worker = null;
    private ?\pocketmine\scheduler\RepeatingTask $poller = null;

    public function __construct(BVC $plugin){
        $this->plugin = $plugin;
    }

    public function start(): void{
        $this->toThread = new ThreadSafeArray();
        $this->fromThread = new ThreadSafeArray();

        $this->worker = new HttpWorkerThread($this->toThread, $this->fromThread);
        $this->worker->start();

        // Poll results back on the main thread every tick
        $this->poller = new class($this) extends \pocketmine\scheduler\RepeatingTask{
            private HttpClient $client;
            public function __construct(HttpClient $client){
                $this->client = $client;
            }
            public function execute(int $currentTick): void{
                $this->client->pollResults();
            }
        };
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->poller, 1);
    }

    public function stop(): void{
        if($this->poller !== null){
            $this->plugin->getScheduler()->cancelTask($this->poller->getTaskId());
            $this->poller = null;
        }
        if($this->worker !== null){
            $this->worker->stop();
            $this->worker->join();
            $this->worker = null;
        }
    }

    /**
     * @param array<string, string> $headers
     * @param callable(int, string, string): void $callback receives HTTP status, body and cURL error
     */
    public function request(string $method, string $url, array $headers, ?string $body, callable $callback, int $timeout = 5): void{
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

    private function pollResults(): void{
        while(($result = $this->fromThread->shift()) !== null){
            $seq = (int) ($result['seq'] ?? 0);
            $callback = self::$pendingCallbacks[$seq] ?? null;
            unset(self::$pendingCallbacks[$seq]);
            if($callback !== null){
                $status = (int) ($result['httpCode'] ?? 0);
                $error = (string) ($result['error'] ?? '');
                $this->plugin->debug("[HttpClient] Finished request #{$seq}: status={$status}" . ($error !== '' ? " error={$error}" : ""));
                try{
                    ($callback)(
                        $status,
                        (string) ($result['body'] ?? ''),
                        $error
                    );
                }catch(\Throwable $e){
                    $this->plugin->getLogger()->error('BVC HTTP callback failed: ' . $e->getMessage());
                }
            }
        }
    }
}
