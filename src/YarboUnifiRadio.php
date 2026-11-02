<?php

declare(strict_types=1);

namespace Yarbo;

/**
 * Protect talkback radio → camera/reader speakers (HA-style media_player).
 */
final class YarboUnifiRadio
{
    /** Prevent status() → play() → stop() → status() from re-entering resume. */
    private bool $resumingRadio = false;

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
     * @return array{playing: bool, station_id: ?string, station_name: ?string, targets: list<string>, active_targets: list<string>, volume: int, error: ?string, started_at: ?int, ends_at: ?int}
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

        $endsAt = isset($state['ends_at']) ? (int) $state['ends_at'] : 0;
        $timedOut = $endsAt > 0 && time() >= $endsAt;
        // Finite clips (Announcement) must return to Idle when ffmpeg exits or ends_at passes.
        // If radio was playing before the announcement, resume that station.
        if ($timedOut || ($alive === [] && ($state['playing'] ?? false))) {
            if ($timedOut && $alive !== []) {
                foreach ($alive as $row) {
                    $pid = (int) ($row['pid'] ?? 0);
                    if ($pid > 1) {
                        $this->killPidTree($pid);
                    }
                }
            }
            $wasAnnounce = trim((string) ($state['station_id'] ?? '')) === 'announce';
            $resume = is_array($state['resume_after'] ?? null) ? $state['resume_after'] : null;
            $state['playing'] = false;
            $state['streams'] = [];
            $state['ends_at'] = null;
            $state['resume_after'] = null;
            $state['error'] = $state['error'] ?? null;
            $this->writeState($state);
            $alive = [];

            if (
                !$this->resumingRadio
                && $wasAnnounce
                && $this->resumeAfterLooksValid($resume)
            ) {
                $this->resumingRadio = true;
                try {
                    $resumeId = (string) $resume['station_id'];
                    $replay = $resumeId === 'airplay'
                        ? $this->playAirplay([
                            'targets' => $resume['targets'],
                            'volume' => $resume['volume'] ?? ($state['volume'] ?? 60),
                            'ensure_receiver' => false,
                        ])
                        : $this->play([
                            'station_id' => $resumeId,
                            'url' => (string) ($resume['url'] ?? ''),
                            'targets' => $resume['targets'],
                            'volume' => $resume['volume'] ?? ($state['volume'] ?? 60),
                        ]);
                    if (($replay['ok'] ?? false) && is_array($replay['radio'] ?? null)) {
                        return $replay['radio'];
                    }
                } finally {
                    $this->resumingRadio = false;
                }
                $state = $this->loadState();
            }
        } elseif (count($alive) !== count($state['streams'])) {
            $state['streams'] = $alive;
            $state['playing'] = $alive !== [];
            if ($alive === []) {
                $state['ends_at'] = null;
            }
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
            'ends_at' => isset($state['ends_at']) && (int) $state['ends_at'] > 0
                ? (int) $state['ends_at']
                : null,
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
        if ($stationId === 'airplay') {
            return $this->playAirplay($input);
        }
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
                return $this->playLocalClip($clip, $input, 'Announcement');
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
        ], 'Announcement');
    }

    /**
     * Bridge AirPlay FIFO PCM → Protect talkback (one ffmpeg, multi UDP).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function playAirplay(array $input): array
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
            $status = $this->status();
            $targets = $this->normalizeTargetIds($status['targets'] ?? []);
        }
        if ($targets === []) {
            return ['ok' => false, 'error' => 'Pick at least one speaker'];
        }

        $volume = array_key_exists('volume', $input)
            ? max(0, min(100, (int) $input['volume']))
            : max(0, min(100, (int) ($this->loadState()['volume'] ?? 60)));

        $airplay = new YarboPanelAirPlay($this->projectRoot);
        $ensureReceiver = !array_key_exists('ensure_receiver', $input)
            || YarboHub::asBool($input['ensure_receiver'] ?? true);
        if ($ensureReceiver) {
            $ensured = $airplay->ensure();
            if (!($ensured['ok'] ?? false) || empty($ensured['running'])) {
                return [
                    'ok' => false,
                    'error' => (string) ($ensured['error'] ?? $ensured['message'] ?? 'AirPlay receiver did not start'),
                    'airplay' => $ensured,
                ];
            }
        } elseif (!$airplay->status()['running']) {
            return [
                'ok' => false,
                'error' => 'AirPlay receiver is not running',
                'airplay' => $airplay->status(),
            ];
        }

        $fifo = $airplay->fifoPath();
        if (!file_exists($fifo)) {
            @mkdir(dirname($fifo), 0755, true);
            @posix_mkfifo($fifo, 0666);
            if (!file_exists($fifo)) {
                // Fallback when posix_mkfifo is missing.
                @shell_exec('mkfifo -m 666 ' . escapeshellarg($fifo) . ' 2>/dev/null');
            }
        }
        if (!file_exists($fifo)) {
            return ['ok' => false, 'error' => 'AirPlay audio FIFO missing'];
        }

        // Do not auto-resume Icecast after AirPlay; Announcement may still resume AirPlay.
        $prevClip = (string) ($this->loadState()['clip_path'] ?? '');
        $this->stop(false);
        if ($prevClip !== '' && is_file($prevClip)) {
            @unlink($prevClip);
        }

        foreach ($targets as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        $sessions = [];
        $errors = [];
        foreach ($targets as $cameraId) {
            $session = $this->unifi->createTalkbackSession($cameraId);
            if (!($session['ok'] ?? false)) {
                $errors[] = (string) ($session['error'] ?? 'talkback failed') . ' (' . $cameraId . ')';
                continue;
            }
            $sessions[] = [
                'camera_id' => $cameraId,
                'talkback_url' => (string) ($session['url'] ?? ''),
                'codec' => (string) ($session['codec'] ?? 'aac'),
                'sampling_rate' => (int) ($session['sampling_rate'] ?? 22050),
            ];
        }
        if ($sessions === []) {
            return [
                'ok' => false,
                'error' => $errors !== [] ? implode('; ', $errors) : 'No speakers started',
                'airplay' => $airplay->status(),
            ];
        }

        $displayName = (string) ($airplay->config()['name'] ?? 'Yarbo Speakers');
        $pid = $this->spawnAirplayFanout($ffmpeg, $fifo, $sessions, $volume);
        if ($pid <= 0) {
            return [
                'ok' => false,
                'error' => 'Could not start AirPlay → talkback bridge (see data/unifi-radio-airplay.log)',
                'airplay' => $airplay->status(),
            ];
        }

        $streams = [];
        foreach ($sessions as $row) {
            $streams[] = [
                'camera_id' => $row['camera_id'],
                'pid' => $pid,
                'talkback_url' => $row['talkback_url'],
                'codec' => $row['codec'],
            ];
        }
        $state = [
            'playing' => true,
            'station_id' => 'airplay',
            'station_name' => $displayName,
            'station_url' => $fifo,
            'clip_path' => null,
            'volume' => $volume,
            'streams' => $streams,
            'targets' => $targets,
            'started_at' => time(),
            'ends_at' => null,
            'resume_after' => null,
            'error' => $errors !== [] ? implode('; ', $errors) : null,
        ];
        $this->writeState($state);

        return [
            'ok' => true,
            'error' => null,
            'warning' => $errors !== [] ? implode('; ', $errors) : null,
            'radio' => $this->status(),
            'airplay' => $airplay->status(),
        ];
    }

    /**
     * Stop talkback bridge and optionally the AirPlay LAN receiver.
     *
     * @return array<string, mixed>
     */
    public function stopAirplay(bool $stopReceiver = true): array
    {
        $stop = $this->stop(true);
        $airplay = new YarboPanelAirPlay($this->projectRoot);
        $recv = $stopReceiver ? $airplay->stopReceiver() : $airplay->status();

        return [
            'ok' => (bool) ($stop['ok'] ?? false),
            'radio' => $stop['radio'] ?? $this->status(),
            'airplay' => $recv,
        ];
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

        // Capture the live station before stop() so we can restart it when the clip ends.
        $resumeAfter = $this->captureResumeAfter();

        return $this->startStreams(
            $ffmpeg,
            $clipPath,
            true,
            'announce',
            $name,
            $targets,
            $volume,
            $clipPath,
            $resumeAfter,
        );
    }

    /**
     * @return array{station_id: string, url: string, targets: list<string>, volume: int}|null
     */
    private function captureResumeAfter(): ?array
    {
        $state = $this->loadState();
        $existing = is_array($state['resume_after'] ?? null) ? $state['resume_after'] : null;
        $stationId = trim((string) ($state['station_id'] ?? ''));
        if ($stationId === '' || $stationId === 'announce') {
            // Stacked announcements keep the original station / AirPlay resume.
            return $this->resumeAfterLooksValid($existing) ? $existing : null;
        }
        // AirPlay bridge is resumable after Announcement (receiver stays up).

        $alive = false;
        foreach ($state['streams'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->pidAlive((int) ($row['pid'] ?? 0))) {
                $alive = true;
                break;
            }
        }
        if (!$alive && empty($state['playing'])) {
            return null;
        }

        $targets = [];
        if (is_array($state['targets'] ?? null)) {
            foreach ($state['targets'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $targets[] = $id;
                }
            }
        }
        if ($targets === []) {
            foreach ($state['streams'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['camera_id'] ?? ''));
                if ($id !== '') {
                    $targets[] = $id;
                }
            }
        }
        $targets = array_values(array_unique($targets));
        if ($targets === []) {
            return null;
        }

        return [
            'station_id' => $stationId,
            'url' => trim((string) ($state['station_url'] ?? '')),
            'targets' => $targets,
            'volume' => max(0, min(100, (int) ($state['volume'] ?? 60))),
        ];
    }

    /**
     * @param mixed $resume
     */
    private function resumeAfterLooksValid(mixed $resume): bool
    {
        if (!is_array($resume)) {
            return false;
        }
        $stationId = trim((string) ($resume['station_id'] ?? ''));
        if ($stationId === '' || $stationId === 'announce') {
            return false;
        }
        $targets = $this->normalizeTargetIds($resume['targets'] ?? []);

        return $targets !== [];
    }

    /**
     * @param list<array{camera_id: string, talkback_url: string, codec: string, sampling_rate: int}> $sessions
     */
    private function spawnAirplayFanout(
        string $ffmpeg,
        string $fifo,
        array $sessions,
        int $volume,
    ): int {
        if ($sessions === []) {
            return 0;
        }
        $volume = max(0, min(100, $volume));
        $gain = number_format($volume / 100, 4, '.', '');
        $n = count($sessions);
        $labels = [];
        for ($i = 0; $i < $n; $i++) {
            $labels[] = '[a' . $i . ']';
        }
        // One FIFO reader → mono 22050 → asplit → AAC ADTS UDP per speaker.
        $filter = '[0:a]volume=' . $gain
            . ',pan=mono|c0=0.5*c0+0.5*c1,aresample=22050,asplit=' . $n
            . implode('', $labels);
        $log = $this->projectRoot . '/data/unifi-radio-airplay.log';
        @file_put_contents($log, '');
        $ff = escapeshellarg($ffmpeg);
        $fifoEsc = escapeshellarg($fifo);
        $logEsc = escapeshellarg($log);
        $filterEsc = escapeshellarg($filter);
        $cmd = "{$ff} -nostdin -hide_banner -loglevel warning"
            . " -f s16le -ar 44100 -ac 2 -i {$fifoEsc}"
            . " -filter_complex {$filterEsc}";
        foreach ($sessions as $i => $row) {
            $parsed = parse_url((string) ($row['talkback_url'] ?? ''));
            $host = (string) ($parsed['host'] ?? '');
            $port = (int) ($parsed['port'] ?? 7004);
            if ($host === '') {
                return 0;
            }
            $udp = escapeshellarg('udp://' . $host . ':' . $port);
            $cmd .= ' -map "[a' . $i . ']" -c:a aac -profile:a aac_low -b:a 32k -f adts ' . $udp;
        }
        $full = 'nohup ' . $cmd . ' > ' . $logEsc . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($full));
        if ($pid <= 1) {
            return 0;
        }
        // Bridge blocks on FIFO until an AirPlay client plays — process should stay alive.
        usleep(300000);
        if (!$this->pidAlive($pid)) {
            return 0;
        }

        return $pid;
    }

    /**
     * @param list<string> $targets
     * @param array{station_id: string, url: string, targets: list<string>, volume: int}|null $resumeAfter
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
        ?array $resumeAfter = null,
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
        $endsAt = null;
        if ($ok && $localFile && $playing) {
            // Clip length + small grace so status() returns Idle after the announcement.
            $seconds = $this->probeClipDurationSeconds($contentUrl);
            $pad = 2;
            $endsAt = time() + max(2, (int) ceil(($seconds ?? 30) + $pad));
        }
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
            'ends_at' => $endsAt,
            // Only announcements carry a station to restore; a normal Play clears it.
            'resume_after' => ($ok && $localFile && $this->resumeAfterLooksValid($resumeAfter))
                ? [
                    'station_id' => (string) $resumeAfter['station_id'],
                    'url' => (string) ($resumeAfter['url'] ?? ''),
                    'targets' => $this->normalizeTargetIds($resumeAfter['targets'] ?? []),
                    'volume' => max(0, min(100, (int) ($resumeAfter['volume'] ?? $volume))),
                ]
                : null,
            'error' => $playError,
        ];
        $this->writeState($state);

        // Clip finished before we returned — resume the interrupted station immediately.
        if (
            $ok
            && $localFile
            && !$playing
            && !$this->resumingRadio
            && $this->resumeAfterLooksValid($state['resume_after'] ?? null)
        ) {
            $resume = $state['resume_after'];
            $state['resume_after'] = null;
            $this->writeState($state);
            $this->resumingRadio = true;
            try {
                $replay = $this->play([
                    'station_id' => (string) $resume['station_id'],
                    'url' => (string) ($resume['url'] ?? ''),
                    'targets' => $resume['targets'],
                    'volume' => $resume['volume'] ?? $volume,
                ]);

                return [
                    'ok' => true,
                    'error' => null,
                    'warning' => $ok && $errors !== [] ? implode('; ', $errors) : null,
                    'radio' => is_array($replay['radio'] ?? null) ? $replay['radio'] : $this->status(),
                ];
            } finally {
                $this->resumingRadio = false;
            }
        }

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
        $state['ends_at'] = null;
        if ($clearError) {
            $state['error'] = null;
            // Explicit Stop cancels “resume radio after announcement”.
            $state['resume_after'] = null;
        }
        $this->writeState($state);

        return ['ok' => true, 'radio' => $this->status()];
    }

    /**
     * Best-effort clip length for Announcement Idle timeout (seconds).
     */
    private function probeClipDurationSeconds(string $path): ?float
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $ffmpeg = $this->unifi->ffmpegPath();
        $ffprobe = 'ffprobe';
        if ($ffmpeg !== '' && str_contains($ffmpeg, '/')) {
            $candidate = dirname($ffmpeg) . '/ffprobe';
            if (is_executable($candidate)) {
                $ffprobe = $candidate;
            }
        }
        $cmd = escapeshellarg($ffprobe)
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($path)
            . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        if ($out === '' || !is_numeric($out)) {
            return null;
        }
        $seconds = (float) $out;
        if ($seconds <= 0 || $seconds > 120) {
            return null;
        }

        return $seconds;
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
            $replay = $stationId === 'airplay'
                ? $this->playAirplay([
                    'targets' => $targets,
                    'volume' => $volume,
                    'ensure_receiver' => false,
                ])
                : $this->play([
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
                'airplay' => $replay['airplay'] ?? null,
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
        $airplay = new YarboPanelAirPlay($this->projectRoot);

        return [
            'stations' => $this->stations(),
            'speakers' => $this->unifi->speakerCameras(),
            'radio' => $this->status(),
            'speakers_enabled' => $this->unifi->speakersEnabled(),
            'airplay' => $airplay->status(),
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
            'ends_at' => null,
            'resume_after' => null,
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
