<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        Schema::connection($this->connection)->create('ca_firms', function (Blueprint $table) {
            $table->id();
            $table->string('firm_name');
            $table->string('frn', 60)->nullable();
            $table->string('firm_type', 40)->nullable();
            $table->unsignedInteger('partner_count')->default(0);
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('pin_code', 12)->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 190)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index('firm_name');
            $table->index('frn');
            $table->index('gst_number');
            $table->index(['state', 'city']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ca_firms');
    }
};
