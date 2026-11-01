<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('upload_sessions')) {
            DB::statement('ALTER TABLE upload_sessions MODIFY expires_at TIMESTAMP NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        // Revert not required
    }
};
