<?php

declare(strict_types=1);

namespace Phalanx\Stoa\OpenApi;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Stoa\Contract\InputHydrator;
use Phalanx\Stoa\Contract\InputSource;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteGroup;
use ReflectionClass;
use ReflectionNamedType;

class OpenApiGenerator
{
    public function __construct(
        private readonly string $title = 'API',
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(RouteGroup $routes): array
    {
        $paths = [];

        foreach ($routes->handlers()->all() as $handler) {
            $config = $handler->config;

            if (!$config instanceof RouteConfig) {
                continue;
            }

            $handlerClass = $handler->task;
            $method = strtolower($config->methods[0] ?? 'get');
            $openApiPath = self::toOpenApiPath($config->path);

            $paths[$openApiPath] ??= [];
            $paths[$openApiPath][$method] = $this->buildOperation($handlerClass, $config);
        }

        $info = ['title' => $this->title, 'version' => $this->version];
        if ($this->description !== null) {
            $info['description'] = $this->description;
        }

        return [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $paths !== [] ? $paths : new \stdClass(),
        ];
    }

    /**
     * @param class-string $handlerClass
     * @return array<string, mixed>
     */
    protected function buildOperation(string $handlerClass, RouteConfig $config): array
    {
        $operation = [];

        $ref = new ReflectionClass($handlerClass);

        $description = self::readPropertyHook($ref, 'description');
        if ($description !== null) {
            $operation['summary'] = $description;
        }

        $tags = self::readPropertyHook($ref, 'tags');
        if (is_array($tags) && $tags !== []) {
            $operation['tags'] = $tags;
        }

        $pathParams = self::extractPathParams($config);
        $inputMeta = InputHydrator::meta($handlerClass);
        $source = InputSource::fromMethod($config->methods[0] ?? 'GET');

        $parameters = $pathParams;

        if ($inputMeta !== null && $source === InputSource::Query) {
            $queryParams = self::buildQueryParams($inputMeta->inputClass);
            $parameters = [...$parameters, ...$queryParams];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($inputMeta !== null && $source === InputSource::Body) {
            $operation['requestBody'] = self::buildRequestBody($inputMeta->inputClass);
        }

        $operation['responses'] = $this->buildResponses($handlerClass, $config, $inputMeta !== null);

        return $operation;
    }

    /**
     * @param class-string $handlerClass
     * @return array<string, mixed>
     */
    protected function buildResponses(
        string $handlerClass,
        RouteConfig $config,
        bool $hasInput,
    ): array {
        /** @var array<string, mixed> $responses */
        $responses = [];

        $ref = self::reflectInvokeReturnType($handlerClass);

        if ($ref instanceof ReflectionNamedType) {
            [$status, $schema] = SchemaReflector::unwrapResponseWrapper($ref);
        } else {
            $status = 200;
            $schema = null;
        }

        $successResponse = ['description' => self::statusDescription($status)];
        if ($schema !== null && $status !== 204) {
            $successResponse['content'] = [
                'application/json' => ['schema' => $schema],
            ];
        }

        $responses[(string) $status] = $successResponse;

        if ($hasInput) {
            $responses['422'] = [
                'description' => 'Validation Failed',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'error' => ['type' => 'string'],
                                'errors' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($config->paramNames !== []) {
            $responses['404'] = ['description' => 'Not Found'];
        }

        return $responses;
    }

    /** @return list<array<string, mixed>> */
    private static function extractPathParams(RouteConfig $config): array
    {
        $params = [];

        foreach ($config->paramNames as $name) {
            $params[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $params;
    }

    /**
     * @param class-string $dtoClass
     * @return list<array<string, mixed>>
     */
    private static function buildQueryParams(string $dtoClass): array
    {
        $ref = new ReflectionClass($dtoClass);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $params = [];

        foreach ($constructor->getParameters() as $param) {
            $schema = SchemaReflector::returnTypeSchema($param->getType()) ?? ['type' => 'string'];
            $required = !$param->isOptional() && !$param->getType()?->allowsNull();

            $paramDef = [
                'name' => $param->getName(),
                'in' => 'query',
                'schema' => $schema,
            ];

            if ($required) {
                $paramDef['required'] = true;
            }

            $params[] = $paramDef;
        }

        return $params;
    }

    /**
     * @param class-string $dtoClass
     * @return array<string, mixed>
     */
    private static function buildRequestBody(string $dtoClass): array
    {
        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => SchemaReflector::classSchema($dtoClass),
                ],
            ],
        ];
    }

    /**
     * @param class-string $handlerClass
     */
    private static function reflectInvokeReturnType(string $handlerClass): ?\ReflectionType
    {
        $ref = new ReflectionClass($handlerClass);

        if (!$ref->hasMethod('__invoke')) {
            return null;
        }

        return $ref->getMethod('__invoke')->getReturnType();
    }

    /**
     * Read a property-hook value from a handler class for OpenAPI generation.
     *
     * Tries the static default first (cheap, works for backed properties).
     * Falls back to instantiating the class IF its constructor has no
     * required parameters -- handlers with constructor dependencies cannot
     * be inspected this way because OpenAPI generation does not have access
     * to the service container (it is a build-time / boot-time operation
     * by design).
     *
     * @param ReflectionClass<object> $ref
     */
    private static function readPropertyHook(ReflectionClass $ref, string $name): mixed
    {
        if (!$ref->hasProperty($name)) {
            return null;
        }

        // Try default value first (works for backed properties).
        $defaults = $ref->getDefaultProperties();
        if (array_key_exists($name, $defaults) && $defaults[$name] !== null) {
            return $defaults[$name];
        }

        // For property hooks with no constructor deps, instantiate to read.
        $constructor = $ref->getConstructor();
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            try {
                $instance = $ref->newInstance();
                $prop = $ref->getProperty($name);
                return $prop->getValue($instance);
            } catch (Cancelled $c) {
                throw $c;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function toOpenApiPath(string $path): string
    {
        return preg_replace('/\{(\w+)(?::[^}]+)?}/', '{$1}', $path) ?? $path;
    }

    private static function statusDescription(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            default => 'Success',
        };
    }
}
