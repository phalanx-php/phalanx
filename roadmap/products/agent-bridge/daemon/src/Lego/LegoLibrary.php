<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

/**
 * File-based lego storage. One subdirectory per domain, one JSON file per lego.
 *
 * Domain names are sanitised before use as directory components: path separators
 * and ".." sequences become underscores to prevent directory traversal.
 * Lego names undergo a tighter sanitisation -- only alphanumerics, dashes, and
 * underscores survive -- so they are safe as bare filenames.
 */
final class LegoLibrary
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /** @return list<LegoDefinition> */
    public function forDomain(string $domain): array
    {
        $dir = $this->domainPath($domain);

        if (!is_dir($dir)) {
            return [];
        }

        $legos = [];

        foreach ((array) glob("{$dir}/*.json") as $file) {
            $raw  = (string) file_get_contents((string) $file);
            /** @var array<string, mixed> $data */
            $data   = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $legos[] = LegoDefinition::fromArray($data);
        }

        return $legos;
    }

    public function get(string $domain, string $name): ?LegoDefinition
    {
        $file = $this->legoPath($domain, $name);

        if (!file_exists($file)) {
            return null;
        }

        $raw  = (string) file_get_contents($file);
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return LegoDefinition::fromArray($data);
    }

    public function save(LegoDefinition $lego): void
    {
        $dir = $this->domainPath($lego->domain);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->legoPath($lego->domain, $lego->name),
            json_encode($lego->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    public function delete(string $domain, string $name): void
    {
        $file = $this->legoPath($domain, $name);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function countForDomain(string $domain): int
    {
        $dir = $this->domainPath($domain);

        if (!is_dir($dir)) {
            return 0;
        }

        return count((array) glob("{$dir}/*.json"));
    }

    private function domainPath(string $domain): string
    {
        $safe = str_replace(['/', '\\', '..'], '_', $domain);

        return $this->basePath . '/' . $safe;
    }

    private function legoPath(string $domain, string $name): string
    {
        $safeName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        return $this->domainPath($domain) . "/{$safeName}.json";
    }
}
