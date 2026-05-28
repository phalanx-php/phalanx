<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

use BgAgents\Config\ModelDefaults;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads markdown specs with YAML frontmatter from a directory.
 *
 * The Markdown body becomes the specialist's identity prompt. Frontmatter
 * provides structured config: name, addressing, model, subscription, etc.
 *
 * Pure invokable — no scope dependency, easy to test in isolation.
 */
final readonly class SpecialistLoader
{
    public function __construct(
        public ModelDefaults $defaults,
    ) {}

    /**
     * @return array<string, Specialist>  keyed by name
     * @throws SpecLoadException
     */
    public function loadAll(string $dir): array
    {
        if (!is_dir($dir)) {
            throw SpecLoadException::for($dir, 'directory does not exist');
        }

        $specs = [];
        $entries = glob(rtrim($dir, '/') . '/*.md') ?: [];
        foreach ($entries as $path) {
            $spec = $this->loadOne($path);
            if (isset($specs[$spec->name])) {
                throw SpecLoadException::for($path, "duplicate specialist name: {$spec->name}");
            }
            $specs[$spec->name] = $spec;
        }

        return $specs;
    }

    public function loadOne(string $path): Specialist
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw SpecLoadException::for($path, 'unreadable file');
        }

        [$frontmatter, $body] = self::splitFrontmatter($path, $contents);

        try {
            $head = Yaml::parse($frontmatter) ?? [];
        } catch (ParseException $e) {
            throw SpecLoadException::for($path, 'invalid YAML frontmatter: ' . $e->getMessage(), $e);
        }
        if (!is_array($head)) {
            throw SpecLoadException::for($path, 'frontmatter must be a map');
        }

        $name = $head['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = pathinfo($path, PATHINFO_FILENAME);
        }

        $addressing = $head['addressing'] ?? [];
        if (!is_array($addressing)) {
            $addressing = [];
        }
        /** @var list<string> $addressList */
        $addressList = array_values(array_filter($addressing, is_string(...)));

        $subscriptionRaw = $head['subscription'] ?? [];
        if (!is_array($subscriptionRaw)) {
            $subscriptionRaw = [];
        }

        $rag = $head['rag'] ?? [];
        if (!is_array($rag)) {
            $rag = [];
        }

        $ragTags = $rag['tags'] ?? [];
        $ragTopics = $rag['topics'] ?? [];

        return new Specialist(
            name: $name,
            addressing: $addressList,
            provider: is_string($head['provider'] ?? null) ? $head['provider'] : 'anthropic',
            model: is_string($head['model'] ?? null) ? $head['model'] : $this->modelDefaultFor($name),
            temperature: (float) ($head['temperature'] ?? 0.5),
            identityPrompt: trim($body),
            subscription: SubscriptionFilter::fromArray($subscriptionRaw),
            ragTags: is_array($ragTags) ? array_values(array_filter($ragTags, is_string(...))) : [],
            ragTopics: is_array($ragTopics) ? array_values(array_filter($ragTopics, is_string(...))) : [],
            description: is_string($head['description'] ?? null) ? $head['description'] : '',
            sourcePath: $path,
        );
    }

    private function modelDefaultFor(string $name): string
    {
        return match ($name) {
            'supervisor' => $this->defaults->supervisor,
            default => $this->defaults->specialist,
        };
    }

    /** @return array{0: string, 1: string} */
    private static function splitFrontmatter(string $path, string $contents): array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");
        if (!str_starts_with($contents, '---')) {
            throw SpecLoadException::for($path, 'missing YAML frontmatter (file must start with ---)');
        }

        $lines = explode("\n", $contents);
        $end = null;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (rtrim($lines[$i]) === '---') {
                $end = $i;
                break;
            }
        }

        if ($end === null) {
            throw SpecLoadException::for($path, 'unterminated YAML frontmatter');
        }

        $head = implode("\n", array_slice($lines, 1, $end - 1));
        $body = implode("\n", array_slice($lines, $end + 1));

        return [$head, $body];
    }
}
