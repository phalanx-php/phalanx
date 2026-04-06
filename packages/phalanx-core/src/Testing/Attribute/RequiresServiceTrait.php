<?php

declare(strict_types=1);

namespace Phalanx\Testing\Attribute;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

trait RequiresServiceTrait
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluateServiceRequirements();
    }

    private function evaluateServiceRequirements(): void
    {
        $classAttrs = (new ReflectionClass($this))
            ->getAttributes(RequiresService::class, ReflectionAttribute::IS_INSTANCEOF);

        foreach ($classAttrs as $attr) {
            $requirement = $attr->newInstance();
            if (!$requirement->isAvailable()) {
                $this->markTestSkipped($requirement->skipMessage());
            }
        }

        $methodAttrs = (new ReflectionMethod($this, $this->name()))
            ->getAttributes(RequiresService::class, ReflectionAttribute::IS_INSTANCEOF);

        foreach ($methodAttrs as $attr) {
            $requirement = $attr->newInstance();
            if (!$requirement->isAvailable()) {
                $this->markTestSkipped($requirement->skipMessage());
            }
        }
    }
}
