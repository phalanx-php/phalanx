<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Tool;

final class InspectTerrain
{
    /** @var array<string, array{elevation: string, terrain_type: string, defensibility: string, chokepoints: string, water_sources: string}> */
    private const array TERRAIN_DATA = [
        'thermopylae' => [
            'elevation' => '15m above sea level, narrow coastal pass',
            'terrain_type' => 'mountain pass',
            'defensibility' => 'exceptional — cliffs to the south, sea to the north, 12m wide at the gates',
            'chokepoints' => 'the Middle Gate (narrowest), the Phocian Wall position',
            'water_sources' => 'hot springs at the eastern end, seasonal streams from Kallidromo',
        ],
        'marathon' => [
            'elevation' => 'coastal plain, 0-20m',
            'terrain_type' => 'open plain with marshland',
            'defensibility' => 'moderate — mountains protect flanks, marsh restricts cavalry',
            'chokepoints' => 'narrow exits between Mount Agrieliki and the Great Marsh',
            'water_sources' => 'the Charadra stream, several wells near Vrana',
        ],
        'plataea' => [
            'elevation' => '200-300m, foothills of Cithaeron',
            'terrain_type' => 'rolling hills with ridgelines',
            'defensibility' => 'strong — ridge positions overlook the Asopos valley',
            'chokepoints' => 'the Dryoscephalae pass through Cithaeron',
            'water_sources' => 'the Gargaphia spring, the Asopos river',
        ],
    ];

    public function __construct(
        private(set) string $location,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $key = strtolower(trim($this->location));
        $data = self::TERRAIN_DATA[$key] ?? null;

        if ($data === null) {
            $known = implode(', ', array_keys(self::TERRAIN_DATA));

            return [
                'location' => $this->location,
                'status' => 'unknown',
                'message' => "No terrain intelligence available for '{$this->location}'. Known locations: {$known}.",
            ];
        }

        return [
            'location' => $this->location,
            ...$data,
        ];
    }
}
