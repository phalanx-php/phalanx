<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Tool;

final class ConsultOracle
{
    private const array PROPHECIES = [
        'The river that flows two ways shall decide the fate of the march. Seek the ford where herons gather at dawn.',
        'Three shields broken before the sun reaches its zenith — yet the fourth holds, and victory follows.',
        'The enemy you see is not the enemy you face. Look to the hills where no fires burn.',
        'When the phalanx turns, it does not retreat — it reveals what stood behind it all along.',
        'Bronze alone does not win wars. The commander who waters his horses before battle drinks from the cup of victory.',
    ];

    public function __construct(
        private(set) string $question,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $index = abs(crc32($this->question)) % count(self::PROPHECIES);
        $prophecy = self::PROPHECIES[$index];

        return [
            'oracle' => 'Pythia of Delphi',
            'question' => $this->question,
            'prophecy' => $prophecy,
            'confidence' => 'cryptic',
        ];
    }
}
