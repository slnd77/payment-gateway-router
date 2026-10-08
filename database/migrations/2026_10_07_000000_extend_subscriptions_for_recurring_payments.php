<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('subscription_id')->nullable()->change();
            $table->dateTime('end_date_time')->nullable()->change();
            $table->string('period')->nullable()->change();
            $table->integer('interval')->nullable()->change();

            if (! Schema::hasColumn('subscriptions', 'plan_name')) {
                $table->string('plan_name')->nullable()->after('plan_id');
            }
            if (! Schema::hasColumn('subscriptions', 'max_amount')) {
                $table->integer('max_amount')->default(0)->after('amount');
            }
            if (! Schema::hasColumn('subscriptions', 'max_cycles')) {
                $table->integer('max_cycles')->nullable()->after('interval');
            }
            if (! Schema::hasColumn('subscriptions', 'auth_amount')) {
                $table->integer('auth_amount')->default(0)->after('max_amount');
            }
            if (! Schema::hasColumn('subscriptions', 'pg_reference_id')) {
                $table->string('pg_reference_id')->nullable()->after('subscription_id')->comment('gateway reference id, e.g. cf_subscription_id');
            }
            if (! Schema::hasColumn('subscriptions', 'authorization_reference')) {
                $table->string('authorization_reference')->nullable()->after('pg_reference_id')->comment('UMRN / UMN / enrollment id');
            }
            if (! Schema::hasColumn('subscriptions', 'next_charge_date_time')) {
                $table->dateTime('next_charge_date_time')->nullable()->after('end_date_time');
            }
            if (! Schema::hasColumn('subscriptions', 'request_data')) {
                $table->json('request_data')->nullable()->after('status');
            }
            if (! Schema::hasColumn('subscriptions', 'response_data')) {
                $table->json('response_data')->nullable()->after('request_data');
            }
        });

        Schema::table('subscriptions_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions_transactions', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('subscriptions_transactions', 'site_reference_id')) {
                $table->string('site_reference_id')->nullable()->after('client_id')->comment('client charge reference');
            }
            if (! Schema::hasColumn('subscriptions_transactions', 'payment_id')) {
                $table->string('payment_id')->nullable()->after('site_reference_id')->comment('internal gateway charge id sent to PG');
            }
            $table->string('transaction_id')->nullable()->change();
            $table->string('payment_method')->nullable()->change();
            $table->dateTime('transaction_date_time')->nullable()->change();
            if (! Schema::hasColumn('subscriptions_transactions', 'remarks')) {
                $table->string('remarks')->nullable()->after('pg_tax');
            }
        });

        // Add index on subscription_id for point lookups
        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index('subscription_id', 'subscriptions_subscription_id_index');
            });
        } catch (Throwable) {
            // Index may already exist
        }

        // Add unique index on client_id and site_reference_id if not present
        // In MySQL / SQLite, index name defaults to subscriptions_transactions_client_id_site_reference_id_unique
        try {
            Schema::table('subscriptions_transactions', function (Blueprint $table) {
                $table->unique(['client_id', 'site_reference_id'], 'sub_txns_client_site_ref_unique');
            });
        } catch (Throwable) {
            // Index may already exist
        }

        // Add indexes on transaction_id and payment_id for gateway webhook/callback lookups
        try {
            Schema::table('subscriptions_transactions', function (Blueprint $table) {
                $table->index('transaction_id', 'sub_txns_transaction_id_index');
                $table->index('payment_id', 'sub_txns_payment_id_index');
            });
        } catch (Throwable) {
            // Indexes may already exist
        }

        Schema::table('payment_gateway_connection_api_logs', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->change();

            if (! Schema::hasColumn('payment_gateway_connection_api_logs', 'subscription_id')) {
                $table->foreignId('subscription_id')->nullable()->after('transaction_id')
                    ->constrained('subscriptions', indexName: 'pg_api_logs_sub_id_fk')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_gateway_connection_api_logs', 'subscription_transaction_id')) {
                $table->foreignId('subscription_transaction_id')->nullable()->after('subscription_id')
                    ->constrained('subscriptions_transactions', indexName: 'pg_api_logs_sub_txn_id_fk')
                    ->nullOnDelete();
            }
        });

        // Ensure foreign keys exist with short names if columns were added without foreign keys
        try {
            Schema::table('payment_gateway_connection_api_logs', function (Blueprint $table) {
                $table->foreign('subscription_id', 'pg_api_logs_sub_id_fk')
                    ->references('id')->on('subscriptions')->nullOnDelete();
            });
        } catch (Throwable) {
            // Already exists or unsupported
        }

        try {
            Schema::table('payment_gateway_connection_api_logs', function (Blueprint $table) {
                $table->foreign('subscription_transaction_id', 'pg_api_logs_sub_txn_id_fk')
                    ->references('id')->on('subscriptions_transactions')->nullOnDelete();
            });
        } catch (Throwable) {
            // Already exists or unsupported
        }

        // Migrate existing subscription_type values if any
        DB::table('subscriptions')->where('subscription_type', 'subscription')->update(['subscription_type' => 'periodic']);
        DB::table('subscriptions')->where('subscription_type', 'recurring')->update(['subscription_type' => 'on_demand']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE `payment_gateway_connection_api_logs` DROP FOREIGN KEY `payment_gateway_connection_api_logs_subscription_id_foreign`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `payment_gateway_connection_api_logs` DROP FOREIGN KEY `pg_api_logs_sub_id_fk`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `payment_gateway_connection_api_logs` DROP FOREIGN KEY `pg_api_logs_sub_txn_id_fk`');
        } catch (Throwable) {
        }

        Schema::table('payment_gateway_connection_api_logs', function (Blueprint $table) {
            if (Schema::hasColumn('payment_gateway_connection_api_logs', 'subscription_transaction_id')) {
                $table->dropColumn('subscription_transaction_id');
            }
            if (Schema::hasColumn('payment_gateway_connection_api_logs', 'subscription_id')) {
                $table->dropColumn('subscription_id');
            }
        });

        try {
            DB::statement('ALTER TABLE `subscriptions_transactions` DROP INDEX `sub_txns_transaction_id_index`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `subscriptions_transactions` DROP INDEX `sub_txns_payment_id_index`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `subscriptions_transactions` DROP INDEX `sub_txns_client_site_ref_unique`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `subscriptions_transactions` DROP INDEX `subscriptions_transactions_client_id_site_reference_id_unique`');
        } catch (Throwable) {
        }

        try {
            DB::statement('ALTER TABLE `subscriptions_transactions` DROP FOREIGN KEY `subscriptions_transactions_client_id_foreign`');
        } catch (Throwable) {
        }

        Schema::table('subscriptions_transactions', function (Blueprint $table) {
            foreach (['client_id', 'site_reference_id', 'payment_id', 'remarks'] as $col) {
                if (Schema::hasColumn('subscriptions_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        try {
            DB::statement('ALTER TABLE `subscriptions` DROP INDEX `subscriptions_subscription_id_index`');
        } catch (Throwable) {
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            foreach ([
                'plan_name',
                'max_amount',
                'max_cycles',
                'auth_amount',
                'pg_reference_id',
                'authorization_reference',
                'next_charge_date_time',
                'request_data',
                'response_data',
            ] as $col) {
                if (Schema::hasColumn('subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
