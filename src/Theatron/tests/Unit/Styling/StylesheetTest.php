<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Styling;

use Phalanx\Theatron\Layout\Border;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Styling\Stylesheet;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StylesheetTest extends TestCase
{
    #[Test]
    public function emptyStylesheetMatchesNothing(): void
    {
        $sheet = Stylesheet::of([]);

        self::assertNull($sheet->match(ElementType::Text));
    }

    #[Test]
    public function rootMatchesAnyType(): void
    {
        $style = Style::of(border: Border::Single);
        $sheet = Stylesheet::of(['root' => $style]);

        self::assertSame($style, $sheet->match(ElementType::Text));
        self::assertSame($style, $sheet->match(ElementType::Row));
        self::assertSame($style, $sheet->match(ElementType::Panel));
    }

    #[Test]
    public function typeMatchesSpecificElement(): void
    {
        $textStyle = Style::of(color: Color::red());
        $sheet = Stylesheet::of(['text' => $textStyle]);

        self::assertSame($textStyle, $sheet->match(ElementType::Text));
        self::assertNull($sheet->match(ElementType::Row));
    }

    #[Test]
    public function typeOverridesRoot(): void
    {
        $rootStyle = Style::of(border: Border::Single);
        $textStyle = Style::of(color: Color::red());
        $sheet = Stylesheet::of([
            'root' => $rootStyle,
            'text' => $textStyle,
        ]);

        self::assertSame($textStyle, $sheet->match(ElementType::Text));
        self::assertSame($rootStyle, $sheet->match(ElementType::Row));
    }

    #[Test]
    public function variantMatching(): void
    {
        $activeStyle = Style::of(background: Color::hex('#333333'));
        $sheet = Stylesheet::of(['row:active' => $activeStyle]);

        self::assertSame($activeStyle, $sheet->match(ElementType::Row, variant: 'active'));
        self::assertNull($sheet->match(ElementType::Row));
        self::assertNull($sheet->match(ElementType::Row, variant: 'hover'));
    }

    #[Test]
    public function roleMatching(): void
    {
        $nameStyle = Style::of(color: Color::hex('#88ccff'));
        $sheet = Stylesheet::of(['text.name' => $nameStyle]);

        self::assertSame($nameStyle, $sheet->match(ElementType::Text, role: 'name'));
        self::assertNull($sheet->match(ElementType::Text));
        self::assertNull($sheet->match(ElementType::Text, role: 'title'));
    }

    #[Test]
    public function specificityOrder(): void
    {
        $root = Style::of(border: Border::None);
        $type = Style::of(border: Border::Single);
        $variant = Style::of(border: Border::Double);
        $role = Style::of(border: Border::Rounded);
        $full = Style::of(border: Border::Heavy);

        $sheet = Stylesheet::of([
            'root' => $root,
            'row' => $type,
            'row:active' => $variant,
            'row.header' => $role,
            'row:active.header' => $full,
        ]);

        self::assertSame($full, $sheet->match(ElementType::Row, role: 'header', variant: 'active'));
        self::assertSame($role, $sheet->match(ElementType::Row, role: 'header'));
        self::assertSame($variant, $sheet->match(ElementType::Row, variant: 'active'));
        self::assertSame($type, $sheet->match(ElementType::Row));
        self::assertSame($root, $sheet->match(ElementType::Text));
    }

    #[Test]
    public function noMatchReturnsNull(): void
    {
        $sheet = Stylesheet::of(['panel' => Style::of(border: Border::Single)]);

        self::assertNull($sheet->match(ElementType::Text));
        self::assertNull($sheet->match(ElementType::Row, role: 'header'));
    }

    #[Test]
    public function rootFallbackWhenTypeMissing(): void
    {
        $rootStyle = Style::of(color: Color::hex('#ffffff'));
        $sheet = Stylesheet::of([
            'root' => $rootStyle,
            'panel' => Style::of(border: Border::Double),
        ]);

        self::assertSame($rootStyle, $sheet->match(ElementType::Text, role: 'title'));
    }

    #[Test]
    public function variantWithRoleFallsToRoleThenVariantThenType(): void
    {
        $roleStyle = Style::of(color: Color::red());
        $sheet = Stylesheet::of([
            'text.label' => $roleStyle,
        ]);

        self::assertSame($roleStyle, $sheet->match(ElementType::Text, role: 'label', variant: 'active'));
    }

    #[Test]
    public function fullMatchMissingFallsToVariant(): void
    {
        $variantStyle = Style::of(color: Color::hex('#aabbcc'));
        $sheet = Stylesheet::of([
            'row:active' => $variantStyle,
        ]);

        self::assertSame($variantStyle, $sheet->match(ElementType::Row, role: 'header', variant: 'active'));
    }

    #[Test]
    public function bothRoleAndVariantMissFallsToType(): void
    {
        $typeStyle = Style::of(border: Border::Single);
        $sheet = Stylesheet::of([
            'row' => $typeStyle,
        ]);

        self::assertSame($typeStyle, $sheet->match(ElementType::Row, role: 'header', variant: 'active'));
    }

    #[Test]
    public function ruleKeysAreCaseSensitive(): void
    {
        $sheet = Stylesheet::of(['Text' => Style::of(border: Border::Single)]);

        self::assertNull($sheet->match(ElementType::Text));
    }
}
