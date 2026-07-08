<?php

namespace App\Support\Security;

class TextSanitizer
{
    /**
     * Strip HTML/script content and normalize whitespace for safe plain-text storage.
     */
    public static function plain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $value) ?? '';
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function sanitizeKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = self::plain($data[$key]);
            }
        }

        return $data;
    }
}
