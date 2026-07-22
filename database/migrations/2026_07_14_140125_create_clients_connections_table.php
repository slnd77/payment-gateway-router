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
        Schema::create('clients_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('pg_connection_id')->constrained();
            $table->boolean('pg_fees_recovery')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->string('type')->default('TEST')->comment('TEST, PRODUCTION');
            $table->boolean('status')->default(true)->comment('1: active, 0: inactive');
            $table->dateTime('deleted_at')->nullable()->comment('deleted at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients_connections');
    }
};
