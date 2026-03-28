<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('daemon')]
final class DaemonAiCoordinationTest extends TestCase
{
    private const SDK_PATH = '/Users/jhavens/Code/Me/Rust/daemonai/sdks/php/src';

    private static string $runId;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('daemonai_observe')) {
            require self::SDK_PATH . '/DaemonAI.php';
            require self::SDK_PATH . '/functions.php';
        }

        $health = @file_get_contents('http://127.0.0.1:9077/health');
        if ($health !== 'ok') {
            self::markTestSkipped('DaemonAI daemon not running on port 9077');
        }

        self::$runId = substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private static function appName(string $agent): string
    {
        return $agent . '-' . self::$runId;
    }

    #[Test]
    public function agent_sends_message_and_another_agent_reads_it_via_http(): void
    {
        $checkpoint = daemonai_observe(limit: 1)['checkpoint'];
        $app = self::appName('architect');

        daemonai(
            ['message' => 'Found N+1 query in UserRepo::findAll', 'project' => '/app/sentinel-test'],
            severity: 'info',
            kind: 'custom',
            channel: 'agent-comms',
            app: $app,
        );

        usleep(150_000);

        $result = daemonai_observe(
            kinds: ['custom'],
            origins: ["app:$app"],
            since: $checkpoint,
        );

        $this->assertCount(1, $result['observations']);

        $obs = $result['observations'][0];
        $this->assertSame($app, $obs['origin']['name']);
        $this->assertSame('agent-comms', $obs['kind']['channel']);
        $this->assertSame('Found N+1 query in UserRepo::findAll', $obs['data']['message']);
        $this->assertSame('/app/sentinel-test', $obs['data']['project']);
        $this->assertSame('info', $obs['severity']);
    }

    #[Test]
    public function agent_sends_via_udp_and_another_reads_back(): void
    {
        if (!function_exists('socket_create')) {
            self::markTestSkipped('ext-sockets not available');
        }

        $checkpoint = daemonai_observe(limit: 1)['checkpoint'];
        $app = self::appName('security');

        daemonai_send_udp(
            ['message' => 'Security issue: SQL injection in SearchController', 'project' => '/app/sentinel-test'],
            severity: 'warn',
            kind: 'custom',
            channel: 'agent-comms',
            app: $app,
        );

        usleep(300_000);

        $result = daemonai_observe(
            kinds: ['custom'],
            origins: ["app:$app"],
            since: $checkpoint,
        );

        $this->assertCount(1, $result['observations']);

        $obs = $result['observations'][0];
        $this->assertSame($app, $obs['origin']['name']);
        $this->assertSame('agent-comms', $obs['kind']['channel']);
        $this->assertStringContainsString('SQL injection', $obs['data']['message']);
    }

    #[Test]
    public function two_agents_exchange_messages_and_each_sees_only_the_other(): void
    {
        $checkpoint = daemonai_observe(limit: 1)['checkpoint'];
        $alpha = self::appName('alpha');
        $beta = self::appName('beta');

        daemonai(
            ['message' => 'Architecture review complete, no issues found'],
            severity: 'info',
            kind: 'custom',
            channel: 'agent-comms',
            app: $alpha,
        );

        daemonai(
            ['message' => 'Performance hotspot detected in OrderService::calculate'],
            severity: 'warn',
            kind: 'custom',
            channel: 'agent-comms',
            app: $beta,
        );

        usleep(200_000);

        $alphaMessages = daemonai_observe(kinds: ['custom'], origins: ["app:$alpha"], since: $checkpoint);
        $betaMessages = daemonai_observe(kinds: ['custom'], origins: ["app:$beta"], since: $checkpoint);

        $this->assertCount(1, $alphaMessages['observations']);
        $this->assertCount(1, $betaMessages['observations']);

        $this->assertStringContainsString('no issues found', $alphaMessages['observations'][0]['data']['message']);
        $this->assertStringContainsString('Performance hotspot', $betaMessages['observations'][0]['data']['message']);
    }

    #[Test]
    public function checkpoint_based_incremental_polling(): void
    {
        $app = self::appName('poller');
        $cp1 = daemonai_observe(limit: 1)['checkpoint'];

        daemonai('message-1', severity: 'info', kind: 'custom', channel: 'poll-test', app: $app);
        usleep(150_000);

        $batch1 = daemonai_observe(kinds: ['custom'], origins: ["app:$app"], since: $cp1);
        $this->assertCount(1, $batch1['observations']);
        $cp2 = $batch1['checkpoint'];

        daemonai('message-2', severity: 'info', kind: 'custom', channel: 'poll-test', app: $app);
        usleep(150_000);

        $batch2 = daemonai_observe(kinds: ['custom'], origins: ["app:$app"], since: $cp2);
        $this->assertCount(1, $batch2['observations']);
        $this->assertStringContainsString('message-2', $batch2['observations'][0]['data']['message']);
    }
}
