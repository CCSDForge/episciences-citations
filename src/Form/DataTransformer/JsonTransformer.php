<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use JsonException;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Handles transforming json to array and backward
 *
 * @implements DataTransformerInterface<array<string, mixed>, string>
 */
class JsonTransformer implements DataTransformerInterface
{

    /**
     * @inheritDoc
     */
    public function reverseTransform($value): mixed
    {
        if (empty($value)) {
            return [];
        }

        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TransformationFailedException('Invalid JSON payload for reference field.', 0, $exception);
        }
    }

    /**
     * @ihneritdoc
     */
    public function transform($value): mixed
    {
        if (empty($value)) {
            return json_encode([]);
        }

        return json_encode($value);
    }
}
