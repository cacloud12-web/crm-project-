<?php

namespace App\Support\Queue;

use Illuminate\Foundation\Bus\PendingDispatch;

class QueueDispatcher
{
    public static function usesSyncConnection(): bool
    {
        return config('queue.default') === 'sync';
    }

    public static function shouldUseBackgroundWorker(): bool
    {
        return ! self::usesSyncConnection();
    }

    /**
     * Run immediately when QUEUE_CONNECTION=sync; otherwise dispatch to the queue.
     */
    public static function dispatchOrRun(object $job): void
    {
        if (self::usesSyncConnection()) {
            dispatch_sync($job);

            return;
        }

        dispatch($job);
    }

    public static function dispatch(object $job): PendingDispatch
    {
        return dispatch($job);
    }
}
