<?php

namespace App\Services\CaReference;

/**
 * Shared path checks for ca_reference migrate commands / migrator hooks.
 */
class CaReferenceMigrateGuard
{
    public static function expectedPath(): string
    {
        return (string) config('ca_reference.migrations_path', 'database/migrations/ca_reference');
    }

    public static function pathIsAllowed(mixed $path): bool
    {
        if ($path === null || $path === '' || $path === []) {
            return false;
        }

        $expected = self::expectedPath();
        $paths = is_array($path) ? $path : [$path];
        foreach ($paths as $candidate) {
            $normalized = str_replace('\\', '/', trim((string) $candidate));
            $normalized = preg_replace('#^\./#', '', $normalized) ?? $normalized;
            $expectedNorm = str_replace('\\', '/', $expected);
            if ($normalized === $expectedNorm || str_ends_with($normalized, '/'.$expectedNorm) || str_ends_with($normalized, $expectedNorm)) {
                return true;
            }
            if (str_contains($normalized, '/'.$expectedNorm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]|string  $paths
     */
    public static function assertMigrationPaths(string $connection, mixed $paths): void
    {
        if ($connection !== 'ca_reference') {
            return;
        }

        if (self::pathIsAllowed($paths)) {
            return;
        }

        $expected = self::expectedPath();
        throw new \RuntimeException(
            "Refusing to run default migrations against ca_reference.\n".
            "Use ONLY:\n".
            "  php artisan migrate --database=ca_reference --path={$expected} --force\n".
            'This guard prevents accidental CRM schema from being applied to the reference database.'
        );
    }
}
