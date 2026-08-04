<?php

declare(strict_types=1);

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/kudosity-codemod-'.bin2hex(random_bytes(6));
    mkdir($this->project.'/app/Notifications', 0777, true);
    mkdir($this->project.'/config', 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->project));
});

function runCodemod(string $path, bool $write = true): string
{
    // Not base_path() — under Testbench that resolves to the skeleton app,
    // not this repository. Walk up from tests/Unit/ instead.
    $codemod = dirname(__DIR__, 2).'/bin/kudosity-codemod';

    $cmd = escapeshellcmd(PHP_BINARY).' '.escapeshellarg($codemod)
        .' '.escapeshellarg($path).($write ? ' --write' : '');

    exec($cmd.' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));

    return implode("\n", $output);
}

it('rewrites namespaces, classes, the notification hook and the channel string', function () {
    file_put_contents($this->project.'/app/Notifications/OrderShipped.php', <<<'PHP'
        <?php

        namespace App\Notifications;

        use ExpertSystems\TransmitSms\Laravel\Notifications\TransmitSmsMessage;
        use Illuminate\Notifications\Notification;

        class OrderShipped extends Notification
        {
            public function via($notifiable): array
            {
                return ['transmitsms'];
            }

            public function toTransmitSms($notifiable): TransmitSmsMessage
            {
                return TransmitSmsMessage::create('Shipped')->from(config('transmitsms.from'));
            }
        }
        PHP);

    runCodemod($this->project);

    $result = file_get_contents($this->project.'/app/Notifications/OrderShipped.php');

    expect($result)
        ->toContain('use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;')
        ->toContain("return ['kudosity'];")
        ->toContain('public function toKudosity($notifiable): KudosityMessage')
        ->toContain("KudosityMessage::create('Shipped')")
        ->toContain("config('kudosity.from')")
        ->not->toContain('TransmitSms');
});

it('rewrites env keys without touching unrelated ones', function () {
    file_put_contents($this->project.'/.env', <<<'ENV'
        APP_NAME=Demo
        TRANSMITSMS_API_KEY=abc
        TRANSMITSMS_FROM=MyBrand
        OTHER_TRANSMIT_KEY=keepme
        ENV);

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/.env'))
        ->toContain('KUDOSITY_API_KEY=abc')
        ->toContain('KUDOSITY_FROM=MyBrand')
        ->toContain('OTHER_TRANSMIT_KEY=keepme')
        ->not->toContain('TRANSMITSMS_');
});

it('rewrites the composer requirement', function () {
    file_put_contents($this->project.'/composer.json', json_encode([
        'require' => ['expertsystemsau/transmitsms-laravel-client' => '^1.9'],
    ], JSON_PRETTY_PRINT));

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/composer.json'))
        ->toContain('expertsystemsau/kudosity-laravel-client');
});

it('preserves the V1 API hostname', function () {
    file_put_contents($this->project.'/config/services.php', <<<'PHP'
        <?php

        return ['base' => 'https://api.transmitsms.com'];
        PHP);

    runCodemod($this->project);

    expect(file_get_contents($this->project.'/config/services.php'))
        ->toContain('https://api.transmitsms.com');
});

it('changes nothing without --write and reports what it would change', function () {
    $file = $this->project.'/app/Notifications/OrderShipped.php';
    file_put_contents($file, '<?php use ExpertSystems\TransmitSms\TransmitSmsClient;');

    $output = runCodemod($this->project, write: false);

    expect(file_get_contents($file))->toContain('TransmitSmsClient')
        ->and($output)->toContain('OrderShipped.php')
        ->and($output)->toContain('dry run');
});

it('skips vendor and .git directories', function () {
    mkdir($this->project.'/vendor/acme', 0777, true);
    $vendored = $this->project.'/vendor/acme/Thing.php';
    file_put_contents($vendored, '<?php use ExpertSystems\TransmitSms\TransmitSmsClient;');

    runCodemod($this->project);

    expect(file_get_contents($vendored))->toContain('TransmitSmsClient');
});

it('leaves every file untouched when the preserve guard fires anywhere in the run (atomic write)', function () {
    // A deliberately over-broad map entry: an unquoted, unanchored "transmitsms"
    // matches inside the preserved hostname too, so this run must trip the guard.
    $map = json_decode(file_get_contents(dirname(__DIR__, 2).'/rename-map.json'), true);
    $map['strings']['transmitsms'] = 'kudosity';
    $badMap = $this->project.'/bad-map.json';
    file_put_contents($badMap, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // "0-" sorts before "1-" so a single-pass, write-as-you-go implementation
    // would rewrite this file on disk before it ever reaches the violation.
    $legit = $this->project.'/app/Notifications/0-legit.php';
    $violation = $this->project.'/app/Notifications/1-violation.php';

    file_put_contents($legit, '<?php use ExpertSystems\TransmitSms\TransmitSmsClient;');
    file_put_contents($violation, "<?php\n\nreturn ['base' => 'https://api.transmitsms.com'];\n");

    $legitBefore = file_get_contents($legit);
    $violationBefore = file_get_contents($violation);

    $codemod = dirname(__DIR__, 2).'/bin/kudosity-codemod';
    $cmd = escapeshellcmd(PHP_BINARY).' '.escapeshellarg($codemod)
        .' '.escapeshellarg($this->project).' --write --map='.escapeshellarg($badMap);

    exec($cmd.' 2>&1', $output, $status);

    expect($status)->toBe(1)
        ->and(implode("\n", $output))->toContain("preserved literal 'api.transmitsms.com'")
        ->and(file_get_contents($violation))->toBe($violationBefore)
        ->and(file_get_contents($legit))->toBe($legitBefore);
});
