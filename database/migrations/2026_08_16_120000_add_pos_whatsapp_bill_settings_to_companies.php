<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WhatsApp Bill (owner voice note 17 Aug 2026): jab final bill banta hai aur
// us par customer ka phone number ho, receipt popup par "WhatsApp Bill" button
// customer ki chat kholta hai jis mein bill message + public receipt link
// prefilled hota hai. Enabled DEFAULT ON (button waise bhi sirf tab dikhta hai
// jab bill par routable number ho); auto-open (bill ban'te hi WhatsApp window
// khud khulna) DEFAULT OFF — popup-blocker/focus behaviour shop ki pasand hai.
// Idempotent + hasColumn-guarded so it self-heals on prod schema drift.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_whatsapp_bill_enabled')->default(true);
            });
        }
        if (!Schema::hasColumn('companies', 'pos_whatsapp_bill_auto_open')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_whatsapp_bill_auto_open')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_whatsapp_bill_auto_open')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_whatsapp_bill_auto_open');
            });
        }
        if (Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_whatsapp_bill_enabled');
            });
        }
    }
};
