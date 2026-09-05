<?php

use App\Support\IdentityScope;
use App\Support\ProductCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Identity becomes OUR code, and uniqueness becomes per-product
 * (owner ruling, 5 Sep 2026).
 *
 * One taxpayer legitimately runs three integrations on the same NTN — a hotel
 * on PRA POS, a Tier-1 outlet on FBR POS, a distribution house on Digital
 * Invoice — and signs up for each with the same email and phone. The old
 * system-wide unique indexes made that impossible, so:
 *
 *   1. companies.account_code (PRA-00026) becomes the system's own identity;
 *      NTN goes back to being ordinary regulator data.
 *   2. email / phone / username / NTN are unique INSIDE a product line only.
 *      Inside one product they must stay unique, or that panel's login could
 *      not tell two accounts apart.
 *   3. users.product_type mirrors the owning company so the database can
 *      enforce (2). Logins still scope through the company row itself.
 *   4. The trial-abuse ledger is scoped the same way (a credential used on
 *      PRA POS must not block a first FBR POS trial).
 *   5. Business groups: accounts that share a CNIC / NTN / email / phone are
 *      tied together automatically for the admin panel only.
 */
return new class extends Migration
{
    /** Every product_type spelling that may sit in the companies table. */
    private const STORED_TYPES = ['di', 'pos', 'fbrpos', 'erps', 'health'];

    public function up(): void
    {
        // ---------------------------------------------------------- mirror
        if (!Schema::hasColumn('users', 'product_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('product_type', 20)->default('')->after('company_id')->index();
            });
        }
        $this->backfillUserProducts();

        // ---------------------------------------------------- account code
        if (!Schema::hasColumn('companies', 'account_code')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('account_code', 32)->nullable()->after('id');
            });
        }
        $this->backfillAccountCodes();
        $this->addUnique('companies', ['account_code'], 'companies_account_code_unique');

        // ------------------------------------- global -> per-product unique
        $this->dropSingleColumnUnique('users', 'email');
        $this->dropSingleColumnUnique('users', 'phone');
        $this->dropSingleColumnUnique('users', 'username');
        $this->dropSingleColumnUnique('companies', 'ntn');

        $this->addUnique('users', ['email', 'product_type'], 'users_email_product_unique');
        $this->addUnique('users', ['phone', 'product_type'], 'users_phone_product_unique');
        $this->addUnique('users', ['username', 'product_type'], 'users_username_product_unique');
        $this->addUnique('companies', ['ntn', 'product_type'], 'companies_ntn_product_unique');

        // ------------------------------------------- trial-abuse ledger
        $this->backfillLedgerProducts();

        // ------------------------------------------------ business groups
        if (!Schema::hasTable('company_groups')) {
            Schema::create('company_groups', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('company_group_members')) {
            Schema::create('company_group_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_group_id')->index();
                $table->unsignedBigInteger('company_id')->unique();
                $table->string('match_type', 16)->default('seed');
                $table->string('match_value', 191)->nullable();
                $table->string('strength', 12)->default('weak');
                $table->boolean('is_manual')->default(false);
                $table->timestamps();
            });
        }

        // A pair an admin has pulled apart must never be re-joined by the
        // automatic pass — otherwise the correction silently undoes itself.
        if (!Schema::hasTable('company_group_exclusions')) {
            Schema::create('company_group_exclusions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_group_id');
                $table->unsignedBigInteger('company_id')->index();
                $table->timestamps();
                $table->unique(['company_group_id', 'company_id'], 'cg_exclusion_unique');
            });
        }

        if (!Schema::hasTable('company_identity_keys')) {
            Schema::create('company_identity_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('key_type', 12);
                $table->string('key_value', 191);
                $table->timestamps();
                $table->unique(['company_id', 'key_type', 'key_value'], 'company_identity_key_unique');
                $table->index(['key_type', 'key_value'], 'company_identity_key_lookup');
            });
        }
    }

    public function down(): void
    {
        foreach (['company_identity_keys', 'company_group_exclusions', 'company_group_members', 'company_groups'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropIndexByName('users', 'users_email_product_unique');
        $this->dropIndexByName('users', 'users_phone_product_unique');
        $this->dropIndexByName('users', 'users_username_product_unique');
        $this->dropIndexByName('companies', 'companies_ntn_product_unique');
        $this->dropIndexByName('companies', 'companies_account_code_unique');

        // Best effort: duplicates created while per-product uniqueness was in
        // force would make these fail, and that must not block a rollback.
        $this->addUnique('users', ['email'], 'users_email_unique');
        $this->addUnique('users', ['phone'], 'users_phone_unique');
        $this->addUnique('users', ['username'], 'users_username_unique');
        $this->addUnique('companies', ['ntn'], 'companies_ntn_unique');
    }

    // ------------------------------------------------------------ backfills

    private function backfillUserProducts(): void
    {
        foreach (self::STORED_TYPES as $stored) {
            $bucket = ProductCatalog::normalize($stored) ?? '';
            DB::table('users')
                ->whereIn('company_id', function ($query) use ($stored) {
                    $query->select('id')->from('companies')->where('product_type', $stored);
                })
                ->update(['product_type' => $bucket]);
        }

        DB::table('users')->whereNull('product_type')->update(['product_type' => '']);
    }

    private function backfillAccountCodes(): void
    {
        DB::table('companies')
            ->select('id', 'product_type', 'account_code')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    if (!empty($row->account_code)) {
                        continue;
                    }
                    DB::table('companies')->where('id', $row->id)->update([
                        'account_code' => IdentityScope::accountCodeFor($row->product_type, (int) $row->id),
                    ]);
                }
            });
    }

    private function backfillLedgerProducts(): void
    {
        if (!Schema::hasTable('registered_credentials') || !Schema::hasColumn('registered_credentials', 'product_type')) {
            return;
        }

        foreach (self::STORED_TYPES as $stored) {
            $bucket = ProductCatalog::normalize($stored) ?? '';
            DB::table('registered_credentials')
                ->whereNull('product_type')
                ->whereIn('company_id', function ($query) use ($stored) {
                    $query->select('id')->from('companies')->where('product_type', $stored);
                })
                ->update(['product_type' => $bucket]);
        }
    }

    // --------------------------------------------------------------- indexes

    private function dropSingleColumnUnique(string $table, string $column): void
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                $columns = $index['columns'] ?? [];
                if (($index['unique'] ?? false) && count($columns) === 1 && $columns[0] === $column) {
                    $name = $index['name'];
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not drop global unique index', ['table' => $table, 'column' => $column, 'error' => $e->getMessage()]);
        }
    }

    private function dropIndexByName(string $table, string $name): void
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? '') === $name) {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not drop index', ['table' => $table, 'index' => $name, 'error' => $e->getMessage()]);
        }
    }

    private function addUnique(string $table, array $columns, string $name): void
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? '') === $name) {
                    return;
                }
            }
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->unique($columns, $name);
            });
        } catch (\Throwable $e) {
            Log::warning('Could not add unique index', ['table' => $table, 'index' => $name, 'error' => $e->getMessage()]);
        }
    }
};
