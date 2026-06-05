<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

use Phalanx\Tui\Tui\Drawing\Rect;

final class SizeResolver
{
    /**
     * @param list<Size> $sizes
     * @return list<Rect>
     */
    public static function vertical(Rect $area, array $sizes): array
    {
        $resolved = self::resolve($area->height, $sizes);

        $rects = [];
        $y = $area->y;

        foreach ($resolved as $size) {
            $rects[] = Rect::of($area->x, $y, $area->width, $size);
            $y += $size;
        }

        return $rects;
    }

    /**
     * @param list<Size> $sizes
     * @return list<Rect>
     */
    public static function horizontal(Rect $area, array $sizes): array
    {
        $resolved = self::resolve($area->width, $sizes);

        $rects = [];
        $x = $area->x;

        foreach ($resolved as $size) {
            $rects[] = Rect::of($x, $area->y, $size, $area->height);
            $x += $size;
        }

        return $rects;
    }

    /**
     * @param list<Size> $sizes
     * @return list<int>
     */
    public static function resolve(int $total, array $sizes): array
    {
        $count = count($sizes);

        if ($count === 0) {
            return [];
        }

        $allocated = array_fill(0, $count, 0);
        $remaining = $total;
        $fractionalTotal = 0;
        $fractionalIndices = [];

        foreach ($sizes as $i => $size) {
            $amount = match ($size->kind) {
                SizeKind::Fixed => min($size->value, max(0, $remaining)),
                SizeKind::Percent => min((int) floor($total * $size->value / 100), max(0, $remaining)),
                SizeKind::Between => min($size->value, max(0, $remaining)),
                SizeKind::Fill => 0,
                SizeKind::Fractional => 0,
            };

            if ($size->kind === SizeKind::Fill) {
                $fractionalTotal += 1;
                $fractionalIndices[] = $i;
            } elseif ($size->kind === SizeKind::Fractional) {
                $fractionalTotal += $size->value;
                $fractionalIndices[] = $i;
            } else {
                $allocated[$i] = $amount;
                $remaining -= $amount;
            }
        }

        if ($remaining > 0) {
            $betweenIndices = [];

            foreach ($sizes as $i => $size) {
                if ($size->kind === SizeKind::Between) {
                    $headroom = $size->max - $allocated[$i];

                    if ($headroom > 0) {
                        $betweenIndices[] = $i;
                    }
                }
            }

            if ($betweenIndices !== []) {
                $betweenShare = $remaining;

                foreach ($betweenIndices as $j => $i) {
                    $headroom = $sizes[$i]->max - $allocated[$i];
                    $portion = (int) floor($betweenShare / (count($betweenIndices) - $j));
                    $give = min($portion, $headroom);
                    $allocated[$i] += $give;
                    $remaining -= $give;
                }
            }
        }

        if ($fractionalTotal > 0 && $remaining > 0) {
            $distributed = 0;

            foreach ($fractionalIndices as $j => $i) {
                $weight = $sizes[$i]->kind === SizeKind::Fill ? 1 : $sizes[$i]->value;
                $share = (int) floor($remaining * $weight / $fractionalTotal);

                if ($j === count($fractionalIndices) - 1) {
                    $share = $remaining - $distributed;
                }

                $allocated[$i] = $share;
                $distributed += $share;
            }
        }

        $overrun = array_sum($allocated) - $total;

        if ($overrun > 0 && $fractionalIndices !== []) {
            foreach (array_reverse($fractionalIndices) as $i) {
                $reduce = min($overrun, $allocated[$i]);
                $allocated[$i] -= $reduce;
                $overrun -= $reduce;

                if ($overrun <= 0) {
                    break;
                }
            }
        }

        return array_values($allocated);
    }
}
