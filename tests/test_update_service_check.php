<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboUpdate;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dir = sys_get_temp_dir() . '/yarbo-unit-' . bin2hex(random_bytes(3));
mkdir($dir, 0775, true);
$update = new YarboUpdate('/home/admin/yarbo');

$old = $dir . '/old.service';
file_put_contents($old, "[Service]\nUser=admin\nWorkingDirectory=/home/admin/yarbo\nExecStart=/usr/bin/php -S 0.0.0.0:8080 -t /home/admin/yarbo/public\n");
$check = $update->serviceCheck($old);
assert_true($check['needs_install'] === true, 'old php -S unit needs install');
assert_true($check['command'] === 'cd /home/admin/yarbo && sudo ./scripts/install.sh', 'command uses the install folder');

$new = $dir . '/new.service';
file_put_contents($new, "[Service]\nExecStart=/home/admin/yarbo/scripts/panel.sh\n");
assert_true($update->serviceCheck($new)['needs_install'] === false, 'panel.sh unit is fine');

assert_true($update->serviceCheck($dir . '/missing.service')['needs_install'] === false, 'no unit (Mac / dev) shows nothing');

$api = (string) file_get_contents(__DIR__ . '/../public/api/update.php');
assert_true(str_contains($api, "'service' => \$update->serviceCheck()"), 'update status reports the service check');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'renderServiceNotice(data?.service)'), 'Settings shows the notice');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($html, 'id="settings-service-notice"'), 'notice element in Settings');

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

echo "ok: update service check\n";
