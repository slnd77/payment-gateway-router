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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('name');
            $table->string('client_id')->unique();
            $table->string('client_secret')->unique();
            $table->string('website');
            $table->string('redirect_uri');
            $table->string('redirect_uri_separator', 1)->default('?');
            $table->string('webhook_uri')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('status')->default(true);
            $table->json('data')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
