<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supported_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('pg_class');
            $table->json('attributes');
            $table->boolean('status')->default(true)->comment('1: active, 0: inactive');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supported_payment_gateways');
    }
};
