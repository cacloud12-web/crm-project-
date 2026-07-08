<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_list_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_list_entry_id')
                ->constrained('sales_list_entries')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('field_name', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('edited_at');
            $table->timestamps();

            $table->index(['sales_list_entry_id', 'edited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_list_edit_histories');
    }
};
