<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Throwable;

class VerifyCaReferenceDatabaseCommand extends Command
{
    protected $signature = 'ca-reference:verify';

    protected $description = 'Verify CA Reference database connection and required tables';

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'ca_firms',
        'ca_partners',
        'ca_addresses',
        'ocr_import_logs',
        'ocr_processing_logs',
        'mapping_logs',
    ];

    /** @var list<string> */
    private const REQUIRED_ENV_KEYS = [
        'CA_REFERENCE_DB_HOST',
        'CA_REFERENCE_DB_PORT',
        'CA_REFERENCE_DB_DATABASE',
        'CA_REFERENCE_DB_USERNAME',
    ];

    public function handle(): int
    {
        if (! array_key_exists('ca_reference', Config::get('database.connections', []))) {
            $this->error('The ca_reference connection is not defined in config/database.php.');

            return self::FAILURE;
        }

        $missing = [];
        foreach (self::REQUIRED_ENV_KEYS as $key) {
            $value = trim((string) env($key, ''));
            if ($value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            $this->error('Missing required CA reference environment values:');
            foreach ($missing as $key) {
                $this->line('  - '.$key);
            }

            return self::FAILURE;
        }

        if (env('CA_REFERENCE_DB_PASSWORD') === null) {
            $this->warn('CA_REFERENCE_DB_PASSWORD is not set. Connection may fail if the database requires a password.');
        }

        try {
            $connection = DB::connection('ca_reference');
            $connection->getPdo();
            $databaseName = (string) $connection->getDatabaseName();
        } catch (QueryException|PDOException $exception) {
            $this->error($this->safeConnectionError($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('CA reference database connection failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('CA reference database connection successful.');
        $this->line('Database: '.$databaseName);

        $schema = Schema::connection('ca_reference');
        $present = [];
        $absent = [];

        foreach (self::REQUIRED_TABLES as $table) {
            if ($schema->hasTable($table)) {
                $present[] = $table;
            } else {
                $absent[] = $table;
            }
        }

        if ($absent === []) {
            $this->info('Required tables: present ('.count($present).'/'.count(self::REQUIRED_TABLES).')');

            return self::SUCCESS;
        }

        $this->warn('Required tables: missing ('.count($absent).'/'.count(self::REQUIRED_TABLES).')');
        foreach ($absent as $table) {
            $this->line('  - missing: '.$table);
        }
        if ($present !== []) {
            $this->line('Present: '.implode(', ', $present));
        }

        $this->newLine();
        $this->line('Run migrations after credentials are confirmed:');
        $this->line('  php artisan migrate --database=ca_reference --force');

        return self::FAILURE;
    }

    private function safeConnectionError(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'access denied')) {
            return 'Access denied for CA reference database user. Check CA_REFERENCE_DB_USERNAME and CA_REFERENCE_DB_PASSWORD.';
        }

        if (str_contains($message, 'connection refused') || str_contains($message, 'could not connect')) {
            return 'Connection refused. Check CA_REFERENCE_DB_HOST and CA_REFERENCE_DB_PORT.';
        }

        if (str_contains($message, 'unknown database')) {
            return 'Unknown database. Check CA_REFERENCE_DB_DATABASE.';
        }

        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known')) {
            return 'Could not resolve CA_REFERENCE_DB_HOST.';
        }

        return 'CA reference database connection failed. Verify CA_REFERENCE_DB_* values in .env.';
    }
}
