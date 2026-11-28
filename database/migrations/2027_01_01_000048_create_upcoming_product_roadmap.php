<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upcoming_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->text('introduction');
            $table->json('benefits');
            $table->json('features');
            $table->json('industries');
            $table->string('concept', 25);
            $table->string('status', 30);
            $table->string('existing_product_path', 160)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'sort_order']);
        });

        Schema::create('upcoming_product_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upcoming_product_id')->constrained()->restrictOnDelete();
            $table->char('email_key', 64);
            $table->text('email')->nullable();
            $table->string('purpose', 25);
            $table->char('token_hash', 64)->unique();
            $table->string('consent_version', 20);
            // The application supplies both times. Avoid MariaDB's implicit
            // TIMESTAMP defaults under strict SQL mode.
            $table->dateTime('consented_at');
            $table->dateTime('confirmation_sent_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['upcoming_product_id', 'email_key', 'purpose'], 'upcoming_interest_dedupe');
        });

        // These are roadmap announcements. Protected deployment publishes only
        // the approved preview copy and never creates a plan or checkout entry.
        $now = now();
        $announcements = [
            [
                'hr-attendance', 'TaxNest HR & Attendance',
                'Manage staff, shifts and payroll from a single workspace.',
                ['See attendance exceptions in one place', 'Give employees access to their own records'],
                ['Employee self-service and attendance review', 'Shift planning and payroll approvals', 'Mobile and biometric attendance where supported'],
                ['Professional services', 'Retail', 'Schools', 'Healthcare'],
                'staff', 'in_development', null,
            ],
            [
                'distribution-erp', 'TaxNest Distribution ERP',
                'Follow orders from the warehouse through dispatch, delivery and collections.',
                ['Reconcile stock and collections at day close', 'See stock across warehouses and vehicle routes'],
                ['Warehouse, batch and expiry tracking', 'Field orders and partial delivery', 'Recovery, returns and vehicle settlement'],
                ['FMCG', 'Beverages', 'Pharmaceuticals', 'Multi-brand distribution'],
                'distribution', 'upcoming', null,
            ],
            [
                'switch', 'TaxNest Switch',
                'Plan, test and reconcile authorized data transfers to TaxNest.',
                ['Review a trial import before changing systems', 'Keep original records and documents traceable'],
                ['File imports and versioned field mapping', 'Reconciled migration trials', 'Vendor connectors when supported and authorized'],
                ['Existing TaxNest customers', 'ERP migrations', 'Multi-system businesses'],
                'migration', 'upcoming', null,
            ],
            [
                'enterprise-360', 'TaxNest Enterprise 360',
                'See results from authorized business installations in a group workspace.',
                ['Compare business performance with source coverage', 'Keep each company in control of its own data'],
                ['Portfolio reporting and native drill-down', 'Verified installation linking and approvals', 'Group finance and consolidation'],
                ['Business groups', 'Holding companies', 'Multi-branch operators'],
                'portfolio', 'upcoming', null,
            ],
            [
                'laboratory-erp-expansion', 'TaxNest Laboratory ERP expansion',
                'Expand the existing Lab workspace for standalone laboratories and collection networks.',
                ['Follow specimens between collection and verification', 'Keep reports and sample history traceable'],
                ['Collection-centre custody and test panels', 'Reagent tracking and quality control', 'Analyzer interfaces and a secure patient portal'],
                ['Diagnostic laboratories', 'Hospital labs', 'Collection networks'],
                'laboratory', 'upcoming', '/nest-erps',
            ],
        ];

        foreach ($announcements as $index => [$slug, $name, $introduction, $benefits, $features, $industries, $concept, $status, $existing]) {
            DB::table('upcoming_products')->insert([
                'slug' => $slug,
                'name' => $name,
                'introduction' => $introduction,
                'benefits' => json_encode($benefits, JSON_THROW_ON_ERROR),
                'features' => json_encode($features, JSON_THROW_ON_ERROR),
                'industries' => json_encode($industries, JSON_THROW_ON_ERROR),
                'concept' => $concept,
                'status' => $status,
                'existing_product_path' => $existing,
                'sort_order' => ($index + 1) * 10,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('upcoming_product_interests');
        Schema::dropIfExists('upcoming_products');
    }
};
