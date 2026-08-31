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
        Schema::table('clients_connections', function (Blueprint $table) {
            $table->boolean('self_redirect')->default(true)->after('transaction_type')
                ->comment('If true, app redirects client to bank url. If false, app returns bank url as json.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients_connections', function (Blueprint $table) {
            $table->dropColumn('self_redirect');
        });
    }
};
