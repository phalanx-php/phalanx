<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Scaffold;

use Phalanx\Cli\Scaffold\TemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    #[Test]
    public function replacesPlaceholders(): void
    {
        $result = TemplateRenderer::render(
            'Hello, {{name}}! Welcome to {{framework}}.',
            ['name' => 'Leonidas', 'framework' => 'Phalanx'],
        );

        self::assertSame('Hello, Leonidas! Welcome to Phalanx.', $result);
    }

    #[Test]
    public function handlesMultipleOccurrences(): void
    {
        $result = TemplateRenderer::render(
            '{{x}} and {{x}}',
            ['x' => 'alpha'],
        );

        self::assertSame('alpha and alpha', $result);
    }

    #[Test]
    public function leavesUnmatchedPlaceholdersAlone(): void
    {
        $result = TemplateRenderer::render(
            '{{known}} and {{unknown}}',
            ['known' => 'value'],
        );

        self::assertSame('value and {{unknown}}', $result);
    }

    #[Test]
    public function emptyVariablesReturnTemplateUnchanged(): void
    {
        $template = 'no variables here';

        self::assertSame($template, TemplateRenderer::render($template, []));
    }

    #[Test]
    public function handlesEscapedNamespace(): void
    {
        $result = TemplateRenderer::render(
            '"{{namespace_escaped}}\\\\": "src/"',
            ['namespace_escaped' => 'App\\\\MyProject'],
        );

        self::assertSame('"App\\\\MyProject\\\\": "src/"', $result);
    }
}
