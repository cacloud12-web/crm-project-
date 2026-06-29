<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->unsignedBigInteger('bulk_action_id')->nullable()->after('source_id');
            $table->foreign('bulk_action_id')
                ->references('bulk_action_id')
                ->on('bulk_actions')
                ->nullOnDelete();
            $table->index('bulk_action_id', 'ca_masters_bulk_action_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropForeign(['bulk_action_id']);
            $table->dropIndex('ca_masters_bulk_action_id_index');
            $table->dropColumn('bulk_action_id');
        });
    }
};
