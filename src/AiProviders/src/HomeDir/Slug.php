<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir;

/**
 * Encodes and decodes filesystem paths to/from the slug format Claude Code
 * uses for its `~/.claude/projects/` directory names.
 *
 * Encoding replaces every `/` with `-`, so `/srv/phalanx/agora` becomes
 * `-srv-phalanx-agora`. Decoding is the naive inverse: every `-` is
 * replaced with `/`.
 *
 * Lossiness: encoding is NOT a round-trip when the original path contains
 * literal `-` characters (e.g. `/home/user/my-project` encodes to
 * `-home-user-my-project`, which decodes to `/home/user/my/project`).
 * This mirrors Claude Code's own behavior — projects with hyphens in their
 * cwd are still discoverable; the slug is used only for directory naming,
 * not for canonical path identity.
 *
 * Final — static-only utility; no extension surface.
 */
final class Slug
{
    private function __construct()
    {
    }

    /**
     * Encode an absolute filesystem path to its Claude Code slug form.
     * A leading `/` becomes a leading `-`; subsequent `/` characters are
     * also replaced with `-`.
     */
    public static function encode(string $absolutePath): string
    {
        return str_replace('/', '-', $absolutePath);
    }

    /**
     * Decode a Claude Code project slug back to an absolute path.
     * Every `-` is replaced with `/`. This is the inverse of
     * {@see self::encode()} for paths that contain no literal hyphens.
     */
    public static function decode(string $slug): string
    {
        return str_replace('-', '/', $slug);
    }
}
