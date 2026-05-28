<?php

declare(strict_types=1);

namespace ThreePath\Tests\Integration;

use Phalanx\Application;
use Phalanx\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ThreePath\StbCommand;
use ThreePath\StbResponse;
use ThreePath\Task\PingStb;
use ThreePath\Task\SendStbCommand;
use ThreePath\Task\ScanForStbs;
use ThreePath\ThreePathServiceBundle;

use function React\Async\await;
use function React\Promise\resolve;

/**
 * Live integration tests against real STBs.
 *
 * Run with: composer test:stb
 *
 * Skipped automatically when the configured device IP is unreachable.
 * All tests run against the single device discovered in setUpBeforeClass()
 * to avoid hammering the network — scan once, reuse the result.
 *
 * Firmware regression workflow:
 *   1. Deploy new firmware to STBs
 *   2. Run `composer test:stb`
 *   3. Any field that changed shape, disappeared, or started returning garbage
 *      will produce a clear assertion failure pointing at the exact field
 */
#[Group('stb')]
final class StbIntegrationTest extends TestCase
{
    private static string $deviceIp;
    private static string $deviceId;

    public static function setUpBeforeClass(): void
    {
        $ip = $_ENV['STB_DEFAULT_DEVICE_IP'] ?? getenv('STB_DEFAULT_DEVICE_IP') ?: '10.30.5.219';
        $id = $_ENV['STB_DEFAULT_DEVICE_ID'] ?? getenv('STB_DEFAULT_DEVICE_ID') ?: '750051296';

        $response = self::execute(new PingStb($ip));

        if (!$response->success) {
            self::markTestSkipped("No STB available at {$ip} (timeout or no response)");
        }

        self::$deviceIp = $ip;
        self::$deviceId = $id;
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    #[Test]
    public function ping_returns_chip_id_and_firmware(): void
    {
        $response = self::execute(new PingStb(self::$deviceIp));

        $this->assertTrue($response->success, "Ping failed: {$response->error}");
        $this->assertNotEmpty($response->chipId, 'chipId must be present in HELLO_DISCOVERY response');

        $fw = $response->get('apk_version');
        $this->assertNotNull($fw, 'apk_version missing from response — firmware may have renamed this field');
        $this->assertMatchesRegularExpression('/^\d/', (string) $fw, 'apk_version should start with a digit');
    }

    #[Test]
    public function ping_response_has_expected_device_fields(): void
    {
        $response = self::execute(new PingStb(self::$deviceIp));

        $this->assertTrue($response->success);

        // These fields have been stable across firmware versions.
        // A failure here means a firmware update changed the response schema.
        foreach (['stb_chip_id', 'apk_version', 'ipAssignment'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $response->data,
                "Field '{$field}' missing from HELLO_DISCOVERY response — check firmware changelog",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Tuner status
    // -------------------------------------------------------------------------

    #[Test]
    public function tuner_status_returns_signal_fields(): void
    {
        $response = self::execute(new SendStbCommand(
            ip: self::$deviceIp,
            deviceId: self::$deviceId,
            command: StbCommand::tunerStatus(),
        ));

        $this->assertTrue($response->success, "Tuner status failed: {$response->error}");

        $details = $response->data['details'] ?? $response->data;

        // Signal fields expected from DVB-S tuner status.
        // If a firmware update moves these into a different key, tests fail here.
        foreach (['frequency', 'mode', 'agc', 'snr', 'symbol', 'lock'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $details,
                "Tuner field '{$field}' missing — firmware may have restructured CMD_GET_TUNER_STATUS response",
            );
        }
    }

    #[Test]
    public function tuner_agc_is_numeric_percentage(): void
    {
        $response = self::execute(new SendStbCommand(
            ip: self::$deviceIp,
            deviceId: self::$deviceId,
            command: StbCommand::tunerStatus(),
        ));

        $this->assertTrue($response->success);

        $agc = $response->data['details']['agc'] ?? $response->data['agc'] ?? null;
        $this->assertNotNull($agc, 'agc field missing');
        $this->assertIsNumeric($agc, 'agc should be a numeric value');
        $this->assertGreaterThanOrEqual(0, (float) $agc);
        $this->assertLessThanOrEqual(100, (float) $agc);
    }

    // -------------------------------------------------------------------------
    // Channel commands
    // -------------------------------------------------------------------------

    #[Test]
    public function channel_up_returns_success(): void
    {
        $response = self::execute(new SendStbCommand(
            ip: self::$deviceIp,
            deviceId: self::$deviceId,
            command: StbCommand::channelUp(),
        ));

        $this->assertTrue($response->success, "CH_UP failed: {$response->error}");
    }

    #[Test]
    public function channel_down_returns_success(): void
    {
        $response = self::execute(new SendStbCommand(
            ip: self::$deviceIp,
            deviceId: self::$deviceId,
            command: StbCommand::channelDown(),
        ));

        $this->assertTrue($response->success, "CH_DOWN failed: {$response->error}");
    }

    #[Test]
    public function channel_switch_to_known_service_returns_success(): void
    {
        $serviceId = (int) ($_ENV['STB_DEFAULT_SERVICE_ID'] ?? getenv('STB_DEFAULT_SERVICE_ID') ?: 146);

        $response = self::execute(new SendStbCommand(
            ip: self::$deviceIp,
            deviceId: self::$deviceId,
            command: StbCommand::forceChannelSwitch($serviceId),
        ));

        $this->assertTrue(
            $response->success,
            "FORCE_CH_SWITCH to service {$serviceId} failed: {$response->error}",
        );
    }

    // -------------------------------------------------------------------------
    // Subnet scan (slow — exercises concurrent map path)
    // -------------------------------------------------------------------------

    #[Test]
    public function scan_finds_at_least_one_stb_on_default_subnet(): void
    {
        $subnet = $_ENV['STB_DEFAULT_SUBNET'] ?? getenv('STB_DEFAULT_SUBNET') ?: '10.30.5.0/24';

        /** @var list<StbResponse> $found */
        $found = self::runScan($subnet);

        $this->assertNotEmpty(
            $found,
            "Scan of {$subnet} found no STBs — network issue or all devices offline",
        );

        foreach ($found as $stb) {
            $this->assertTrue($stb->success);
            $this->assertNotEmpty($stb->chipId, "STB at {$stb->ip} returned empty chipId");
            $this->assertNotEmpty($stb->ip);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function execute(PingStb|SendStbCommand $task): StbResponse
    {
        $app   = Application::starting()->providers(new ThreePathServiceBundle())->compile();
        $scope = $app->createScope();

        /** @var StbResponse $result */
        $result = await(resolve(null)->then(static fn() => $scope->execute($task)));

        Loop::stop();

        return $result;
    }

    private static function runScan(string $cidr): array
    {
        $app   = Application::starting()->providers(new ThreePathServiceBundle())->compile();
        $scope = $app->createScope();

        /** @var list<StbResponse> $result */
        $result = await(resolve(null)->then(
            static fn() => $scope->execute(new ScanForStbs($cidr))
        ));

        Loop::stop();

        return $result;
    }
}
