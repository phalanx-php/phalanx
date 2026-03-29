<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Layout;

use Phalanx\Terminal\Buffer\Rect;

final class Layout
{
    /** @return list<Rect> */
    public static function vertical(Rect $area, ConstraintValue ...$constraints): array
    {
        $sizes = self::resolve($area->height, $constraints);

        $rects = [];
        $y = $area->y;

        foreach ($sizes as $size) {
            $rects[] = Rect::of($area->x, $y, $area->width, $size);
            $y += $size;
        }

        return $rects;
    }

    /** @return list<Rect> */
    public static function horizontal(Rect $area, ConstraintValue ...$constraints): array
    {
        $sizes = self::resolve($area->width, $constraints);

        $rects = [];
        $x = $area->x;

        foreach ($sizes as $size) {
            $rects[] = Rect::of($x, $area->y, $size, $area->height);
            $x += $size;
        }

        return $rects;
    }

    /**
     * Single-pass constraint resolution.
     *
     * @param list<ConstraintValue> $constraints
     * @return list<int>
     */
    private static function resolve(int $total, array $constraints): array
    {
        $count = count($constraints);

        if ($count === 0) {
            return [];
        }

        $sizes = array_fill(0, $count, 0);
        $remaining = $total;
        $fillCount = 0;
        $fillIndices = [];

        foreach ($constraints as $i => $c) {
            $allocated = match ($c->kind) {
                Constraint::Length => min($c->value, $remaining),
                Constraint::Percentage => (int) floor($total * $c->value / 100),
                Constraint::Min => $c->value,
                Constraint::Max => 0,
                Constraint::Fill => 0,
            };

            if ($c->kind === Constraint::Fill) {
                $fillCount++;
                $fillIndices[] = $i;
            } else {
                $sizes[$i] = $allocated;
                $remaining -= $allocated;
            }
        }

        if ($fillCount > 0 && $remaining > 0) {
            $perFill = intdiv($remaining, $fillCount);
            $extra = $remaining % $fillCount;

            foreach ($fillIndices as $j => $i) {
                $sizes[$i] = $perFill + ($j < $extra ? 1 : 0);
            }
        }

        foreach ($constraints as $i => $c) {
            if ($c->kind === Constraint::Min) {
                $sizes[$i] = max($sizes[$i], $c->value);
            }

            if ($c->kind === Constraint::Max) {
                $sizes[$i] = min($sizes[$i], $c->value);
            }
        }

        $overrun = array_sum($sizes) - $total;

        if ($overrun > 0 && $fillIndices !== []) {
            foreach (array_reverse($fillIndices) as $i) {
                $reduce = min($overrun, $sizes[$i]);
                $sizes[$i] -= $reduce;
                $overrun -= $reduce;

                if ($overrun <= 0) {
                    break;
                }
            }
        }

        return $sizes;
    }
}
