<?php

namespace App\Listeners;

use App\Services\CaReference\CaReferenceMigrateGuard;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Abort migrate --database=ca_reference unless --path points at dedicated migrations.
 */
class GuardCaReferenceMigratePath
{
    public function handle(CommandStarting $event): void
    {
        $input = $event->input;
        if (! $input instanceof InputInterface) {
            return;
        }

        $command = $this->resolveCommandName($event->command, $input);
        if (! in_array($command, ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'], true)) {
            return;
        }

        $database = $this->resolveOption($input, 'database');
        if ($database !== 'ca_reference') {
            return;
        }

        if (in_array($command, ['migrate:fresh', 'migrate:refresh', 'migrate:reset'], true)) {
            throw new \RuntimeException(
                'Refusing '.$command.' on ca_reference. Never wipe the reference database with migrate:fresh/refresh/reset.'
            );
        }

        $path = $this->resolveOption($input, 'path');

        if ($command === 'migrate:rollback') {
            if (! CaReferenceMigrateGuard::pathIsAllowed($path)) {
                throw new \RuntimeException(
                    'Refusing migrate:rollback on ca_reference without --path='.CaReferenceMigrateGuard::expectedPath()
                );
            }

            return;
        }

        CaReferenceMigrateGuard::assertMigrationPaths('ca_reference', $path);
    }

    private function resolveCommandName(string $eventCommand, InputInterface $input): string
    {
        if ($eventCommand !== '') {
            return $eventCommand;
        }

        $first = $input->getFirstArgument();

        return is_string($first) ? $first : '';
    }

    private function resolveOption(InputInterface $input, string $name): mixed
    {
        try {
            if ($input->hasOption($name)) {
                $value = $input->getOption($name);
                if ($value !== null && $value !== false && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // Definition may not be bound yet.
        }

        $long = '--'.$name;
        if ($input->hasParameterOption($long, true)) {
            return $input->getParameterOption($long, null, true);
        }

        try {
            $ref = new \ReflectionObject($input);
            if ($ref->hasProperty('parameters')) {
                $prop = $ref->getProperty('parameters');
                $prop->setAccessible(true);
                $params = $prop->getValue($input);
                if (is_array($params) && array_key_exists($long, $params)) {
                    return $params[$long];
                }
                if (is_array($params) && array_key_exists($name, $params)) {
                    return $params[$name];
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }
}
