<?php

use App\Models\Agent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('password');
            $table->string('referral_code', 20)->nullable()->unique()->after('is_active');
        });

        Agent::query()->whereNull('referral_code')->eachById(function (Agent $agent) {
            do {
                $code = 'AG-' . strtoupper(Str::random(8));
            } while (Agent::where('referral_code', $code)->exists());
            $agent->forceFill(['referral_code' => $code])->saveQuietly();
        });

        // Legacy admin-entered rows allowed duplicate emails. Keep the oldest
        // usable login and clear later duplicates before enforcing identity.
        DB::table('agents')->whereNotNull('email')->where('email', '!=', '')
            ->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')
            ->pluck('email')->each(function ($email) {
                $keep = DB::table('agents')->where('email', $email)->min('id');
                DB::table('agents')->where('email', $email)->where('id', '!=', $keep)->update(['email' => null]);
            });
        Schema::table('agents', fn (Blueprint $table) => $table->unique('email'));

        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('description');
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->unsignedBigInteger('paid_by_admin_id')->nullable()->after('paid_at');
        });

        Schema::create('agent_sale_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->index();
            $table->string('identifier', 255);
            $table->string('identifier_type', 10);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sale_claims');
        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->dropColumn(['status', 'paid_at', 'paid_by_admin_id']);
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['password', 'is_active', 'referral_code']);
        });
    }
};