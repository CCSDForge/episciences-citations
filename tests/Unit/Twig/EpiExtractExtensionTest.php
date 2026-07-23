<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\EpiExtractExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EpiExtractExtensionTest extends TestCase
{
    private EpiExtractExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new EpiExtractExtension();
    }

    #[Test]
    public function testExtendsAbstractExtension(): void
    {
        $this->assertInstanceOf(AbstractExtension::class, $this->extension);
    }

    #[Test]
    public function testGetFunctions_ReturnsFormatcslFunction(): void
    {
        $functions = $this->extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('formatcsl', $functions[0]->getName());
    }

    #[Test]
    public function testGetFunctions_FormatcslCallableTargetsExtensionInstance(): void
    {
        // Note: the extension does not itself define a `formatcsl` method — this
        // registration only works if a `formatcsl` method is added later or the
        // callable is otherwise resolved. Documenting the current (broken) wiring
        // rather than invoking the callable, which would fatal with
        // "Call to undefined method".
        $functions = $this->extension->getFunctions();
        [$target, $method] = $functions[0]->getCallable();

        $this->assertSame($this->extension, $target);
        $this->assertSame('formatcsl', $method);
        $this->assertFalse(method_exists($target, $method), 'formatcsl is not implemented on EpiExtractExtension');
    }
}
