<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1381 — "POS se hi call back karein".
 *
 * Counter par POS ab sirf yeh nahi batata ke kis ka phone aaya tha: cashier
 * wahin se "Call back" daba kar paired counter-phone ko tap-to-dial request
 * bhej sakta hai. Us request ki durable queue yahan banti hai.
 *
 * Shape POS ke print-job queue jaisi hi hai (pos_print_jobs): status +
 * claim_token do-qadam claim, koi FK nahi (shared/legacy tables ke saath FK
 * add karna hamesha maslay deta hai), aur short expiry — purani request phone
 * par der se pohanch kar random call na laga de.
 *
 * Idempotent: har cheez hasTable/hasColumn guard ke peeche hai
 * (prod-schema-drift-selfheal — live par "Ran" mark shuda migrations ke
 * bawajood column ghayab ho sakta hai).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_caller_dial_requests')) {
            Schema::create('pos_caller_dial_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                // Kis ring ka jawab hai (Haaliya calls list ki row) — nullable,
                // kyunke attached-customer card se bhi call back ho sakta hai.
                $table->unsignedBigInteger('event_id')->nullable();
                // PkPhone::normalize ki shakal (923001234567) — matching/logging.
                $table->string('phone', 32);
                // Phone par jo number dial karna hai (0300-1234567 ki jagah saaf
                // digits: 03001234567 / +9715...). App isi ko tel: URI banati hai.
                $table->string('dial_digits', 32);
                $table->string('caller_name', 120)->nullable();
                // pending → delivered → dialed | failed | expired
                $table->string('status', 12)->default('pending');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('claim_token', 64)->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('error', 190)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('dialed_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index('claim_token');
            });
        }

        // "Call back kiya" ka nishan — Haaliya calls list mein missed vs handled
        // ka farq isi se dikhta hai.
        if (Schema::hasTable('pos_caller_events') && !Schema::hasColumn('pos_caller_events', 'called_back_at')) {
            Schema::table('pos_caller_events', function (Blueprint $table) {
                $table->timestamp('called_back_at')->nullable()->after('ring_at');
            });
        }

        // Device capability + fresh heartbeat. last_seen_at 6 ghante tak "online"
        // manta hai (app ka koi periodic heartbeat nahi tha); dial queue ka poll
        // har chand second par aata hai, is liye "abhi request le sakta hai?" ka
        // faisla alag, bohat taze stamp par hota hai.
        if (Schema::hasTable('pos_caller_devices')) {
            if (!Schema::hasColumn('pos_caller_devices', 'supports_dial')) {
                Schema::table('pos_caller_devices', function (Blueprint $table) {
                    $table->boolean('supports_dial')->default(false)->after('device');
                });
            }
            if (!Schema::hasColumn('pos_caller_devices', 'dial_seen_at')) {
                Schema::table('pos_caller_devices', function (Blueprint $table) {
                    $table->timestamp('dial_seen_at')->nullable()->after('last_seen_at');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_caller_dial_requests');

        if (Schema::hasTable('pos_caller_events') && Schema::hasColumn('pos_caller_events', 'called_back_at')) {
            Schema::table('pos_caller_events', function (Blueprint $table) {
                $table->dropColumn('called_back_at');
            });
        }
        if (Schema::hasTable('pos_caller_devices')) {
            foreach (['supports_dial', 'dial_seen_at'] as $col) {
                if (Schema::hasColumn('pos_caller_devices', $col)) {
                    Schema::table('pos_caller_devices', function (Blueprint $table) use ($col) {
                        $table->dropColumn($col);
                    });
                }
            }
        }
    }
};
