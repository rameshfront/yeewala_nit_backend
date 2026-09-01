<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add wallet fields to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'amount')) {
                $table->decimal('amount', 10, 2)->default(0.00)->after('password');
            }
            if (!Schema::hasColumn('users', 'earnings')) {
                $table->decimal('earnings', 10, 2)->default(0.00)->after('amount');
            }
            if (!Schema::hasColumn('users', 'actualearning')) {
                $table->decimal('actualearning', 10, 2)->default(0.00)->after('earnings');
            }
        });

        // 2. Create admin_earnings table
        if (!Schema::hasTable('admin_earnings')) {
            Schema::create('admin_earnings', function (Blueprint $table) {
                $table->id();
                $table->decimal('revenue', 10, 2)->default(0.00);
                $table->timestamps();
            });

            DB::table('admin_earnings')->insert([
                'revenue'    => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Create withdraw_requests table
        if (!Schema::hasTable('withdraw_requests')) {
            Schema::create('withdraw_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('amount', 10, 2);
                $table->string('status', 50)->default('pending')->index();
                $table->text('bank_info')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();
            });
        }

        // 4. Create recharge_requests table
        if (!Schema::hasTable('recharge_requests')) {
            Schema::create('recharge_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('requested_amount', 10, 2);
                $table->decimal('approved_amount', 10, 2)->nullable();
                $table->string('status', 50)->default('pending')->index();
                $table->string('payment_method', 50)->default('razorpay');
                $table->string('transaction_reference', 255)->nullable();
                $table->timestamps();
            });
        }

        // 5. Create purchases table
        if (!Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('buyer_id')->index();
                $table->unsignedBigInteger('video_id')->index();
                $table->decimal('price', 10, 2)->default(0.00);
                $table->timestamps();

                $table->unique(['buyer_id', 'video_id']);
            });
        }

        // 6. Sync existing wallet balances to users.amount
        if (Schema::hasTable('wallets') && Schema::hasTable('wallet_transactions')) {
            $wallets = DB::table('wallets')->where('type', 'user')->get();
            foreach ($wallets as $wallet) {
                $credits = (int)DB::table('wallet_transactions')
                    ->where('wallet_id', $wallet->id)
                    ->where('type', 'credit')
                    ->where('status', 'cleared')
                    ->sum('amount_minor_units');

                $debits = (int)DB::table('wallet_transactions')
                    ->where('wallet_id', $wallet->id)
                    ->where('type', 'debit')
                    ->where('status', 'cleared')
                    ->sum('amount_minor_units');

                $balMinor = max(0, $credits - $debits);
                $balDecimal = number_format($balMinor / 100.0, 2, '.', '');

                DB::table('users')->where('id', $wallet->owner_id)->update([
                    'amount' => $balDecimal,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['amount', 'earnings', 'actualearning']);
        });

        Schema::dropIfExists('admin_earnings');
        Schema::dropIfExists('withdraw_requests');
        Schema::dropIfExists('recharge_requests');
        Schema::dropIfExists('purchases');
    }
};
