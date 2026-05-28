<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Tool\InspectTerrain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InspectTerrainTest extends TestCase
{
    #[Test]
    public function known_location_returns_terrain_data(): void
    {
        $tool = new InspectTerrain(location: 'Thermopylae');

        $result = $tool();

        self::assertIsArray($result);
        self::assertArrayHasKey('elevation', $result);
        self::assertArrayHasKey('terrain_type', $result);
        self::assertArrayHasKey('defensibility', $result);
        self::assertArrayHasKey('chokepoints', $result);
        self::assertArrayHasKey('water_sources', $result);
    }

    #[Test]
    public function unknown_location_returns_status_unknown(): void
    {
        $tool = new InspectTerrain(location: 'Atlantis');

        $result = $tool();

        self::assertIsArray($result);
        self::assertSame('unknown', $result['status']);
        self::assertStringContainsString('Atlantis', $result['message']);
    }

    #[Test]
    public function location_matching_is_case_insensitive(): void
    {
        $result = (new InspectTerrain(location: 'MARATHON'))();

        self::assertArrayHasKey('elevation', $result);
    }
}
