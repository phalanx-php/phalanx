<?php

declare(strict_types=1);

namespace Phalanx\Http\OpenApi;

use Phalanx\Http\Contract\InputHydrator;
use Phalanx\Http\Contract\InputSource;
use Phalanx\Http\Route;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteGroup;
use Phalanx\SelfDescribed;
use Phalanx\Tagged;
use ReflectionClass;
use ReflectionNamedType;

class OpenApiGenerator
{
    public function __construct(
        private readonly string $title = 'API',
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
    ) {}

    /** @return array<string, mixed> */
    public function generate(RouteGroup $routes): array
    {
        $paths = [];
        $schemas = [];

        foreach ($routes->handlers()->all() as $key => $handler) {
            $config = $handler->config;

            if (!$config instanceof RouteConfig) {
                continue;
            }

            $task = $handler->task;
            $method = strtolower($config->methods[0] ?? 'get');
            $openApiPath = self::toOpenApiPath($config->path);

            $paths[$openApiPath] ??= [];
            $paths[$openApiPath][$method] = $this->buildOperation($task, $config);
        }

        $info = ['title' => $this->title, 'version' => $this->version];
        if ($this->description !== null) {
            $info['description'] = $this->description;
        }

        $spec = [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $paths ?: new \stdClass(),
        ];

        return $spec;
    }

    /** @return array<string, mixed> */
    protected function buildOperation(mixed $task, RouteConfig $config): array
    {
        $operation = [];

        $originalHandler = $task instanceof Route ? $task->fn : $task;
        $callable = $task instanceof Route ? $task->callable : $task;

        if ($originalHandler instanceof SelfDescribed) {
            $operation['summary'] = $originalHandler->description;
        }

        if ($originalHandler instanceof Tagged) {
            $tags = $originalHandler->tags;
            if ($tags !== []) {
                $operation['tags'] = $tags;
            }
        }

        $pathParams = self::extractPathParams($config);
        $inputMeta = InputHydrator::meta($callable);
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

        $operation['responses'] = $this->buildResponses($callable, $config, $inputMeta !== null);

        return $operation;
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

    /** @return array<string, mixed> */
    protected function buildResponses(
        mixed $task,
        RouteConfig $config,
        bool $hasInput,
    ): array {
        $responses = [];

        $ref = self::reflectReturnType($task);

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

    private static function reflectReturnType(mixed $task): ?\ReflectionType
    {
        if ($task instanceof \Closure) {
            return (new \ReflectionFunction($task))->getReturnType();
        }

        if (is_object($task)) {
            $ref = new ReflectionClass($task);
            if ($ref->hasMethod('__invoke')) {
                return $ref->getMethod('__invoke')->getReturnType();
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
