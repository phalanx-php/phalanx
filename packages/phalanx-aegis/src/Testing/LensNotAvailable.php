<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use LogicException;

/**
 * Thrown when userland accesses a lens accessor on TestApp without
 * registering the providing bundle.
 *
 * This is a programming error, not a runtime error: the test class
 * forgot to pass the bundle to TestApp::boot(...). The exception
 * message names the missing lens and the bundles whose registration
 * would activate it.
 */
final class LensNotAvailable extends LogicException
{
    /**
     * @param class-string<TestLens>             $lens
     * @param list<class-string<TestableBundle>> $providers Bundles known to this
     *        TestApp instance that declare the lens. Empty when no bundle
     *        passed to TestApp::boot() listed it.
     */
    public function __construct(string $lens, array $providers = [])
    {
        $message = "Lens {$lens} is not registered on this TestApp.";

        if ($providers !== []) {
            $list = implode(', ', $providers);
            $message .= " Pass one of these bundles to TestApp::boot(): {$list}.";
        } else {
            $message .= ' Pass a TestableBundle that declares this lens to TestApp::boot().';
        }

        parent::__construct($message);
    }
}
