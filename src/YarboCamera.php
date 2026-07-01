<?php

declare(strict_types=1);

namespace Yarbo;

final class YarboCamera
{
  private const PATH = '/live/chn0';

  /** @var array<string, array{data: string, at: float}> */
  private static array $snapshotCache = [];

  /** @var string|null */
  private static ?string $resolvedHost = null;

  /**
   * @param array<string, mixed> $config
   */
  public static function resolveHost(array $config, bool $force = false): string
  {
    if (!$force && self::$resolvedHost !== null) {
      return self::$resolvedHost;
    }

    if (!empty($config['camera_host'])) {
      self::$resolvedHost = (string) $config['camera_host'];

      return self::$resolvedHost;
    }

    $candidates = [];
    if ($config['camera_auto_detect'] ?? true) {
      $candidates[] = '127.0.0.1';
    }
    $candidates[] = (string) $config['broker_host'];

    foreach ($candidates as $host) {
      if (self::anyPortOpen($config, $host)) {
        self::$resolvedHost = $host;

        return self::$resolvedHost;
      }
    }

    self::$resolvedHost = (string) $config['broker_host'];

    return self::$resolvedHost;
  }

  /**
   * @param array<string, mixed> $config
   */
  public static function prepareCameras(array $config): void
  {
    $client = new YarboMqtt(
      $config['broker_host'],
      (int) $config['broker_port'],
      $config['serial'],
    );
    $client->connect();
    $client->sendCommand('camera_toggle', ['enabled' => true], true);
    $client->sendCommand('smart_vision_control', ['state' => 1], false);
    $client->disconnect();
    self::$resolvedHost = null;
  }

  /**
   * @param array<string, mixed> $config
   * @return array<string, array{id: string, name: string, rtsp: string, port: int|null, online: bool|null}>
   */
  public static function list(array $config, ?array $cameraState = null): array
  {
    if (!($config['cameras_enabled'] ?? true)) {
      return [];
    }

    $host = self::resolveHost($config);
    $cameras = [];

    foreach (self::definitions($config) as $id => $def) {
      $stateKey = $def['state_key'] ?? null;
      $online = null;
      if ($cameraState !== null && $stateKey !== null) {
        $online = (int) ($cameraState[$stateKey] ?? 0) === 1;
      }

      $cameras[$id] = [
        'id'     => $id,
        'name'   => $def['name'],
        'rtsp'   => self::rtspUrl($config, $def, $host),
        'port'   => $def['port'] ?? null,
        'online' => $online,
      ];
    }

    return $cameras;
  }

  /**
   * @param array<string, mixed> $config
   * @return array{host: string, ports: array<int, bool>}
   */
  public static function probePorts(array $config): array
  {
    $host = self::resolveHost($config, true);
    $ports = [];
    foreach (self::definitions($config) as $def) {
      $port = (int) ($def['port'] ?? 0);
      if ($port > 0) {
        $ports[$port] = self::canConnect($host, $port);
      }
    }

    $localhostOpen = ($config['camera_auto_detect'] ?? true) && self::anyPortOpen($config, '127.0.0.1');

    return [
      'host' => $host,
      'ports' => $ports,
      'localhost_tunnel' => $localhostOpen,
    ];
  }

  /**
   * @param array<string, mixed> $config
   */
  public static function rtspFor(array $config, string $cameraId): string
  {
    $definitions = self::definitions($config);
    if (!isset($definitions[$cameraId])) {
      throw new \InvalidArgumentException('Unknown camera: ' . $cameraId);
    }

    return self::rtspUrl($config, $definitions[$cameraId], self::resolveHost($config));
  }

  /**
   * @param array<string, mixed> $config
   */
  public static function snapshot(array $config, string $cameraId, float $cacheSeconds = 0.4): string
  {
    $cacheKey = $cameraId;
    $now = microtime(true);
    if (
      isset(self::$snapshotCache[$cacheKey])
      && ($now - self::$snapshotCache[$cacheKey]['at']) < $cacheSeconds
    ) {
      return self::$snapshotCache[$cacheKey]['data'];
    }

    $rtsp = self::rtspFor($config, $cameraId);
    $ffmpeg = self::ffmpegPath($config);
    $cmd = sprintf(
      '%s -hide_banner -loglevel error -rtsp_transport tcp -stimeout 5000000 -i %s -an -frames:v 1 -f image2 pipe:1',
      escapeshellcmd($ffmpeg),
      escapeshellarg($rtsp),
    );

    $data = self::runCommand($cmd);
    if ($data === '') {
      throw new \RuntimeException('No frame received from camera stream');
    }

    self::$snapshotCache[$cacheKey] = ['data' => $data, 'at' => $now];

    return $data;
  }

  /**
   * @param array<string, mixed> $config
   */
  public static function streamMjpeg(array $config, string $cameraId, int $fps = 5): void
  {
    $rtsp = self::rtspFor($config, $cameraId);
    $ffmpeg = self::ffmpegPath($config);
    $cmd = sprintf(
      '%s -hide_banner -loglevel error -rtsp_transport tcp -stimeout 5000000 -i %s -an -f mjpeg -q:v 5 -r %d pipe:1',
      escapeshellcmd($ffmpeg),
      escapeshellarg($rtsp),
      $fps,
    );

    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Connection: close');
    header('Content-Type: multipart/x-mixed-replace; boundary=frame');

    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
      throw new \RuntimeException('Failed to start ffmpeg');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $buffer = '';
    while (!feof($pipes[1])) {
      $chunk = fread($pipes[1], 8192);
      if ($chunk === false) {
        break;
      }
      if ($chunk === '') {
        usleep(20000);
        continue;
      }

      $buffer .= $chunk;
      while (($start = strpos($buffer, "\xff\xd8")) !== false) {
        $next = strpos($buffer, "\xff\xd8", $start + 2);
        if ($next === false) {
          break;
        }

        $frame = substr($buffer, $start, $next - $start);
        $buffer = substr($buffer, $next);
        echo "--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " . strlen($frame) . "\r\n\r\n";
        echo $frame;
        echo "\r\n";
        if (function_exists('flush')) {
          flush();
        }
      }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
  }

  /**
   * @param array<string, mixed> $config
   * @return array<string, array{name: string, port?: int, rtsp?: string, state_key?: string}>
   */
  private static function definitions(array $config): array
  {
    if (!empty($config['cameras']) && is_array($config['cameras'])) {
      return $config['cameras'];
    }

    return [
      'front' => ['name' => 'Front', 'port' => 19201, 'state_key' => 'cam_m_state'],
      'left'  => ['name' => 'Left',  'port' => 19202, 'state_key' => 'cam_l_state'],
      'right' => ['name' => 'Right', 'port' => 19203, 'state_key' => 'cam_r_state'],
      'rear'  => ['name' => 'Rear',  'port' => 19204, 'state_key' => 'cam_b_state'],
    ];
  }

  /**
   * @param array<string, mixed> $config
   * @param array{name: string, port?: int, rtsp?: string, state_key?: string} $def
   */
  private static function rtspUrl(array $config, array $def, string $host): string
  {
    if (!empty($def['rtsp'])) {
      return (string) $def['rtsp'];
    }

    $port = (int) ($def['port'] ?? 19201);

    return sprintf('rtsp://%s:%d%s', $host, $port, self::PATH);
  }

  /**
   * @param array<string, mixed> $config
   */
  private static function anyPortOpen(array $config, string $host): bool
  {
    foreach (self::definitions($config) as $def) {
      $port = (int) ($def['port'] ?? 0);
      if ($port > 0 && self::canConnect($host, $port)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param array<string, mixed> $config
   */
  private static function ffmpegPath(array $config): string
  {
    return (string) ($config['ffmpeg_path'] ?? 'ffmpeg');
  }

  private static function canConnect(string $host, int $port, float $timeoutSeconds = 1.5): bool
  {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
    if ($socket === false) {
      return false;
    }

    fclose($socket);

    return true;
  }

  private static function runCommand(string $cmd): string
  {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
      throw new \RuntimeException('Failed to start ffmpeg');
    }

    $output = stream_get_contents($pipes[1]) ?: '';
    $error = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0 || $output === '') {
      $message = trim($error) !== '' ? trim($error) : 'ffmpeg exited with code ' . $code;
      throw new \RuntimeException($message);
    }

    return $output;
  }
}
