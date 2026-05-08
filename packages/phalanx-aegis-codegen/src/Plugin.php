<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Codegen;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;

/**
 * Composer plugin that regenerates Phalanx\Testing\Generated\TestAppAccessors
 * after every autoload dump.
 *
 * Discovery sources:
 *
 *   - Aegis-native lenses (always-on; declared on Phalanx\Testing\TestApp).
 *   - Lens classes declared in any installed package's
 *     `composer.json` extra.phalanx.bundles list.
 *
 * Usage in a userland project:
 *
 *   composer require --dev phalanx-php/aegis-codegen
 *
 * The plugin runs automatically on `composer install` /
 * `composer dump-autoload`. To regenerate manually, run
 * `composer dump-autoload`.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const string TARGET_RELATIVE_PATH
        = 'phalanx-php/aegis/src/Testing/Generated/TestAppAccessors.php';

    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No persistent state to clean up. Generated trait is left in place
        // for the consuming project to remove if desired.
    }

    public function onPostAutoloadDump(Event $event): void
    {
        $composer = $this->composer ?? $event->getComposer();
        $io = $this->io ?? $event->getIO();

        $bundles = $this->collectDeclaredBundles($composer);

        try {
            $lenses = new LensDiscovery()->discover($bundles);
            $target = $this->resolveTargetPath($composer);

            new AccessorTraitWriter()->write($lenses, $target);

            $io->write(
                sprintf(
                    '<info>phalanx-aegis-codegen</info>: regenerated TestAppAccessors with %d lens accessor(s)',
                    count($lenses),
                ),
            );
        } catch (RuntimeException $e) {
            $io->writeError("<error>phalanx-aegis-codegen: {$e->getMessage()}</error>");

            throw $e;
        }
    }

    private static function detectMonorepoTarget(Composer $composer): ?string
    {
        $rootDir = dirname((string) $composer->getConfig()->getConfigSource()->getName());
        $candidate = $rootDir . '/packages/phalanx-aegis/src/Testing/Generated/TestAppAccessors.php';

        if (is_dir(dirname($candidate))) {
            return $candidate;
        }

        return null;
    }

    /** @return list<class-string<\Phalanx\Testing\TestableBundle>> */
    private function collectDeclaredBundles(Composer $composer): array
    {
        $declared = [];

        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();

            if (!isset($extra['phalanx']['bundles']) || !is_array($extra['phalanx']['bundles'])) {
                continue;
            }

            foreach ($extra['phalanx']['bundles'] as $bundleClass) {
                if (is_string($bundleClass)) {
                    /** @var class-string<\Phalanx\Testing\TestableBundle> $bundleClass */
                    $declared[] = $bundleClass;
                }
            }
        }

        // Root package's own bundle declarations
        $rootExtra = $composer->getPackage()->getExtra();

        if (isset($rootExtra['phalanx']['bundles']) && is_array($rootExtra['phalanx']['bundles'])) {
            foreach ($rootExtra['phalanx']['bundles'] as $bundleClass) {
                if (is_string($bundleClass)) {
                    /** @var class-string<\Phalanx\Testing\TestableBundle> $bundleClass */
                    $declared[] = $bundleClass;
                }
            }
        }

        return array_values(array_unique($declared));
    }

    private function resolveTargetPath(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        if (!is_string($vendorDir)) {
            throw new RuntimeException('Composer vendor-dir is not configured.');
        }

        // Inside the Phalanx monorepo, phalanx-aegis is replaced rather than
        // installed under vendor/. Fall back to the in-repo path so codegen
        // still updates the source file during development.
        $vendorTarget = $vendorDir . DIRECTORY_SEPARATOR . self::TARGET_RELATIVE_PATH;

        if (is_dir(dirname($vendorTarget))) {
            return $vendorTarget;
        }

        $monorepoTarget = self::detectMonorepoTarget($composer);

        if ($monorepoTarget !== null) {
            return $monorepoTarget;
        }

        throw new RuntimeException(
            'Cannot locate phalanx-aegis Generated/ directory. Expected '
            . $vendorTarget . ' or a monorepo packages/phalanx-aegis path.',
        );
    }
}
