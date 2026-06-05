<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Reactive\Resource;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\SignalRegistry;
use Phalanx\Tui\Reactive\Store;
use ReflectionClass;
use ReflectionNamedType;

final class SignalScanner
{
    /** @param array<string, mixed> $runtimeParams */
    public static function scan(
        object $component,
        DirtyBatch $batch,
        array $runtimeParams = [],
        ?SignalRegistry $registry = null,
    ): SignalScanResult {
        $ownedSignals = [];
        $subscriptions = [];
        $storeSubscriptions = [];
        $renderIgnoredReactives = [];

        /** @var array<int, true> */
        $borrowed = [];
        foreach ($runtimeParams as $value) {
            if ($value instanceof Signal) {
                $borrowed[spl_object_id($value)] = true;
            }
        }

        $ref = new ReflectionClass($component);
        foreach ($ref->getProperties() as $prop) {
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            if ($typeName === Signal::class) {
                $value = $prop->getValue($component);
                if (!$value instanceof Signal) {
                    continue;
                }

                $subscriptions[] = $value->subscribe(static function () use ($batch): void {
                    $batch->request();
                });
                $renderIgnoredReactives[] = $value;

                if (!isset($borrowed[spl_object_id($value)])) {
                    $ownedSignals[] = $value;
                    $registry?->register($value, $ref->getShortName() . '::' . $prop->getName());
                }
            } elseif ($typeName === Resource::class) {
                $value = $prop->getValue($component);
                if (!$value instanceof Resource) {
                    continue;
                }

                $subscriptions[] = $value->subscribe(static function () use ($batch): void {
                    $batch->request();
                });
                $renderIgnoredReactives[] = $value;
            } elseif (is_a($typeName, Store::class, true)) {
                $value = $prop->getValue($component);
                if (!$value instanceof Store) {
                    continue;
                }

                $storeSubscriptions[] = $value->subscribe(static function () use ($batch): void {
                    $batch->request();
                });
            }
        }

        return new SignalScanResult($ownedSignals, $subscriptions, $storeSubscriptions, $renderIgnoredReactives);
    }
}
