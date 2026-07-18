<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class UsersTableSchema
{
    public static function ensureSoftDeletesColumn(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
}
