<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Effect;

/**
 * Normalized kinds of effects an agent may request and that authorizers
 * evaluate against grants. The closed set covers the common surface; the
 * `Custom` case carries an opaque string for vendor-specific or host-defined
 * effects (e.g. `vault.note.append`, `surreal.query`).
 */
enum Kind: string
{
    case FileRead = 'file.read';
    case FileWrite = 'file.write';
    case FileList = 'file.list';
    case ShellExec = 'shell.exec';
    case WebFetch = 'web.fetch';
    case CodeSearch = 'code.search';
    case ProviderCall = 'provider.call';
    case MemoryWrite = 'memory.write';
    case KnowledgeWrite = 'knowledge.write';
    case Custom = 'custom';
}
