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
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasColumn('purchases', 'settled_at')) {
                    $table->timestamp('settled_at')->nullable()->after('price');
                }
                if (!Schema::hasColumn('purchases', 'settled_admin_share')) {
                    $table->decimal('settled_admin_share', 10, 2)->nullable()->after('settled_at');
                }
                if (!Schema::hasColumn('purchases', 'settled_creator_share')) {
                    $table->decimal('settled_creator_share', 10, 2)->nullable()->after('settled_admin_share');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (Schema::hasColumn('purchases', 'settled_creator_share')) {
                    $table->dropColumn('settled_creator_share');
                }
                if (Schema::hasColumn('purchases', 'settled_admin_share')) {
                    $table->dropColumn('settled_admin_share');
                }
                if (Schema::hasColumn('purchases', 'settled_at')) {
                    $table->dropColumn('settled_at');
                }
            });
        }
    }
};
