<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Protect talkback radio → camera/reader speakers (HA-style media_player).
 */
final class YarboUnifiRadio
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly YarboUnifi $unifi,
    ) {
    }

    public function defaultsPath(): string
    {
        return $this->projectRoot . '/src/defaults/unifi-radio-stations.json';
    }

    public function stationsPath(): string
    {
        return $this->projectRoot . '/data/unifi-radio-stations.json';
    }

    public function statePath(): string
    {
        return $this->projectRoot . '/data/unifi-radio-state.json';
    }

    /**
     * @return list<array{id: string, name: string, url: string, format: string}>
     */
    public function defaultStations(): array
    {
        return $this->normalizeStations($this->readJsonFile($this->defaultsPath()));
    }

    /**
     * @return list<array{id: string, name: string, url: string, format: string}>
     */
    public function stations(): array
    {
        if (!is_file($this->stationsPath())) {
            $defaults = $this->defaultStations();
            $this->writeStations($defaults);

            return $defaults;
        }

        return $this->normalizeStations($this->readJsonFile($this->stationsPath()));
    }

    /**
     * @param list<mixed>|array<string, mixed> $raw
     * @return list<array{id: string, name: string, url: string, format: string}>
     */
    public function saveStations(array $raw): array
    {
        $stations = $this->normalizeStations($raw);
        $this->writeStations($stations);

        return $stations;
    }

    /**
     * @return list<array{id: string, name: string, url: string, format: string}>
     */
    public function restoreDefaultStations(): array
    {
        $defaults = $this->defaultStations();
        $this->writeStations($defaults);

        return $defaults;
    }

    /**
     * @return array{playing: bool, station_id: ?string, station_name: ?string, targets: list<string>, active_targets: list<string>, volume: int, error: ?string, started_at: ?int}
     */
    public function status(): array
    {
        $state = $this->loadState();
        $alive = [];
        foreach ($state['streams'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['pid'] ?? 0);
            if ($pid > 0 && $this->pidAlive($pid)) {
                $alive[] = $row;
            }
        }
        if ($alive === [] && ($state['playing'] ?? false)) {
            $state['playing'] = false;
            $state['streams'] = [];
            $state['error'] = $state['error'] ?? null;
            $this->writeState($state);
        } elseif (count($alive) !== count($state['streams'])) {
            $state['streams'] = $alive;
            $state['playing'] = $alive !== [];
            $this->writeState($state);
        }

        $aliveIds = array_values(array_map(
            static fn (array $r): string => (string) ($r['camera_id'] ?? ''),
            $alive
        ));
        $savedTargets = [];
        if (is_array($state['targets'] ?? null)) {
            foreach ($state['targets'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $savedTargets[] = $id;
                }
            }
        }
        // Keep last chosen speakers for the UI while Idle; only fall back to live
        // streams when nothing was saved yet.
        $targets = $savedTargets !== [] ? array_values(array_unique($savedTargets)) : $aliveIds;

        return [
            'playing' => !empty($state['playing']) && $alive !== [],
            'station_id' => isset($state['station_id']) ? (string) $state['station_id'] : null,
            'station_name' => isset($state['station_name']) ? (string) $state['station_name'] : null,
            'targets' => $targets,
            'active_targets' => $aliveIds,
            'volume' => max(0, min(100, (int) ($state['volume'] ?? 60))),
            'error' => isset($state['error']) && is_string($state['error']) && $state['error'] !== ''
                ? $state['error']
                : null,
            'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function play(array $input): array
    {
        if (!$this->unifi->speakersEnabled()) {
            return ['ok' => false, 'error' => 'Speakers are turned off in Settings → UniFi'];
        }
        $ffmpeg = $this->unifi->ffmpegPath();
        if ($ffmpeg === '') {
            return ['ok' => false, 'error' => 'ffmpeg is not installed on this panel'];
        }

        $stationId = trim((string) ($input['station_id'] ?? $input['station'] ?? ''));
        $customUrl = trim((string) ($input['url'] ?? ''));
        $station = null;
        foreach ($this->stations() as $row) {
            if ($row['id'] === $stationId) {
                $station = $row;
                break;
            }
        }
        if ($station === null && $customUrl !== '') {
            $station = [
                'id' => 'custom',
                'name' => 'Custom stream',
                'url' => $customUrl,
                'format' => $this->guessFormat($customUrl),
            ];
        }
        if ($station === null) {
            return ['ok' => false, 'error' => 'Pick a radio station'];
        }

        $targets = $this->normalizeTargetIds($input['targets'] ?? $input['speakers'] ?? []);
        if ($targets === []) {
            return ['ok' => false, 'error' => 'Pick at least one speaker'];
        }

        $volume = array_key_exists('volume', $input)
            ? max(0, min(100, (int) $input['volume']))
            : max(0, min(100, (int) ($this->loadState()['volume'] ?? 60)));

        $this->stop(false);

        $streams = [];
        $errors = [];
        foreach ($targets as $cameraId) {
            $vol = $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
            if (!($vol['ok'] ?? false)) {
                // Volume is best-effort; still try talkback.
                $errors[] = (string) ($vol['error'] ?? 'volume failed') . ' (' . $cameraId . ')';
            }
            $session = $this->unifi->createTalkbackSession($cameraId);
            if (!($session['ok'] ?? false)) {
                $errors[] = (string) ($session['error'] ?? 'talkback failed') . ' (' . $cameraId . ')';
                continue;
            }
            $pid = $this->spawnFfmpeg(
                $ffmpeg,
                (string) $station['url'],
                (string) ($session['url'] ?? ''),
                (string) ($session['codec'] ?? 'aac'),
                (int) ($session['sampling_rate'] ?? 22050),
                $cameraId
            );
            if ($pid <= 0) {
                $errors[] = 'Could not start audio for ' . $cameraId;
                continue;
            }
            $streams[] = [
                'camera_id' => $cameraId,
                'pid' => $pid,
                'talkback_url' => (string) ($session['url'] ?? ''),
                'codec' => (string) ($session['codec'] ?? ''),
            ];
        }

        $playing = $streams !== [];
        $state = [
            'playing' => $playing,
            'station_id' => $station['id'],
            'station_name' => $station['name'],
            'station_url' => $station['url'],
            'volume' => $volume,
            'streams' => $streams,
            // Remember the speakers the user ticked, even if some failed to start.
            'targets' => $targets,
            'started_at' => $playing ? time() : null,
            'error' => $playing
                ? ($errors !== [] ? implode('; ', $errors) : null)
                : ($errors !== [] ? implode('; ', $errors) : 'No speakers started'),
        ];
        $this->writeState($state);

        return [
            'ok' => $playing,
            'error' => $playing ? null : ($state['error'] ?? 'Play failed'),
            'warning' => $playing && $errors !== [] ? implode('; ', $errors) : null,
            'radio' => $this->status(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(bool $clearError = true): array
    {
        $state = $this->loadState();
        foreach ($state['streams'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['pid'] ?? 0);
            if ($pid > 1) {
                $this->killPidTree($pid);
            }
        }
        $state['playing'] = false;
        $state['streams'] = [];
        $state['started_at'] = null;
        if ($clearError) {
            $state['error'] = null;
        }
        $this->writeState($state);

        return ['ok' => true, 'radio' => $this->status()];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setVolume(array $input): array
    {
        $volume = max(0, min(100, (int) ($input['volume'] ?? 60)));
        $targets = $this->normalizeTargetIds($input['targets'] ?? $input['speakers'] ?? []);
        if ($targets === []) {
            $status = $this->status();
            $targets = !empty($status['active_targets'])
                ? $status['active_targets']
                : ($status['targets'] ?? []);
        }
        $errors = [];
        foreach ($targets as $cameraId) {
            $res = $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
            if (!($res['ok'] ?? false)) {
                $errors[] = (string) ($res['error'] ?? 'failed') . ' (' . $cameraId . ')';
            }
        }
        $state = $this->loadState();
        $state['volume'] = $volume;
        if ($targets !== []) {
            $state['targets'] = $targets;
        }
        $this->writeState($state);

        return [
            'ok' => $errors === [],
            'error' => $errors !== [] ? implode('; ', $errors) : null,
            'volume' => $volume,
            'radio' => $this->status(),
        ];
    }

    /**
     * @return array{stations: list<array{id: string, name: string, url: string, format: string}>, speakers: list<array<string, mixed>>, radio: array<string, mixed>, speakers_enabled: bool}
     */
    public function dashboardSlice(): array
    {
        return [
            'stations' => $this->stations(),
            'speakers' => $this->unifi->speakerCameras(),
            'radio' => $this->status(),
            'speakers_enabled' => $this->unifi->speakersEnabled(),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{id: string, name: string, url: string, format: string}>
     */
    private function normalizeStations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $list = array_is_list($raw) ? $raw : ($raw['stations'] ?? []);
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($name === '' || $url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'station');
                $id = trim($id, '-') ?: 'station';
            }
            $base = $id;
            $n = 2;
            while (isset($seen[$id])) {
                $id = $base . '-' . $n;
                $n++;
            }
            $seen[$id] = true;
            $format = strtolower(trim((string) ($row['format'] ?? '')));
            if (!in_array($format, ['mp3', 'aac', 'hls'], true)) {
                $format = $this->guessFormat($url);
            }
            $out[] = [
                'id' => $id,
                'name' => function_exists('mb_substr') ? mb_substr($name, 0, 64) : substr($name, 0, 64),
                'url' => $url,
                'format' => $format,
            ];
        }

        return $out;
    }

    private function guessFormat(string $url): string
    {
        $lower = strtolower($url);
        if (str_contains($lower, '.m3u8') || str_contains($lower, 'hls')) {
            return 'hls';
        }
        if (str_contains($lower, 'aac') || str_contains($lower, '.aac')) {
            return 'aac';
        }

        return 'mp3';
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeTargetIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $parsed = YarboUnifi::parseHomeId($id);
            if ($parsed !== null && $parsed['kind'] === YarboUnifi::KIND_CAMERA) {
                $id = $parsed['native_id'];
            }
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function spawnFfmpeg(
        string $ffmpeg,
        string $contentUrl,
        string $talkbackUrl,
        string $codec,
        int $sampleRate,
        string $cameraId,
    ): int {
        $talkbackUrl = trim($talkbackUrl);
        if ($talkbackUrl === '') {
            return 0;
        }
        $sampleRate = $sampleRate > 0 ? $sampleRate : 22050;
        $codec = strtolower(trim($codec));
        $logDir = $this->projectRoot . '/data';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $log = $logDir . '/unifi-radio-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $cameraId) . '.log';

        $input = escapeshellarg($contentUrl);
        $ff = escapeshellarg($ffmpeg);
        $logEsc = escapeshellarg($log);

        if ($codec === 'opus') {
            $out = escapeshellarg($talkbackUrl);
            $cmd = "{$ff} -nostdin -hide_banner -loglevel error -re -i {$input} -vn -ac 1 -ar {$sampleRate}"
                . " -c:a libopus -application lowdelay -f rtp {$out}";
        } else {
            // AAC / default: ADTS over UDP (works for many Protect talkback endpoints).
            $parsed = parse_url($talkbackUrl);
            $host = (string) ($parsed['host'] ?? '');
            $port = (int) ($parsed['port'] ?? 7004);
            if ($host === '') {
                return 0;
            }
            $udp = escapeshellarg('udp://' . $host . ':' . $port . '?pkt_size=188');
            $cmd = "{$ff} -nostdin -hide_banner -loglevel error -re -i {$input} -vn -ac 1 -ar {$sampleRate}"
                . " -c:a aac -b:a 64k -f adts {$udp}";
        }

        $full = 'nohup ' . $cmd . ' > ' . $logEsc . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($full));

        return $pid > 1 ? $pid : 0;
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = trim((string) shell_exec('kill -0 ' . $pid . ' 2>/dev/null; echo $?'));

        return $out === '0';
    }

    private function killPidTree(int $pid): void
    {
        if ($pid <= 1) {
            return;
        }
        @shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
        usleep(150000);
        if ($this->pidAlive($pid)) {
            @shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        $defaults = [
            'playing' => false,
            'station_id' => null,
            'station_name' => null,
            'station_url' => null,
            'volume' => 60,
            'streams' => [],
            'targets' => [],
            'started_at' => null,
            'error' => null,
        ];
        $decoded = $this->readJsonFile($this->statePath());
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, $decoded);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->statePath(), $json . "\n", LOCK_EX);
        }
    }

    /**
     * @param list<array{id: string, name: string, url: string, format: string}> $stations
     */
    private function writeStations(array $stations): void
    {
        $dir = $this->projectRoot . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($stations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($this->stationsPath(), $json . "\n", LOCK_EX);
        }
    }

    private function readJsonFile(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }
}
