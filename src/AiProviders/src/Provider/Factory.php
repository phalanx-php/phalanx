<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider;

use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Transport;
use ReflectionClass;
use ReflectionParameter;

final class Factory
{
    public static function create(
        Resolution $resolution,
        Transport $transport,
        ?string $apiKey = null,
    ): Provider {
        $wireTranslator = $resolution->config->wireTranslator;

        if ($wireTranslator === null) {
            throw FactoryError::missingWireTranslator($resolution->config->id);
        }

        $ref = new ReflectionClass($wireTranslator);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            throw FactoryError::noConstructor($wireTranslator);
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $args[] = self::resolveParameter($param, $resolution, $transport, $apiKey);
        }

        /** @var Provider */
        return $ref->newInstanceArgs($args);
    }

    private static function resolveParameter(
        ReflectionParameter $param,
        Resolution $resolution,
        Transport $transport,
        ?string $apiKey,
    ): mixed {
        $name = $param->getName();

        return match ($name) {
            'transport' => $transport,
            'apiKey' => self::resolveApiKey($param, $apiKey, $resolution->config->id),
            'model' => $resolution->model,
            'baseUrl' => $resolution->config->baseUrl ?? self::paramDefault($param, $resolution->config->id, 'baseUrl'),
            'defaultHeaders' => $resolution->config->defaultHeaders !== [] ? $resolution->config->defaultHeaders : self::paramDefault($param, $resolution->config->id, 'defaultHeaders'),
            default => self::paramDefault($param, $resolution->config->id, $name),
        };
    }

    private static function resolveApiKey(ReflectionParameter $param, ?string $apiKey, string $providerId): string
    {
        if ($apiKey !== null) {
            return $apiKey;
        }

        throw FactoryError::missingApiKey($providerId);
    }

    private static function paramDefault(ReflectionParameter $param, string $providerId, string $name): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw FactoryError::missingParameter($providerId, $name);
    }
}
