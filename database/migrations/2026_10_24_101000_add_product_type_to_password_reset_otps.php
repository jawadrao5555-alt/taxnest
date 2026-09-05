<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reset must know WHICH product's account it is resetting.
 *
 * Since 5 Sep 2026 the same email can hold a PRA POS, an FBR POS and a Digital
 * Invoice account. /forgot-password is one shared flow keyed on the email
 * alone, so without this column an OTP issued for the hotel's POS account
 * would happily reset the distribution house's DI password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('password_reset_otps') || Schema::hasColumn('password_reset_otps', 'product_type')) {
            return;
        }

        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->string('product_type', 20)->nullable()->after('email')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('password_reset_otps') && Schema::hasColumn('password_reset_otps', 'product_type')) {
            Schema::table('password_reset_otps', function (Blueprint $table) {
                $table->dropColumn('product_type');
            });
        }
    }
};
