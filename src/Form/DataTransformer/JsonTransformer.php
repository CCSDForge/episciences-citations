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
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new TransformationFailedException('Decoded JSON payload must be an array.');
            }
            return $decoded;
        } catch (JsonException $exception) {
            throw new TransformationFailedException('Invalid JSON payload for reference field.', 0, $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function transform($value): mixed
    {
        if (empty($value)) {
            return json_encode([]);
        }

        return json_encode($value);
    }
}
