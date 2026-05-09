<?php

declare(strict_types=1);

namespace Phalanx\Argos\Discovery;

use UnexpectedValueException;

final class MdnsPacket
{
    private const int TYPE_PTR = 12;
    private const int CLASS_IN = 1;

    private function __construct()
    {
    }

    public static function ptrQuery(string $serviceType): string
    {
        return pack(
            'nnnnnn',
            0,
            0,
            1,
            0,
            0,
            0,
        ) . self::encodeName($serviceType) . pack('nn', self::TYPE_PTR, self::CLASS_IN);
    }

    /** @return list<array{name: string, data: string}> */
    public static function ptrAnswers(string $packet): array
    {
        if (strlen($packet) < 12) {
            throw new UnexpectedValueException('DNS packet is shorter than the fixed header.');
        }

        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if ($header === false) {
            throw new UnexpectedValueException('DNS packet header could not be decoded.');
        }

        $offset = 12;
        for ($i = 0; $i < $header['questions']; $i++) {
            self::decodeName($packet, $offset);
            $offset += 4;
        }

        $answers = [];
        for ($i = 0; $i < $header['answers']; $i++) {
            $name = self::decodeName($packet, $offset);
            self::assertAvailable($packet, $offset, 10);

            $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            if ($record === false) {
                throw new UnexpectedValueException('DNS resource record could not be decoded.');
            }

            $offset += 10;
            $dataOffset = $offset;
            self::assertAvailable($packet, $dataOffset, $record['length']);

            if ($record['type'] === self::TYPE_PTR) {
                $answers[] = [
                    'name' => $name,
                    'data' => self::decodeName($packet, $dataOffset),
                ];
            }

            $offset += $record['length'];
        }

        return $answers;
    }

    private static function encodeName(string $name): string
    {
        $labels = explode('.', trim($name, '.'));
        $encoded = '';

        foreach ($labels as $label) {
            $length = strlen($label);
            if ($length > 63) {
                throw new UnexpectedValueException('DNS label exceeds 63 octets.');
            }
            $encoded .= chr($length) . $label;
        }

        return $encoded . "\0";
    }

    private static function decodeName(string $packet, int &$offset, int $depth = 0): string
    {
        if ($depth > 16) {
            throw new UnexpectedValueException('DNS name compression pointer depth exceeded.');
        }

        $labels = [];

        while (true) {
            self::assertAvailable($packet, $offset, 1);
            $length = ord($packet[$offset]);

            if ($length === 0) {
                $offset++;
                return implode('.', $labels);
            }

            if (($length & 0xC0) === 0xC0) {
                self::assertAvailable($packet, $offset, 2);
                $pointer = (($length & 0x3F) << 8) | ord($packet[$offset + 1]);
                $offset += 2;

                return implode('.', [
                    ...$labels,
                    self::decodeName($packet, $pointer, $depth + 1),
                ]);
            }

            if (($length & 0xC0) !== 0) {
                throw new UnexpectedValueException('DNS name uses an unsupported label encoding.');
            }

            $offset++;
            self::assertAvailable($packet, $offset, $length);
            $labels[] = substr($packet, $offset, $length);
            $offset += $length;
        }
    }

    private static function assertAvailable(string $packet, int $offset, int $length): void
    {
        if ($offset < 0 || $offset + $length > strlen($packet)) {
            throw new UnexpectedValueException('DNS packet ended before the record was complete.');
        }
    }
}
