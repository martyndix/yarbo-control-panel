<?php

declare(strict_types=1);

namespace Yarbo;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

final class YarboMqtt
{
    private MqttClient $client;
    private string $serial;
    private ?array $lastMessage = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $serial,
    ) {
        $this->serial = $serial;
        $clientId = 'yarbo-php-' . bin2hex(random_bytes(4));
        $this->client = new MqttClient($this->host, $this->port, $clientId);
    }

    public function connect(): void
    {
        $settings = (new ConnectionSettings())
            ->setKeepAliveInterval(30)
            ->setConnectTimeout(5)
            ->setSocketTimeout(5);

        $this->client->connect($settings, true);
    }

    public function disconnect(): void
    {
        if ($this->client->isConnected()) {
            $this->client->disconnect();
        }
    }

    public function requestTelemetry(int $timeoutSeconds = 5): ?array
    {
        $feedbackTopic = $this->topic('device', 'data_feedback');
        $deviceMsgTopic = $this->topic('device', 'DeviceMSG');
        $this->lastMessage = null;

        $handler = function (string $topic, string $message): void {
            $telemetry = self::extractTelemetry(YarboCodec::decode($message));
            if ($telemetry !== null) {
                $this->lastMessage = $telemetry;
            }
        };

        $this->client->subscribe($feedbackTopic, $handler, 0);
        $this->client->subscribe($deviceMsgTopic, $handler, 0);

        $this->waitForSubscriptions(0.3);
        $this->publish('get_device_msg', []);

        $loopStarted = microtime(true);
        $deadline = $loopStarted + $timeoutSeconds;
        while ($this->lastMessage === null && microtime(true) < $deadline) {
            $this->client->loopOnce($loopStarted, true);
        }

        return $this->lastMessage;
    }

    /**
     * Enter manual mode and/or send velocity. Optimized for repeated drive commands.
     */
    public function sendDrive(float $linear, float $angular, bool $enterManual = false): void
    {
        $this->acquireController();

        if ($enterManual) {
            $this->publish('set_working_state', ['state' => 'manual']);
        }

        $this->publish('cmd_vel', ['vel' => $linear, 'rev' => $angular]);
        $this->briefLoop(0.15);
    }

    public function sendCommand(string $cmd, array $payload = [], bool $acquireController = true): void
    {
        if ($acquireController) {
            $this->acquireController();
        }

        $this->publish($cmd, $payload);
        $this->briefLoop(1.0);
    }

    /**
     * Publish command variants (firmware differs on payload shape).
     *
     * @param array<int, array{cmd: string, payload: array<string, mixed>}> $variants
     */
    public function sendCommandVariants(array $variants, bool $acquireController = true): string
    {
        if ($variants === []) {
            throw new \InvalidArgumentException('At least one command variant is required');
        }

        if ($acquireController) {
            $this->acquireController();
        }

        $last = $variants[count($variants) - 1];
        foreach ($variants as $variant) {
            $this->publish($variant['cmd'], $variant['payload']);
            $last = $variant;
        }

        $this->briefLoop(1.0);

        return $last['cmd'];
    }

    /**
     * Publish a command and wait for matching data_feedback response.
     *
     * @return array<string, mixed>|null Full decoded feedback envelope, or null on timeout.
     */
    public function requestDataFeedback(
        string $cmd,
        array $payload = [],
        float $timeoutSeconds = 5.0,
        bool $acquireController = true
    ): ?array {
        $feedbackTopic = $this->topic('device', 'data_feedback');
        /** @var array<string, mixed>|null $feedbackResponse */
        $feedbackResponse = null;

        $this->client->subscribe($feedbackTopic, function (string $topic, string $message) use ($cmd, &$feedbackResponse): void {
            $decoded = YarboCodec::decode($message);
            if (($decoded['topic'] ?? '') === $cmd) {
                $feedbackResponse = $decoded;
            }
        }, 0);

        $this->waitForSubscriptions(0.3);

        if ($acquireController) {
            $this->acquireController();
        }

        $this->publish($cmd, $payload);

        $loopStarted = microtime(true);
        $deadline = $loopStarted + $timeoutSeconds;
        while ($feedbackResponse === null && microtime(true) < $deadline) {
            $this->client->loopOnce($loopStarted, true);
        }

        if (!is_array($feedbackResponse)) {
            return null;
        }

        return $feedbackResponse;
    }

    /**
     * Publish multiple read commands on one connection with a single controller acquire.
     *
     * @param array<string, float> $commands Command name => per-command timeout seconds
     * @return array<string, array<string, mixed>|null>
     */
    public function requestDataFeedbackBatch(array $commands, bool $acquireController = true): array
    {
        if ($commands === []) {
            return [];
        }

        $feedbackTopic = $this->topic('device', 'data_feedback');
        /** @var array<string, array<string, mixed>|null> $responses */
        $responses = [];
        foreach (array_keys($commands) as $cmd) {
            $responses[$cmd] = null;
        }

        $this->client->subscribe($feedbackTopic, function (string $topic, string $message) use (&$responses): void {
            $decoded = YarboCodec::decode($message);
            $cmd = (string) ($decoded['topic'] ?? '');
            if ($cmd !== '' && array_key_exists($cmd, $responses) && $responses[$cmd] === null) {
                $responses[$cmd] = $decoded;
            }
        }, 0);

        $this->waitForSubscriptions(0.4);

        if ($acquireController) {
            $this->acquireController();
        }

        foreach ($commands as $cmd => $timeoutSeconds) {
            if ($responses[$cmd] !== null) {
                continue;
            }

            $this->publish($cmd, []);

            $loopStarted = microtime(true);
            $deadline = $loopStarted + $timeoutSeconds;
            while ($responses[$cmd] === null && microtime(true) < $deadline) {
                $this->client->loopOnce($loopStarted, true);
            }
        }

        return $responses;
    }

    private function acquireController(): void
    {
        $feedbackTopic = $this->topic('device', 'data_feedback');
        $this->lastMessage = null;
        $this->client->subscribe($feedbackTopic, function (string $topic, string $message): void {
            $decoded = YarboCodec::decode($message);
            if (($decoded['topic'] ?? '') === 'get_controller' && ($decoded['state'] ?? 1) === 0) {
                $this->lastMessage = $decoded;
            }
        }, 0);

        $this->waitForSubscriptions(0.3);
        $this->publish('get_controller', []);

        $loopStarted = microtime(true);
        $deadline = $loopStarted + 3;
        while ($this->lastMessage === null && microtime(true) < $deadline) {
            $this->client->loopOnce($loopStarted, true);
        }
    }

    private function briefLoop(float $seconds): void
    {
        $loopStarted = microtime(true);
        $deadline = $loopStarted + $seconds;
        while (microtime(true) < $deadline) {
            $this->client->loopOnce($loopStarted, true);
        }
    }

    private function publish(string $cmd, array $payload): void
    {
        $topic = $this->topic('app', $cmd);
        $this->client->publish($topic, YarboCodec::encode($payload), 0);
    }

    private function topic(string $direction, string $leaf): string
    {
        return sprintf('snowbot/%s/%s/%s', $this->serial, $direction, $leaf);
    }

    private function waitForSubscriptions(float $seconds): void
    {
        $loopStarted = microtime(true);
        $deadline = $loopStarted + $seconds;
        while (microtime(true) < $deadline) {
            $this->client->loopOnce($loopStarted, true);
        }
    }

    /**
     * data_feedback wraps telemetry in a {topic, state, data} envelope;
     * DeviceMSG has BatteryMSG/StateMSG at the top level.
     */
    public static function extractTelemetry(array $decoded): ?array
    {
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $data = $decoded['data'];
            if (isset($data['BatteryMSG']) || isset($data['StateMSG'])) {
                return $data;
            }
        }

        if (isset($decoded['BatteryMSG']) || isset($decoded['StateMSG'])) {
            return $decoded;
        }

        return null;
    }

    private function looksLikeTelemetry(array $decoded): bool
    {
        return self::extractTelemetry($decoded) !== null;
    }
}
