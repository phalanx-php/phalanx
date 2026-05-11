<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\Service\ServiceBundle;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

/**
 * Flags TestApp lens accessor reads that have no corresponding ServiceBundle
 * registered via testApp(...). Each accessor (e.g. $app->http) requires a
 * bundle whose static::lens() declares the backing lens class (e.g. HttpLens).
 *
 * Aegis-native lenses (LedgerLens, ScopeLens, RuntimeLens) are always
 * available and are never flagged regardless of the bundles passed.
 *
 * The rule is path-gated to integration/feature test directories, matching
 * the same policy as UseTestAppRule.
 *
 * @implements Rule<PropertyFetch>
 */
final class LensRequiresBundleRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.lensRequiresBundle';

    private const array TEST_DIRECTORIES = [
        '/tests/Integration/',
        '/tests/Feature/',
    ];

    private const string TEST_APP_CLASS = 'Phalanx\\Testing\\TestApp';

    private const string TEST_APP_ACCESSORS_TRAIT = 'Phalanx\\Testing\\Generated\\TestAppAccessors';

    /**
     * Lenses always registered by TestApp itself — no bundle required.
     *
     * @var list<class-string>
     */
    private const array AEGIS_NATIVE_LENSES = [
        'Phalanx\\Testing\\Lenses\\LedgerLens',
        'Phalanx\\Testing\\Lenses\\ScopeLens',
        'Phalanx\\Testing\\Lenses\\RuntimeLens',
    ];

    /** @var array<string, string>|null propertyName -> lensFqcn, null means not yet built */
    private ?array $accessorMap = null;

    /**
     * Cache of parsed file methods: filePath -> list<ClassMethod>.
     *
     * @var array<string, list<ClassMethod>>
     */
    private array $fileMethodCache = [];

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!self::isInTestDirectory($scope->getFile())) {
            return [];
        }

        $objectType = $scope->getType($node->var);
        if (!(new ObjectType(self::TEST_APP_CLASS))->isSuperTypeOf($objectType)->yes()) {
            return [];
        }

        if (!$node->name instanceof Identifier) {
            return [];
        }

        $property = $node->name->toString();
        $map = $this->resolveAccessorMap();

        if (!array_key_exists($property, $map)) {
            return [];
        }

        $lensFqcn = $map[$property];

        // Aegis-native lenses need no bundle — skip immediately.
        if (in_array($lensFqcn, self::AEGIS_NATIVE_LENSES, true)) {
            return [];
        }

        $methodName = self::enclosingMethodName($scope);
        if ($methodName === null) {
            return [];
        }

        $method = $this->findMethodInFile($scope->getFile(), $methodName);
        if ($method === null) {
            return [];
        }

        $bundleClasses = self::collectBundleClasses($method);
        if ($bundleClasses === null) {
            return [];
        }

        $availableLenses = self::aggregateLenses($bundleClasses);

        if (in_array($lensFqcn, $availableLenses, true)) {
            return [];
        }

        return RuleErrors::build(
            sprintf(
                'Property $app->%s returns %s which requires a ServiceBundle whose static::lens() declares it. '
                . 'None of the bundles passed to testApp() include this lens.',
                $property,
                $lensFqcn,
            ),
            self::IDENTIFIER,
            $node->getStartLine(),
        );
    }

    private static function enclosingMethodName(Scope $scope): ?string
    {
        $fn = $scope->getFunction();
        if (!$fn instanceof MethodReflection) {
            return null;
        }

        return $fn->getName();
    }

    /**
     * Locate the generated TestAppAccessors trait file via the Composer
     * autoloader to avoid PHPStan's runtime-reflection restriction.
     */
    private static function locateAccessorTraitFile(): ?string
    {
        foreach (spl_autoload_functions() as $loader) {
            if (!is_array($loader)) {
                continue;
            }

            $instance = reset($loader);
            if (!$instance instanceof \Composer\Autoload\ClassLoader) {
                continue;
            }

            $path = $instance->findFile(self::TEST_APP_ACCESSORS_TRAIT);
            if ($path !== false && $path !== null) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }

    /**
     * Parse a PHP file and collect all ClassMethod nodes with names resolved.
     *
     * @return list<ClassMethod>
     */
    private static function parseFileMethods(string $filePath): array
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            return [];
        }

        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $stmts = $parser->parse($source) ?? [];
        } catch (\Throwable) {
            return [];
        }

        // Resolve names so class-strings in `new Foo()` become FQCNs.
        $nameTraverser = new NodeTraverser();
        $nameTraverser->addVisitor(new NameResolver());
        $stmts = $nameTraverser->traverse($stmts);

        $collector = new class extends NodeVisitorAbstract {
            /** @var list<ClassMethod> */
            public array $methods = [];

            public function enterNode(\PhpParser\Node $node): null
            {
                if ($node instanceof ClassMethod) {
                    $this->methods[] = $node;
                }
                return null;
            }
        };

        $collectTraverser = new NodeTraverser();
        $collectTraverser->addVisitor($collector);
        $collectTraverser->traverse($stmts);

        return $collector->methods;
    }

    /**
     * Scan the method body for `$this->testApp(...)` calls and collect the
     * class names of `new Foo()` bundle arguments.
     *
     * Returns null when no testApp() call is found (rule cannot determine state).
     * Returns an empty list when testApp() is called with no bundle args.
     *
     * @return list<string>|null
     */
    private static function collectBundleClasses(ClassMethod $method): ?array
    {
        $collector = new class extends NodeVisitorAbstract {
            public bool $found = false;
            /** @var list<string> */
            public array $classes = [];

            public function enterNode(\PhpParser\Node $node): null
            {
                if (!$node instanceof MethodCall) {
                    return null;
                }

                if (!$node->name instanceof Identifier) {
                    return null;
                }

                if ($node->name->toString() !== 'testApp') {
                    return null;
                }

                $this->found = true;

                // @dev-cleanup-ignore — VariadicPlaceholder nodes have no $value; skip non-Arg entries
                foreach ($node->args as $arg) {
                    if (!$arg instanceof \PhpParser\Node\Arg) {
                        continue;
                    }

                    $value = $arg->value;
                    if (!$value instanceof New_) {
                        continue;
                    }

                    if (!$value->class instanceof \PhpParser\Node\Name) {
                        continue;
                    }

                    $className = $value->class->toString();

                    if (!self::isServiceBundle($className)) {
                        continue;
                    }

                    $this->classes[] = $className;
                }

                return null;
            }

            private static function isServiceBundle(string $class): bool
            {
                if (!class_exists($class)) {
                    return false;
                }

                return $class === ServiceBundle::class
                    || is_subclass_of($class, ServiceBundle::class);
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);

        if ($method->stmts !== null) {
            $traverser->traverse($method->stmts);
        }

        if (!$collector->found) {
            return null;
        }

        return $collector->classes;
    }

    /**
     * Call static::lens() on each bundle class and collect the lens FQCNs.
     * Bundles that are not loadable or that throw are silently skipped.
     *
     * @param list<string> $bundleClasses
     * @return list<string>
     */
    private static function aggregateLenses(array $bundleClasses): array
    {
        $lenses = [];

        foreach ($bundleClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            try {
                /** @var \Phalanx\Testing\TestLens $collection */
                $collection = $class::lens();
                foreach ($collection->all() as $lensFqcn) {
                    $lenses[] = $lensFqcn;
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique($lenses));
    }

    private static function isInTestDirectory(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach (self::TEST_DIRECTORIES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the propertyName -> lensFqcn map once by parsing the generated
     * TestAppAccessors trait. Property hooks in PHP 8.4 emit as:
     *
     *   public HttpLens $http {
     *       get => $this->lens(HttpLens::class);
     *   }
     *
     * PhpParser's NameResolver resolves the short class name to its FQCN via
     * the use-import list at the top of the generated file.
     *
     * @return array<string, string>
     */
    private function resolveAccessorMap(): array
    {
        if ($this->accessorMap !== null) {
            return $this->accessorMap;
        }

        $traitFile = self::locateAccessorTraitFile();

        if ($traitFile === null || !file_exists($traitFile)) {
            $this->accessorMap = [];
            return $this->accessorMap;
        }

        $source = file_get_contents($traitFile);
        if ($source === false) {
            $this->accessorMap = [];
            return $this->accessorMap;
        }

        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $stmts = $parser->parse($source) ?? [];
        } catch (\Throwable) {
            $this->accessorMap = [];
            return $this->accessorMap;
        }

        // Resolve use-imports so class names become FQCNs.
        $nameTraverser = new NodeTraverser();
        $nameTraverser->addVisitor(new NameResolver());
        $stmts = $nameTraverser->traverse($stmts);

        $extractor = new class extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $map = [];

            public function enterNode(\PhpParser\Node $node): null
            {
                if (!$node instanceof \PhpParser\Node\Stmt\Property) {
                    return null;
                }

                foreach ($node->props as $prop) {
                    $propName = $prop->name->name;

                    foreach ($node->hooks as $hook) {
                        if ($hook->name->name !== 'get') {
                            continue;
                        }

                        // The hook body is the MethodCall `$this->lens(LensFqcn::class)`.
                        $body = $hook->body;
                        if (!$body instanceof MethodCall) {
                            continue;
                        }

                        $arg = $body->args[0]->value ?? null;
                        if (!$arg instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                            continue;
                        }

                        if (!$arg->class instanceof \PhpParser\Node\Name) {
                            continue;
                        }

                        $this->map[$propName] = $arg->class->toString();
                    }
                }

                return null;
            }
        };

        $extractTraverser = new NodeTraverser();
        $extractTraverser->addVisitor($extractor);
        $extractTraverser->traverse($stmts);

        $this->accessorMap = $extractor->map;
        return $this->accessorMap;
    }

    /**
     * Find a ClassMethod by name in the parsed method cache for the given file.
     * Parses and caches on first access per file.
     */
    private function findMethodInFile(string $filePath, string $methodName): ?ClassMethod
    {
        if (!array_key_exists($filePath, $this->fileMethodCache)) {
            $this->fileMethodCache[$filePath] = self::parseFileMethods($filePath);
        }

        foreach ($this->fileMethodCache[$filePath] as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        return null;
    }
}
