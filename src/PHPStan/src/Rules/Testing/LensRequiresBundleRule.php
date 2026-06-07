<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Rules\Testing;

use Phalanx\Cancellation\Cancelled;
use Phalanx\PHPStan\Support\RuleErrors;
use Phalanx\PHPStan\Support\TestingPathPolicy;
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
 * @implements Rule<PropertyFetch>
 */
final class LensRequiresBundleRule implements Rule
{
    private const string IDENTIFIER = 'phalanx.testing.lensRequiresBundle';

    private const string TEST_APP_CLASS = 'Phalanx\\Testing\\TestApp';

    private const string TEST_APP_ACCESSORS_TRAIT = 'Phalanx\\Testing\\Generated\\TestAppAccessors';

    /**
     * Lenses always registered by TestApp itself — no bundle required.
     *
     * @var list<class-string>
     */
    private const array DEFAULT_RUNTIME_NATIVE_LENSES = [
        'Phalanx\\Testing\\Lenses\\ConfigLens',
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

    /**
     * @param list<class-string> $runtimeNativeLensClasses
     */
    public function __construct(
        private readonly TestingPathPolicy $paths,
        private readonly array $runtimeNativeLensClasses = self::DEFAULT_RUNTIME_NATIVE_LENSES,
    ) {
    }

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        if (!$this->paths->shouldReport($scope->getFile(), self::IDENTIFIER)) {
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

        // Runtime-native lenses need no bundle — skip immediately.
        if (in_array($lensFqcn, $this->runtimeNativeLensClasses, true)) {
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

        $availableLenses = self::collectAvailableLenses($method);
        if ($availableLenses === null) {
            return [];
        }

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
        } catch (Cancelled $e) {
            throw $e;
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
     * lens class names available through each ServiceBundle argument.
     *
     * Returns null when no testApp() call is found (rule cannot determine state).
     * Returns an empty list when testApp() is called with no bundle args.
     *
     * @return list<string>|null
     */
    private static function collectAvailableLenses(ClassMethod $method): ?array
    {
        $collector = new class extends NodeVisitorAbstract {
            public bool $found = false;

            /** @var list<string> */
            public array $lenses = [];

            /** @var array<string, list<string>> */
            private array $variables = [];

            public function enterNode(\PhpParser\Node $node): null
            {
                if ($node instanceof \PhpParser\Node\Expr\Assign) {
                    $this->recordAssignment($node);

                    return null;
                }

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

                foreach ($node->args as $offset => $arg) {
                    if (!$arg instanceof \PhpParser\Node\Arg) {
                        continue;
                    }

                    if ($offset === 0 && $arg->name === null) {
                        continue;
                    }

                    $this->lenses = [
                        ...$this->lenses,
                        ...self::lensesFromExpression($arg->value, $this->variables),
                    ];
                }

                $this->lenses = array_values(array_unique($this->lenses));

                return null;
            }

            /**
             * @param array<string, list<string>> $variables
             * @return list<string>
             */
            private static function lensesFromExpression(
                \PhpParser\Node\Expr $expr,
                array $variables,
            ): array {
                if ($expr instanceof \PhpParser\Node\Expr\Variable && is_string($expr->name)) {
                    return $variables[$expr->name] ?? [];
                }

                if (!$expr instanceof New_) {
                    return [];
                }

                if ($expr->class instanceof \PhpParser\Node\Name) {
                    return self::lensesFromNamedBundle($expr->class->toString());
                }

                if ($expr->class instanceof \PhpParser\Node\Stmt\Class_) {
                    return self::lensesFromAnonymousBundle($expr->class);
                }

                return [];
            }

            /** @return list<string> */
            private static function lensesFromNamedBundle(string $class): array
            {
                if (!class_exists($class)) {
                    return [];
                }

                if ($class !== ServiceBundle::class && !is_subclass_of($class, ServiceBundle::class)) {
                    return [];
                }

                try {
                    /** @var \Phalanx\Testing\TestLens $collection */
                    $collection = $class::lens();

                    return $collection->all();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (\Throwable) {
                    return [];
                }
            }

            /** @return list<string> */
            private static function lensesFromAnonymousBundle(\PhpParser\Node\Stmt\Class_ $class): array
            {
                if ($class->extends === null) {
                    return [];
                }

                $extends = $class->extends->toString();
                if ($extends !== ServiceBundle::class && !is_subclass_of($extends, ServiceBundle::class)) {
                    return [];
                }

                foreach ($class->getMethods() as $method) {
                    if ($method->name->toString() !== 'lens') {
                        continue;
                    }

                    return self::lensesFromLensMethod($method);
                }

                return [];
            }

            /** @return list<string> */
            private static function lensesFromLensMethod(ClassMethod $method): array
            {
                $collector = new class extends NodeVisitorAbstract {
                    /** @var list<string> */
                    public array $lenses = [];

                    public function enterNode(\PhpParser\Node $node): null
                    {
                        if (!$node instanceof \PhpParser\Node\Expr\StaticCall) {
                            return null;
                        }

                        if (!$node->class instanceof \PhpParser\Node\Name) {
                            return null;
                        }

                        if (!$node->name instanceof Identifier) {
                            return null;
                        }

                        if ($node->class->toString() !== 'Phalanx\\Testing\\TestLens') {
                            return null;
                        }

                        if ($node->name->toString() !== 'of') {
                            return null;
                        }

                        foreach ($node->args as $arg) {
                            if (!$arg instanceof \PhpParser\Node\Arg) {
                                continue;
                            }

                            $value = $arg->value;
                            if (!$value instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                                continue;
                            }

                            if (!$value->class instanceof \PhpParser\Node\Name) {
                                continue;
                            }

                            $this->lenses[] = $value->class->toString();
                        }

                        return null;
                    }
                };

                $traverser = new NodeTraverser();
                $traverser->addVisitor($collector);

                if ($method->stmts !== null) {
                    $traverser->traverse($method->stmts);
                }

                return array_values(array_unique($collector->lenses));
            }

            private function recordAssignment(\PhpParser\Node\Expr\Assign $assign): void
            {
                if (!$assign->var instanceof \PhpParser\Node\Expr\Variable || !is_string($assign->var->name)) {
                    return;
                }

                $lenses = self::lensesFromExpression($assign->expr, $this->variables);
                if ($lenses === []) {
                    return;
                }

                $this->variables[$assign->var->name] = $lenses;
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

        return $collector->lenses;
    }

    /**
     * Build the propertyName -> lensFqcn map once by parsing the generated
     * TestAppAccessors trait. Property hooks in PHP 8.4 emit as:
     *
     *   public Lens $http {
     *       get => $this->lens(Lens::class);
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
        } catch (Cancelled $e) {
            throw $e;
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
