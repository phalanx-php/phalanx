<?php

declare(strict_types=1);

namespace Phalanx\Testing\Codegen;

use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\Attribute\Lens as LensAttribute;
use Phalanx\Testing\TestApp;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;

final class LensDiscovery
{
    private const string AEGIS_LENSES_CONSTANT = 'AEGIS_NATIVE_LENSES';

    /**
     * @param list<class-string<ServiceBundle>> $declaredBundles
     * @return list<LensMetadata>
     */
    public function discover(array $declaredBundles): array
    {
        $byAccessor = [];

        foreach ($this->aegisNativeLenses() as $lensClass) {
            $metadata = $this->reflectLens($lensClass);
            $this->record($byAccessor, $metadata);
        }

        foreach ($declaredBundles as $bundleClass) {
            if (!is_subclass_of($bundleClass, ServiceBundle::class)) {
                throw new RuntimeException(
                    "{$bundleClass} is declared in extra.phalanx.bundles but does not extend "
                    . ServiceBundle::class . '.',
                );
            }

            foreach ($bundleClass::lens()->all() as $lensClass) {
                $metadata = $this->reflectLens($lensClass);
                $this->record($byAccessor, $metadata);
            }
        }

        ksort($byAccessor);

        return array_values($byAccessor);
    }

    /** @return list<class-string<\Phalanx\Testing\Lens>> */
    private function aegisNativeLenses(): array
    {
        $reflection = new ReflectionClass(TestApp::class);

        if (!$reflection->hasConstant(self::AEGIS_LENSES_CONSTANT)) {
            return [];
        }

        $value = $reflection->getConstant(self::AEGIS_LENSES_CONSTANT);

        if (!is_array($value)) {
            return [];
        }

        /** @var list<class-string<\Phalanx\Testing\Lens>> $value */
        return array_values($value);
    }

    /** @param class-string<\Phalanx\Testing\Lens> $lensClass */
    private function reflectLens(string $lensClass): LensMetadata
    {
        $reflection = new ReflectionClass($lensClass);
        $attributes = $reflection->getAttributes(LensAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new RuntimeException(
                "Lens {$lensClass} is missing the #[\\Phalanx\\Testing\\Attribute\\Lens] attribute.",
            );
        }

        $attribute = $attributes[0]->newInstance();

        return new LensMetadata(
            accessor: $attribute->accessor,
            lensClass: $lensClass,
            factoryClass: $attribute->factory,
        );
    }

    /**
     * @param array<string, LensMetadata> $byAccessor
     */
    private function record(array &$byAccessor, LensMetadata $metadata): void
    {
        if (isset($byAccessor[$metadata->accessor])) {
            $existing = $byAccessor[$metadata->accessor];

            if ($existing->lensClass === $metadata->lensClass) {
                return;
            }

            throw new RuntimeException(
                "Duplicate test lens accessor '{$metadata->accessor}': "
                . "{$existing->lensClass} and {$metadata->lensClass} both claim it. "
                . 'Rename one of the lens accessors.',
            );
        }

        $byAccessor[$metadata->accessor] = $metadata;
    }
}
