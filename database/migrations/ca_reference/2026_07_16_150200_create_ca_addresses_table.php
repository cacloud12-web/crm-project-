<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('ca_addresses')) {
            return;
        }

        Schema::connection($this->connection)->create('ca_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('ca_firms')->cascadeOnDelete();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('pin_code', 12)->nullable();
            $table->string('country', 120)->default('India');
            $table->timestamps();

            $table->index('firm_id');
            $table->index(['state', 'city', 'pin_code']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ca_addresses');
    }
};
