<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reporting_oauth_clients')) {
            Schema::create('reporting_oauth_clients', function (Blueprint $t) {
                $t->string('id', 64)->primary();
                $t->string('name', 100);
                $t->json('redirect_uris');
                $t->dateTime('created_at');
            });
        }
        if (! Schema::hasTable('reporting_oauth_codes')) {
            Schema::create('reporting_oauth_codes', function (Blueprint $t) {
                $t->string('hash', 64)->primary();
                $t->string('client_id', 64);
                $t->unsignedBigInteger('actor_id');
                $t->string('redirect_uri', 512);
                $t->string('challenge', 43);
                $t->string('resource', 512);
                $t->string('scope', 100);
                $t->json('company_ids')->nullable();
                $t->dateTime('expires_at');
                $t->dateTime('used_at')->nullable();
                $t->dateTime('created_at');
            });
        }
        if (! Schema::hasTable('reporting_oauth_grants')) {
            Schema::create('reporting_oauth_grants', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('client_id', 64);
                $t->unsignedBigInteger('actor_id');
                $t->string('resource', 512);
                $t->string('scope', 100);
                $t->json('company_ids')->nullable();
                $t->dateTime('expires_at');
                $t->dateTime('revoked_at')->nullable();
                $t->dateTime('created_at');
            });
        }
        foreach (['access', 'refresh'] as $kind) {
            if (! Schema::hasTable('reporting_oauth_'.$kind.'_tokens')) {
                Schema::create('reporting_oauth_'.$kind.'_tokens', function (Blueprint $t) use ($kind) {
                    $t->string('hash', 64)->primary();
                    $t->uuid('grant_id')->index();
                    $t->dateTime('expires_at');
                    $t->dateTime('created_at');
                    if ($kind === 'refresh') {
                        $t->dateTime('used_at')->nullable();
                    }
                });
            }
        }
        if (! Schema::hasTable('reporting_read_audits')) {
            Schema::create('reporting_read_audits', function (Blueprint $t) {
                $t->id();
                $t->uuid('grant_id')->index();
                $t->unsignedBigInteger('actor_id');
                $t->unsignedBigInteger('company_id')->nullable();
                $t->string('tool', 64);
                $t->date('date_from')->nullable();
                $t->date('date_to')->nullable();
                $t->dateTime('created_at');
            });
        }
    }

    public function down(): void {} // Preserve owner consent/revocation and access audit.
};
