<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Ensures large OCR PDFs are accepted under `php artisan serve`.
 * The built-in server re-spawns PHP without inheriting `php -c`, so inject -d flags.
 */
#[AsCommand(name: 'serve')]
class ServeCommand extends BaseServeCommand
{
    /**
     * @return array<int, string>
     */
    protected function serverCommand(): array
    {
        $command = parent::serverCommand();
        // Always allow large Master CA PDFs (westprop ~6MB+); never trust default 2M PHP.
        $uploadMb = max(128, (int) config('document-ai.max_file_mb', 100));
        $upload = $uploadMb.'M';
        $post = max($uploadMb + 28, 160).'M';

        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize='.$upload,
            '-d', 'post_max_size='.$post,
            '-d', 'memory_limit=512M',
        ]);

        return $command;
    }
}
