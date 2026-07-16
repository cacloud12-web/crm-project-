<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ca_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('ca_firms')->cascadeOnDelete();
            $table->string('partner_name');
            $table->string('membership_number', 60)->nullable();
            $table->string('designation', 80)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('membership_number');
            $table->index('partner_name');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ca_partners');
    }
};
