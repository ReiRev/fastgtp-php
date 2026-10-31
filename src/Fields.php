<?php

declare(strict_types=1);

namespace FastGtp;

use UnexpectedValueException;

/**
 * Reads the fields of a decoded response, and refuses one that is missing
 * or of the wrong type, so that a response unlike fastgtp's cannot become
 * a value with empty fields.
 *
 * Decoded into PHP arrays, a nested JSON [] and {} look the same, and so do
 * a list and an object with keys "0", "1" and so on. Those are accepted; a
 * response from fastgtp never has them in the wrong place.
 *
 * @internal
 */
final class Fields
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private readonly array $data) {}

    public function string(string $key): string
    {
        return is_string($this->data[$key] ?? null) ? $this->data[$key] : throw self::bad($key);
    }

    /** A string that can be one segment of a path, as an id from the server must be. */
    public function id(string $key): string
    {
        $value = $this->string($key);

        return in_array($value, ['', '.', '..'], true) ? throw self::bad($key) : $value;
    }

    public function int(string $key): int
    {
        return is_int($this->data[$key] ?? null) ? $this->data[$key] : throw self::bad($key);
    }

    public function float(string $key): float
    {
        $value = $this->data[$key] ?? null;

        // JSON has one number type, so a komi of 7 arrives as an int.
        return is_int($value) || is_float($value) ? (float) $value : throw self::bad($key);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw self::bad($key);
        }

        return $value;
    }

    /** @return list<array<string, mixed>> A list of objects, kept as arrays. */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw self::bad($key);
        }
        foreach ($value as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw self::bad($key);
            }
        }

        return $value;
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) && ($value === [] || !array_is_list($value)) ? new self($value) : throw self::bad($key);
    }

    private static function bad(string $key): UnexpectedValueException
    {
        return new UnexpectedValueException("The response has no valid \"$key\"");
    }
}
