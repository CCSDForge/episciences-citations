<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\DataTransformer;

use App\Form\DataTransformer\JsonTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

class JsonTransformerTest extends TestCase
{
    private JsonTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new JsonTransformer();
    }

    #[Test]
    public function testTransform_ArrayValue_ReturnsJsonString(): void
    {
        $value = ['raw_reference' => 'Smith, J. (2020). Title.', 'doi' => '10.1234/test'];

        $result = $this->transformer->transform($value);

        $this->assertJsonStringEqualsJsonString(json_encode($value), $result);
    }

    #[Test]
    public function testTransform_EmptyArray_ReturnsEmptyJsonArray(): void
    {
        $result = $this->transformer->transform([]);

        $this->assertEquals('[]', $result);
    }

    #[Test]
    public function testTransform_Null_ReturnsEmptyJsonArray(): void
    {
        $result = $this->transformer->transform(null);

        $this->assertEquals('[]', $result);
    }

    #[Test]
    public function testTransform_EmptyString_ReturnsEmptyJsonArray(): void
    {
        $result = $this->transformer->transform('');

        $this->assertEquals('[]', $result);
    }

    #[Test]
    public function testReverseTransform_ValidJsonString_ReturnsDecodedArray(): void
    {
        $value = ['raw_reference' => 'Doe, J. (2021). Another Title.', 'doi' => '10.5678/test'];
        $json = json_encode($value);

        $result = $this->transformer->reverseTransform($json);

        $this->assertEquals($value, $result);
    }

    #[Test]
    public function testReverseTransform_Null_ReturnsEmptyArray(): void
    {
        $result = $this->transformer->reverseTransform(null);

        $this->assertEquals([], $result);
    }

    #[Test]
    public function testReverseTransform_EmptyString_ReturnsEmptyArray(): void
    {
        $result = $this->transformer->reverseTransform('');

        $this->assertEquals([], $result);
    }

    #[Test]
    public function testReverseTransform_ZeroString_ReturnsEmptyArray(): void
    {
        // '0' is falsy in PHP, so empty('0') === true — documents this edge case
        $result = $this->transformer->reverseTransform('0');

        $this->assertEquals([], $result);
    }

    #[Test]
    public function testReverseTransform_MalformedJson_ThrowsTransformationFailedException(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform('not valid json {{{');
    }

    #[Test]
    public function testRoundTrip_TransformThenReverseTransform_PreservesData(): void
    {
        $value = ['raw_reference' => 'Round trip test', 'doi' => '10.1/rt', 'nested' => ['a' => 1, 'b' => [1, 2, 3]]];

        $json = $this->transformer->transform($value);
        $result = $this->transformer->reverseTransform($json);

        $this->assertEquals($value, $result);
    }
}
