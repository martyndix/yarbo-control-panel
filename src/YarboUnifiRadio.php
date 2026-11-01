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
        if ($station === null && $stationId === 'announce') {
            $clip = trim((string) ($input['clip_path'] ?? $this->loadState()['station_url'] ?? ''));
            if ($clip !== '' && is_file($clip)) {
                return $this->playLocalClip($clip, $input, 'Announce');
            }
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

        return $this->startStreams(
            $ffmpeg,
            (string) $station['url'],
            false,
            (string) $station['id'],
            (string) $station['name'],
            $targets,
            $volume,
            null,
        );
    }

    /**
     * Play a short browser-recorded clip to the ticked speakers (talkback).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $upload from $_FILES['audio']
     * @return array<string, mixed>
     */
    public function playAnnounce(array $input, array $upload): array
    {
        if (!$this->unifi->speakersEnabled()) {
            return ['ok' => false, 'error' => 'Speakers are turned off in Settings → UniFi'];
        }
        $ffmpeg = $this->unifi->ffmpegPath();
        if ($ffmpeg === '') {
            return ['ok' => false, 'error' => 'ffmpeg is not installed on this panel'];
        }

        $targets = $this->normalizeTargetIds($input['targets'] ?? $input['speakers'] ?? []);
        if ($targets === []) {
            $rawTargets = $input['targets'] ?? null;
            if (is_string($rawTargets) && $rawTargets !== '') {
                $decoded = json_decode($rawTargets, true);
                $targets = $this->normalizeTargetIds(is_array($decoded) ? $decoded : []);
            }
        }
        if ($targets === []) {
            return ['ok' => false, 'error' => 'Pick at least one speaker'];
        }

        $saved = $this->storeAnnounceUpload($upload);
        if (!($saved['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($saved['error'] ?? 'Could not save recording')];
        }
        $clipPath = (string) $saved['path'];

        $volume = array_key_exists('volume', $input)
            ? max(0, min(100, (int) $input['volume']))
            : max(0, min(100, (int) ($this->loadState()['volume'] ?? 60)));

        return $this->playLocalClip($clipPath, [
            'targets' => $targets,
            'volume' => $volume,
        ], 'Announce');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function playLocalClip(string $clipPath, array $input, string $name): array
    {
        $ffmpeg = $this->unifi->ffmpegPath();
        if ($ffmpeg === '') {
            return ['ok' => false, 'error' => 'ffmpeg is not installed on this panel'];
        }
        if (!is_file($clipPath)) {
            return ['ok' => false, 'error' => 'Recording file missing'];
        }
        $targets = $this->normalizeTargetIds($input['targets'] ?? $input['speakers'] ?? []);
        if ($targets === []) {
            return ['ok' => false, 'error' => 'Pick at least one speaker'];
        }
        $volume = array_key_exists('volume', $input)
            ? max(0, min(100, (int) $input['volume']))
            : max(0, min(100, (int) ($this->loadState()['volume'] ?? 60)));

        return $this->startStreams(
            $ffmpeg,
            $clipPath,
            true,
            'announce',
            $name,
            $targets,
            $volume,
            $clipPath,
        );
    }

    /**
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private function startStreams(
        string $ffmpeg,
        string $contentUrl,
        bool $localFile,
        string $stationId,
        string $stationName,
        array $targets,
        int $volume,
        ?string $clipPath,
    ): array {
        $prevClip = (string) ($this->loadState()['clip_path'] ?? '');
        $this->stop(false);
        if ($prevClip !== '' && is_file($prevClip) && ($clipPath === null || $prevClip !== $clipPath)) {
            @unlink($prevClip);
        }

        // Best-effort Protect hardware volume (needs local UniFi OS admin). Playback
        // loudness is always applied in ffmpeg so the slider works without that.
        foreach ($targets as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        $streams = [];
        $errors = [];
        $started = 0;
        foreach ($targets as $cameraId) {
            $session = $this->unifi->createTalkbackSession($cameraId);
            if (!($session['ok'] ?? false)) {
                $errors[] = (string) ($session['error'] ?? 'talkback failed') . ' (' . $cameraId . ')';
                continue;
            }
            $pid = $this->spawnFfmpeg(
                $ffmpeg,
                $contentUrl,
                (string) ($session['url'] ?? ''),
                (string) ($session['codec'] ?? 'aac'),
                (int) ($session['sampling_rate'] ?? 22050),
                $cameraId,
                $volume,
                $localFile,
            );
            if ($pid <= 0) {
                $detail = $this->tailRadioLog($cameraId);
                $errors[] = 'Could not start audio for ' . $cameraId
                    . ($detail !== '' ? ' — ' . $detail : '');
                continue;
            }
            $started++;
            if ($this->pidAlive($pid)) {
                $streams[] = [
                    'camera_id' => $cameraId,
                    'pid' => $pid,
                    'talkback_url' => (string) ($session['url'] ?? ''),
                    'codec' => (string) ($session['codec'] ?? ''),
                ];
            }
        }

        $playing = $streams !== [];
        // Short announce clips may finish before we return; still count as ok if ffmpeg started.
        $ok = $localFile ? $started > 0 : $playing;
        $playError = $ok
            ? ($errors !== [] ? implode('; ', $errors) : null)
            : ($errors !== [] ? implode('; ', $errors) : 'No speakers started');
        $state = [
            'playing' => $playing,
            'station_id' => $stationId,
            'station_name' => $stationName,
            'station_url' => $contentUrl,
            'clip_path' => $localFile ? $clipPath : null,
            'volume' => $volume,
            'streams' => $streams,
            // Remember the speakers the user ticked, even if some failed to start.
            'targets' => $targets,
            'started_at' => $ok ? time() : null,
            'error' => $playError,
        ];
        $this->writeState($state);

        return [
            'ok' => $ok,
            'error' => $ok ? null : ($state['error'] ?? 'Play failed'),
            'warning' => $ok && $errors !== [] ? implode('; ', $errors) : null,
            'radio' => $this->status(),
        ];
    }

    /**
     * @param array<string, mixed> $upload
     * @return array{ok: bool, path?: string, error?: string}
     */
    private function storeAnnounceUpload(array $upload): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Recording upload failed'];
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Recording upload missing'];
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > 3_000_000) {
            return ['ok' => false, 'error' => 'Recording must be under 3 MB (keep it under ~30 seconds)'];
        }
        $mime = strtolower(trim((string) ($upload['type'] ?? '')));
        $name = strtolower((string) ($upload['name'] ?? ''));
        $allowed = [
            'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg',
            'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/wave',
            'audio/x-m4a', 'audio/aac',
        ];
        $ext = 'webm';
        if (str_contains($mime, 'ogg') || str_ends_with($name, '.ogg') || str_ends_with($name, '.opus')) {
            $ext = 'ogg';
        } elseif (str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_ends_with($name, '.m4a') || str_ends_with($name, '.mp4')) {
            $ext = 'm4a';
        } elseif (str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') || str_ends_with($name, '.mp3')) {
            $ext = 'mp3';
        } elseif (str_contains($mime, 'wav') || str_ends_with($name, '.wav')) {
            $ext = 'wav';
        } elseif (str_contains($mime, 'aac') || str_ends_with($name, '.aac')) {
            $ext = 'aac';
        }
        if ($mime !== '' && !in_array($mime, $allowed, true)
            && !str_starts_with($mime, 'audio/') && $mime !== 'video/webm' && $mime !== 'application/ogg') {
            return ['ok' => false, 'error' => 'Unsupported recording format'];
        }

        $dir = $this->projectRoot . '/data/unifi-announce';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create announce folder'];
        }
        $path = $dir . '/clip-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $path)) {
            return ['ok' => false, 'error' => 'Could not save recording'];
        }

        return ['ok' => true, 'path' => $path];
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
        $clip = trim((string) ($state['clip_path'] ?? ''));
        // Keep the last announce file while Idle so volume changes can replay it;
        // only delete when clearing after a fresh stop request with clearError.
        if ($clearError && $clip !== '' && is_file($clip)) {
            // Leave file for a short window — cleaned on next play/announce.
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
        $state = $this->loadState();
        $wasPlaying = !empty($state['playing']) && is_array($state['streams']) && $state['streams'] !== [];
        $stationId = isset($state['station_id']) ? (string) $state['station_id'] : '';
        $state['volume'] = $volume;
        if ($targets !== []) {
            $state['targets'] = $targets;
        }
        $this->writeState($state);

        // Hardware volume is optional; stream volume is applied by restarting play.
        foreach ($targets as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        if ($wasPlaying && $stationId !== '' && $targets !== []) {
            $replay = $this->play([
                'station_id' => $stationId,
                'targets' => $targets,
                'volume' => $volume,
            ]);

            return [
                'ok' => (bool) ($replay['ok'] ?? false),
                'error' => $replay['error'] ?? null,
                'warning' => $replay['warning'] ?? null,
                'volume' => $volume,
                'radio' => $replay['radio'] ?? $this->status(),
            ];
        }

        return [
            'ok' => true,
            'error' => null,
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
        int $volume = 60,
        bool $localFile = false,
    ): int {
        $talkbackUrl = trim($talkbackUrl);
        if ($talkbackUrl === '') {
            return 0;
        }
        $sampleRate = $sampleRate > 0 ? $sampleRate : 22050;
        $codec = strtolower(trim($codec));
        $volume = max(0, min(100, $volume));
        // Linear gain in the stream so the UI slider works without Protect hardware volume.
        $gain = number_format($volume / 100, 4, '.', '');
        $volFilter = escapeshellarg('volume=' . $gain);
        $logDir = $this->projectRoot . '/data';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $log = $this->radioLogPath($cameraId);
        @file_put_contents($log, '');

        $input = escapeshellarg($contentUrl);
        $ff = escapeshellarg($ffmpeg);
        $logEsc = escapeshellarg($log);
        // Icecast/HLS drop briefly; reconnect so Play does not die after a few seconds.
        // Local announce clips skip reconnect flags (they confuse file inputs).
        $httpIn = $localFile
            ? ''
            : '-reconnect 1 -reconnect_streamed 1 -reconnect_on_network_error 1'
                . ' -reconnect_delay_max 5 -rw_timeout 15000000';

        $parsed = parse_url($talkbackUrl);
        $host = (string) ($parsed['host'] ?? '');
        $port = (int) ($parsed['port'] ?? 7004);
        if ($host === '') {
            return 0;
        }

        $inFlags = trim($httpIn . ' -re');
        if ($codec === 'opus') {
            $out = escapeshellarg($talkbackUrl);
            $cmd = "{$ff} -nostdin -hide_banner -loglevel warning {$inFlags} -i {$input}"
                . " -vn -ac 1 -ar {$sampleRate} -af {$volFilter} -c:a libopus -application lowdelay -f rtp {$out}";
        } else {
            // AAC talkback: ADTS over UDP to the camera (same as Home Assistant / go2rtc).
            // Avoid MPEG-TS packet sizing on this ADTS path — it makes ffmpeg exit immediately.
            $udp = escapeshellarg('udp://' . $host . ':' . $port);
            $cmd = "{$ff} -nostdin -hide_banner -loglevel warning {$inFlags} -i {$input}"
                . " -vn -ac 1 -ar {$sampleRate} -af {$volFilter} -c:a aac -profile:a aac_low -b:a 32k -f adts {$udp}";
        }

        $full = 'nohup ' . $cmd . ' > ' . $logEsc . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($full));
        if ($pid <= 1) {
            return 0;
        }
        // Talkback dies if the encoder never sends; catch immediate ffmpeg exits.
        // Short announce clips may finish within this window — treat clean exits as ok.
        usleep($localFile ? 200000 : 500000);
        if (!$this->pidAlive($pid)) {
            if ($localFile && !$this->radioLogLooksFailed($cameraId)) {
                return $pid;
            }

            return 0;
        }

        return $pid;
    }

    private function radioLogLooksFailed(string $cameraId): bool
    {
        $path = $this->radioLogPath($cameraId);
        if (!is_file($path)) {
            return false;
        }
        $raw = strtolower((string) @file_get_contents($path));
        if ($raw === '') {
            return false;
        }
        foreach (['error', 'invalid data', 'does not contain', 'conversion failed', 'no such file'] as $needle) {
            if (str_contains($raw, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function radioLogPath(string $cameraId): string
    {
        return $this->projectRoot . '/data/unifi-radio-'
            . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $cameraId) . '.log';
    }

    private function tailRadioLog(string $cameraId): string
    {
        $path = $this->radioLogPath($cameraId);
        if (!is_file($path)) {
            return '';
        }
        $raw = trim((string) @file_get_contents($path));
        if ($raw === '') {
            return '';
        }
        $lines = preg_split('/\R/', $raw) ?: [];
        $last = trim((string) end($lines));
        $last = preg_replace('/\s+/', ' ', $last) ?? $last;
        if ($last === '') {
            return '';
        }

        return strlen($last) > 160 ? substr($last, 0, 157) . '…' : $last;
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
            'clip_path' => null,
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
