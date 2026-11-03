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

        $homepodTargets = [];
        if (is_array($state['homepod_targets'] ?? null)) {
            foreach ($state['homepod_targets'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $homepodTargets[] = $id;
                }
            }
        }
        $homepodAlive = $homepodTargets !== [];

        // AirPlay fan-out blocks on an empty FIFO forever — never treat it as Playing
        // unless a real client session is active (play-begins hook / TCP watcher).
        if (
            trim((string) ($state['station_id'] ?? '')) === 'airplay'
            && (!empty($state['playing']) || $alive !== [] || $homepodAlive)
        ) {
            $airplay = new YarboPanelAirPlay($this->projectRoot);
            if (!$airplay->clientIsPresent()) {
                foreach ($alive as $row) {
                    $pid = (int) ($row['pid'] ?? 0);
                    if ($pid > 1) {
                        $this->killPidTree($pid);
                    }
                }
                if ($homepodAlive) {
                    YarboHomepod::fromEnv($this->projectRoot)->stop($homepodTargets);
                    $this->stopHomepodBridgeHttp();
                }
                $airplay->markSessionActive(false);
                $state['playing'] = false;
                $state['station_id'] = null;
                $state['station_name'] = null;
                $state['station_url'] = null;
                $state['streams'] = [];
                $state['homepod_targets'] = [];
                $state['ends_at'] = null;
                $this->writeState($state);
                $alive = [];
                $homepodTargets = [];
                $homepodAlive = false;
            }
        }

        $endsAt = isset($state['ends_at']) ? (int) $state['ends_at'] : 0;
        $stationIdNow = trim((string) ($state['station_id'] ?? ''));
        // HomePod-only announcements with a missing ends_at used to stay Playing forever
        // (no local ffmpeg pid to reap). Bound them from started_at.
        if ($stationIdNow === 'announce' && $homepodAlive && $endsAt <= 0) {
            $startedAt = (int) ($state['started_at'] ?? 0);
            $endsAt = $startedAt > 0 ? $startedAt + 45 : time();
            $state['ends_at'] = $endsAt;
            $this->writeState($state);
        }
        $timedOut = $endsAt > 0 && time() >= $endsAt;
        // Finite clips (Announcement) must return to Idle when ffmpeg exits or ends_at passes.
        // HomePod-only radio stays Playing while homepod_targets is set (no local ffmpeg pid).
        if ($timedOut || ($alive === [] && !$homepodAlive && ($state['playing'] ?? false))) {
            if ($timedOut && $alive !== []) {
                foreach ($alive as $row) {
                    $pid = (int) ($row['pid'] ?? 0);
                    if ($pid > 1) {
                        $this->killPidTree($pid);
                    }
                }
            }
            if ($homepodAlive || $timedOut) {
                YarboHomepod::fromEnv($this->projectRoot)->stop($homepodTargets !== [] ? $homepodTargets : null);
                $this->stopHomepodBridgeHttp();
            }
            $wasAnnounce = trim((string) ($state['station_id'] ?? '')) === 'announce';
            $resume = is_array($state['resume_after'] ?? null) ? $state['resume_after'] : null;
            $state['playing'] = false;
            $state['streams'] = [];
            $state['homepod_targets'] = [];
            $state['ends_at'] = null;
            $state['resume_after'] = null;
            $state['error'] = $state['error'] ?? null;
            $this->writeState($state);
            $alive = [];
            $homepodTargets = [];
            $homepodAlive = false;

            if (
                !$this->resumingRadio
                && $wasAnnounce
                && $this->resumeAfterLooksValid($resume)
            ) {
                $this->resumingRadio = true;
                try {
                    $replay = $this->resumeAfterPlayback($resume);
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
            $state['playing'] = $alive !== [] || $homepodAlive;
            if ($alive === [] && !$homepodAlive) {
                $state['ends_at'] = null;
            }
            $this->writeState($state);
        }

        // Re-read after possible writes above.
        $homepodAlive = is_array($state['homepod_targets'] ?? null) && $state['homepod_targets'] !== [];

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

        $homepodActive = [];
        if (is_array($state['homepod_targets'] ?? null)) {
            foreach ($state['homepod_targets'] as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $homepodActive[] = $id;
                }
            }
        }

        return [
            'playing' => (!empty($state['playing']) && $alive !== []) || $homepodActive !== [],
            'station_id' => isset($state['station_id']) ? (string) $state['station_id'] : null,
            'station_name' => isset($state['station_name']) ? (string) $state['station_name'] : null,
            'targets' => $targets,
            'active_targets' => array_values(array_unique(array_merge($aliveIds, $homepodActive))),
            'homepod_targets' => $homepodActive,
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

        // Radio station takes the speakers — stop advertising AirPlay so a late
        // client connect cannot yank talkback away from the station.
        (new YarboPanelAirPlay($this->projectRoot))->stopReceiver();

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
    /**
     * Advertise AirPlay on the LAN and remember speakers — never mark Playing here.
     * Talkback starts only from play-begins / TCP-watch hooks when a device streams.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function armAirplay(array $input): array
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
        $airplay->saveBridge($targets, $volume, true);
        $airplay->markSessionActive(false);

        // Stop any current station/fanout; receiver restart is handled by ensure().
        $prevClip = (string) ($this->loadState()['clip_path'] ?? '');
        $this->stop(false);
        if ($prevClip !== '' && is_file($prevClip)) {
            @unlink($prevClip);
        }

        $ensured = $airplay->ensure();
        if (!($ensured['ok'] ?? false) || empty($ensured['running'])) {
            $airplay->clearBridge();

            return [
                'ok' => false,
                'error' => (string) ($ensured['error'] ?? $ensured['message'] ?? 'AirPlay receiver did not start'),
                'radio' => $this->status(),
                'airplay' => $airplay->status(),
            ];
        }

        // Idle until a device connects (session play-begins hook and/or TCP watcher).
        $state = $this->loadState();
        $state['playing'] = false;
        $state['station_id'] = null;
        $state['station_name'] = null;
        $state['station_url'] = null;
        $state['streams'] = [];
        $state['targets'] = $targets;
        $state['volume'] = $volume;
        $state['ends_at'] = null;
        $state['resume_after'] = null;
        $state['error'] = null;
        $this->writeState($state);

        return [
            'ok' => true,
            'error' => null,
            'radio' => $this->status(),
            'airplay' => $airplay->status(),
        ];
    }

    /**
     * Start FIFO → talkback fan-out (client active, announcement resume, or classic).
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

        $airplay = new YarboPanelAirPlay($this->projectRoot);
        $bridge = $airplay->bridge();

        $targets = $this->normalizeTargetIds($input['targets'] ?? $input['speakers'] ?? []);
        if ($targets === []) {
            $targets = $this->normalizeTargetIds($bridge['targets']);
        }
        if ($targets === []) {
            $status = $this->status();
            $targets = $this->normalizeTargetIds($status['targets'] ?? []);
        }
        if ($targets === []) {
            return ['ok' => false, 'error' => 'Pick at least one speaker', 'airplay' => $airplay->status()];
        }

        $volume = array_key_exists('volume', $input)
            ? max(0, min(100, (int) $input['volume']))
            : ($bridge['volume'] > 0 ? $bridge['volume'] : max(0, min(100, (int) ($this->loadState()['volume'] ?? 60))));

        $airplay->saveBridge($targets, $volume, true);

        $ensureReceiver = !array_key_exists('ensure_receiver', $input)
            || YarboHub::asBool($input['ensure_receiver'] ?? true);
        if ($ensureReceiver) {
            $ensured = $airplay->ensure();
            if (!($ensured['ok'] ?? false) || empty($ensured['running'])) {
                return [
                    'ok' => false,
                    'error' => (string) ($ensured['error'] ?? $ensured['message'] ?? 'AirPlay receiver did not start'),
                    'airplay' => $airplay->status(),
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
                @shell_exec('mkfifo -m 666 ' . escapeshellarg($fifo) . ' 2>/dev/null');
            }
        }
        if (!file_exists($fifo)) {
            return ['ok' => false, 'error' => 'AirPlay audio FIFO missing', 'airplay' => $airplay->status()];
        }

        $prevClip = (string) ($this->loadState()['clip_path'] ?? '');
        $this->stop(false);
        if ($prevClip !== '' && is_file($prevClip)) {
            @unlink($prevClip);
        }

        $protectIds = YarboHomepod::filterProtectIds($targets);
        $homepodIds = YarboHomepod::filterHomepodIds($targets);

        foreach ($protectIds as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        $sessions = [];
        $errors = [];
        foreach ($protectIds as $cameraId) {
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
        if ($sessions === [] && $homepodIds === []) {
            return [
                'ok' => false,
                'error' => $errors !== [] ? implode('; ', $errors) : 'No speakers started',
                'airplay' => $airplay->status(),
            ];
        }

        $sessionMeta = $airplay->session();
        $client = trim((string) ($sessionMeta['client_name'] ?? ''));
        $displayName = $client !== '' ? $client : 'AirPlay';
        $homepodCount = count($homepodIds);
        $pid = $this->spawnAirplayFanout($ffmpeg, $fifo, $sessions, $volume, $homepodCount);
        if ($pid <= 0) {
            return [
                'ok' => false,
                'error' => 'Could not start AirPlay → speaker bridge (see data/unifi-radio-airplay.log)',
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
        // HomePod-only bridge still tracks the shared ffmpeg pid so Stop/status can reap it.
        if ($sessions === [] && $homepodCount > 0) {
            $streams[] = [
                'camera_id' => 'homepod-bridge',
                'pid' => $pid,
                'talkback_url' => '',
                'codec' => 'mp3',
            ];
        }

        $homepodTargets = [];
        if ($homepodCount > 0) {
            // Give ffmpeg a moment to open each HTTP listener (one port per HomePod).
            usleep(500000);
            $hp = YarboHomepod::fromEnv($this->projectRoot);
            foreach ($homepodIds as $i => $nativeId) {
                $bridgeUrl = $this->homepodBridgeUrl((int) $i);
                $hpResult = $hp->streamToMany([$nativeId], $bridgeUrl, $volume);
                foreach ($hpResult['errors'] as $err) {
                    $errors[] = $err;
                }
                foreach ($hpResult['started'] as $id) {
                    $homepodTargets[] = $id;
                }
            }
        }

        $state = [
            'playing' => true,
            'station_id' => 'airplay',
            'station_name' => $displayName,
            'station_url' => $fifo,
            'clip_path' => null,
            'volume' => $volume,
            'streams' => $streams,
            'homepod_targets' => $homepodTargets,
            'targets' => $targets,
            'started_at' => time(),
            'ends_at' => null,
            'resume_after' => null,
            'error' => $errors !== [] ? implode('; ', $errors) : null,
        ];
        $this->writeState($state);
        // Session active flag is owned by play-begins / TCP watcher — do not set it here.

        return [
            'ok' => true,
            'error' => null,
            'warning' => $errors !== [] ? implode('; ', $errors) : null,
            'radio' => $this->status(),
            'airplay' => $airplay->status(),
        ];
    }

    /**
     * shairport-sync play begins / TCP client seen — start talkback fan-out if armed.
     *
     * @return array<string, mixed>
     */
    public function onAirplayClientActive(): array
    {
        $airplay = new YarboPanelAirPlay($this->projectRoot);
        $bridge = $airplay->bridge();
        if (!$bridge['armed']) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'not armed', 'airplay' => $airplay->status()];
        }
        $state = $this->loadState();
        // Don't interrupt an announcement; resume_after already points at AirPlay.
        if (trim((string) ($state['station_id'] ?? '')) === 'announce' && !empty($state['playing'])) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'announce playing', 'airplay' => $airplay->status()];
        }
        if (
            !empty($state['playing'])
            && trim((string) ($state['station_id'] ?? '')) === 'airplay'
            && is_array($state['streams'] ?? null)
            && $state['streams'] !== []
        ) {
            // Already bridging — refresh client name in status if known.
            $client = trim((string) ($airplay->session()['client_name'] ?? ''));
            if ($client !== '') {
                $state['station_name'] = $client;
                $this->writeState($state);
            }

            return ['ok' => true, 'skipped' => true, 'reason' => 'already playing', 'radio' => $this->status(), 'airplay' => $airplay->status()];
        }

        return $this->playAirplay([
            'targets' => $bridge['targets'],
            'volume' => $bridge['volume'],
            'ensure_receiver' => false,
        ]);
    }

    /**
     * shairport-sync left active state — stop fan-out but keep the receiver advertised.
     *
     * @return array<string, mixed>
     */
    public function onAirplayClientInactive(): array
    {
        $airplay = new YarboPanelAirPlay($this->projectRoot);
        $state = $this->loadState();
        $stationId = trim((string) ($state['station_id'] ?? ''));
        // Announcement owns the speakers; leave resume_after alone.
        if ($stationId === 'announce') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'announce',
                'radio' => $this->status(),
                'airplay' => $airplay->status(),
            ];
        }
        if ($stationId === 'airplay') {
            $this->stop(false);
        }

        return [
            'ok' => true,
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
        if (!$stopReceiver) {
            $airplay->markSessionActive(false);
        }

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
     * Restart the station / AirPlay bridge captured before an announcement.
     *
     * @param array{station_id: string, url: string, targets: list<string>, volume: int} $resume
     * @return array<string, mixed>
     */
    private function resumeAfterPlayback(array $resume): array
    {
        $resumeId = (string) ($resume['station_id'] ?? '');
        if ($resumeId === 'airplay') {
            $airplay = new YarboPanelAirPlay($this->projectRoot);
            $targets = $this->normalizeTargetIds($resume['targets'] ?? []);
            $volume = max(0, min(100, (int) ($resume['volume'] ?? 60)));
            $airplay->saveBridge($targets, $volume, true);
            // If the Apple device dropped during the announcement, stay Available
            // (waiting) instead of showing Playing with a silent FIFO reader.
            if (!$airplay->clientIsPresent()) {
                return [
                    'ok' => true,
                    'skipped' => true,
                    'radio' => $this->status(),
                    'airplay' => $airplay->status(),
                ];
            }

            return $this->playAirplay([
                'targets' => $targets,
                'volume' => $volume,
                'ensure_receiver' => false,
            ]);
        }

        return $this->play([
            'station_id' => $resumeId,
            'url' => (string) ($resume['url'] ?? ''),
            'targets' => $resume['targets'] ?? [],
            'volume' => $resume['volume'] ?? 60,
        ]);
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
        int $homepodCount = 0,
    ): int {
        $homepodCount = max(0, $homepodCount);
        if ($sessions === [] && $homepodCount < 1) {
            return 0;
        }
        $volume = max(0, min(100, $volume));
        $gain = number_format($volume / 100, 4, '.', '');
        $n = count($sessions);
        $split = $n + $homepodCount;
        if ($split < 1) {
            return 0;
        }
        $labels = [];
        for ($i = 0; $i < $n; $i++) {
            $labels[] = '[a' . $i . ']';
        }
        for ($i = 0; $i < $homepodCount; $i++) {
            $labels[] = '[hm' . $i . ']';
        }
        // One FIFO reader → mono → asplit → AAC ADTS UDP per Protect speaker
        // (+ one MP3 HTTP listener per HomePod; ffmpeg -listen is single-client).
        $filter = '[0:a]volume=' . $gain
            . ',pan=mono|c0=0.5*c0+0.5*c1,aresample=22050,asplit=' . $split
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
        if ($homepodCount > 0) {
            @file_put_contents($this->homepodBridgePidPath(), '');
            $basePort = $this->homepodBridgePort();
            for ($i = 0; $i < $homepodCount; $i++) {
                $http = escapeshellarg('http://127.0.0.1:' . ($basePort + $i) . '/live.mp3');
                $cmd .= ' -map "[hm' . $i . ']" -c:a libmp3lame -b:a 128k -f mp3 -listen 1 ' . $http;
            }
        }
        $full = 'nohup ' . $cmd . ' > ' . $logEsc . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($full));
        if ($pid <= 1) {
            return 0;
        }
        if ($homepodCount > 0) {
            @file_put_contents($this->homepodBridgePidPath(), (string) $pid);
        }
        // Bridge blocks on FIFO until an AirPlay client plays — process should stay alive.
        usleep(300000);
        if (!$this->pidAlive($pid)) {
            return 0;
        }

        return $pid;
    }

    private function homepodBridgePort(): int
    {
        $port = (int) (getenv('YARBO_HOMEPOD_BRIDGE_PORT') ?: 8768);

        return $port > 0 ? $port : 8768;
    }

    private function homepodBridgeUrl(int $index = 0): string
    {
        return 'http://127.0.0.1:' . ($this->homepodBridgePort() + max(0, $index)) . '/live.mp3';
    }

    private function homepodBridgePidPath(): string
    {
        return $this->projectRoot . '/data/homepod/bridge-http.pid';
    }

    private function stopHomepodBridgeHttp(): void
    {
        $pf = $this->homepodBridgePidPath();
        // Fanout pid is shared with talkback — do not kill it here; stop() already kills streams.
        @unlink($pf);
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

        $protectIds = YarboHomepod::filterProtectIds($targets);
        $homepodIds = YarboHomepod::filterHomepodIds($targets);

        // Best-effort Protect hardware volume (needs local UniFi OS admin). Playback
        // loudness is always applied in ffmpeg so the slider works without that.
        foreach ($protectIds as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        $streams = [];
        $errors = [];
        $started = 0;
        foreach ($protectIds as $cameraId) {
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

        $homepodTargets = [];
        if ($homepodIds !== []) {
            $hp = YarboHomepod::fromEnv($this->projectRoot);
            $hpResult = $hp->streamToMany($homepodIds, $contentUrl, $volume);
            foreach ($hpResult['errors'] as $err) {
                $errors[] = $err;
            }
            foreach ($hpResult['started'] as $id) {
                $homepodTargets[] = $id;
                $started++;
            }
        }

        $protectPlaying = $streams !== [];
        $homepodPlaying = $homepodTargets !== [];
        $playing = $protectPlaying || $homepodPlaying;
        // Short announce clips may finish before we return; still count as ok if ffmpeg/HomePod started.
        $ok = $localFile ? $started > 0 : $playing;
        $playError = $ok
            ? ($errors !== [] ? implode('; ', $errors) : null)
            : ($errors !== [] ? implode('; ', $errors) : 'No speakers started');
        $endsAt = null;
        if ($ok && $localFile) {
            // Always set ends_at for announcements — HomePod-only clips have no ffmpeg
            // pid, so without this the UI can stay on Playing forever.
            $seconds = $this->probeClipDurationSeconds($contentUrl);
            $pad = 2;
            $endsAt = time() + max(2, (int) ceil(($seconds ?? 30) + $pad));
            if (!$playing) {
                // Already finished on every target before we returned.
                $endsAt = time();
            }
        }
        $state = [
            'playing' => $playing,
            'station_id' => $stationId,
            'station_name' => $stationName,
            'station_url' => $contentUrl,
            'clip_path' => $localFile ? $clipPath : null,
            'volume' => $volume,
            'streams' => $streams,
            'homepod_targets' => $homepodTargets,
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
                $replay = $this->resumeAfterPlayback($resume);

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
        $homepodIds = [];
        if (is_array($state['homepod_targets'] ?? null)) {
            $homepodIds = $state['homepod_targets'];
        }
        // Always stop HomePod streams tied to radio/announce/bridge.
        YarboHomepod::fromEnv($this->projectRoot)->stop($homepodIds !== [] ? $homepodIds : null);
        $this->stopHomepodBridgeHttp();
        $clip = trim((string) ($state['clip_path'] ?? ''));
        // Keep the last announce file while Idle so volume changes can replay it;
        // only delete when clearing after a fresh stop request with clearError.
        if ($clearError && $clip !== '' && is_file($clip)) {
            // Leave file for a short window — cleaned on next play/announce.
        }
        $state['playing'] = false;
        $state['streams'] = [];
        $state['homepod_targets'] = [];
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
        $wasPlaying = (!empty($state['playing']) && is_array($state['streams']) && $state['streams'] !== [])
            || (is_array($state['homepod_targets'] ?? null) && $state['homepod_targets'] !== []);
        $stationId = isset($state['station_id']) ? (string) $state['station_id'] : '';
        $state['volume'] = $volume;
        if ($targets !== []) {
            $state['targets'] = $targets;
        }
        $this->writeState($state);

        // Hardware volume is optional; stream volume is applied by restarting play.
        foreach (YarboHomepod::filterProtectIds($targets) as $cameraId) {
            $this->unifi->setCameraSpeakerVolume($cameraId, $volume);
        }

        $homepodIds = YarboHomepod::filterHomepodIds(
            $targets !== [] ? $targets : (is_array($state['homepod_targets'] ?? null) ? $state['homepod_targets'] : [])
        );
        if ($homepodIds !== []) {
            YarboHomepod::fromEnv($this->projectRoot)->setVolume($homepodIds, $volume);
        }

        if ($wasPlaying && $stationId !== '' && $targets !== []) {
            // Protect / AirPlay bridge loudness is baked into ffmpeg — restart to apply.
            // HomePod-only radio can keep the RAOP session and only change volume above.
            $protectIds = YarboHomepod::filterProtectIds($targets);
            if ($stationId === 'airplay' || $protectIds !== []) {
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
        $homepod = YarboHomepod::fromEnv($this->projectRoot);
        $speakers = array_merge($this->unifi->speakerCameras(), $homepod->speakerRows());

        return [
            'stations' => $this->stations(),
            'speakers' => $speakers,
            'radio' => $this->status(),
            'speakers_enabled' => $this->unifi->speakersEnabled(),
            'airplay' => $airplay->status(),
            'homepod' => $homepod->status(),
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
            if (YarboHomepod::isHomepodTargetId($id)) {
                $id = YarboHomepod::withPrefix(YarboHomepod::stripPrefix($id));
            } else {
                $parsed = YarboUnifi::parseHomeId($id);
                if ($parsed !== null && $parsed['kind'] === YarboUnifi::KIND_CAMERA) {
                    $id = $parsed['native_id'];
                }
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
            'homepod_targets' => [],
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
