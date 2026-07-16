<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        Schema::connection($this->connection)->create('mapping_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('ca_firms')->cascadeOnDelete();
            $table->unsignedBigInteger('crm_record_id')->nullable();
            $table->string('mapping_type', 50);
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['mapping_type', 'status']);
            $table->index('crm_record_id');
            $table->index('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('mapping_logs');
    }
};
