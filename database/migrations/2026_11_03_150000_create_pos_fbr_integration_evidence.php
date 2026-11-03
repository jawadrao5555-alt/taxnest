<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_di_sandbox_profiles', function (Blueprint $t) {
            $t->string('sandbox_url', 500)->nullable();
            $t->string('production_url', 500)->nullable();
        });
        Schema::create('pos_ims_profiles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('enabled')->default(false); $t->string('environment', 20)->default('sandbox');
            $t->string('connection_mode', 20)->default('cloud');
            foreach (['sandbox','production'] as $env) {
                $t->string($env.'_pos_id', 20)->nullable(); $t->text($env.'_token')->nullable();
                $t->string($env.'_url', 500)->nullable();
            }
            $t->string('device_uid',100)->nullable(); $t->string('local_ims_url',500)->nullable();
            $t->timestamp('production_confirmed_at')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
        });
        Schema::create('pos_ims_submissions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('source_transaction_id');
            $t->string('environment', 20); $t->string('connection_mode',20); $t->string('state',40)->default('draft');
            $t->longText('snapshot'); $t->longText('payload')->nullable(); $t->string('payload_hash',64)->nullable();
            $t->longText('attempts')->nullable(); $t->string('fiscal_number',100)->nullable();
            $t->timestamp('attempted_at')->nullable(); $t->string('agent_claim',64)->nullable();
            $t->string('device_uid',100)->nullable(); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->unique(['company_id','source_transaction_id','environment'],'pos_ims_source_environment_unique');
            $t->index(['company_id','state']);
        });
    }
    public function down(): void
    {
        if (DB::table('pos_ims_submissions')->exists() || DB::table('pos_ims_profiles')->exists()) {
            throw new RuntimeException('Preserve IMS configuration and fiscal evidence; roll back application code only.');
        }
        Schema::dropIfExists('pos_ims_submissions'); Schema::dropIfExists('pos_ims_profiles');
        Schema::table('pos_di_sandbox_profiles', fn(Blueprint $t) => $t->dropColumn(['sandbox_url','production_url']));
    }
};
