<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Task 1022: minimal reusable POS survey system (elaan + advice collection)
// + seed the Caller ID survey (Roman Urdu) targeting ALL PRA POS companies.
//
// Idempotent on purpose (prod runs migrate --force): tables created only if
// missing, seed row inserted only if a survey with the same title is absent.
return new class extends Migration
{
    private const CALLER_ID_TITLE = 'Caller ID Feature — Aap ka Mashwara';

    public function up(): void
    {
        if (!Schema::hasTable('surveys')) {
            Schema::create('surveys', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                $table->text('intro')->nullable();
                // Fixed-choice questions: [{key,text,options:[{key,label}]}]
                $table->text('questions');
                $table->boolean('allow_comment')->default(true);
                // 'pos_all' = all PRA POS companies, 'pos_restaurant' = restaurant-mode only.
                $table->string('audience', 30)->default('pos_all');
                $table->boolean('is_published')->default(false);
                // Closing stops the POS popup/pill without losing results.
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('survey_responses')) {
            Schema::create('survey_responses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('survey_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('company_id');
                // NULL answers = user only SAW/dismissed the survey (seen tracking);
                // answered_at set = a real submitted response.
                $table->text('answers')->nullable();
                $table->text('comment')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();
                $table->unique(['survey_id', 'user_id']);
                $table->index('company_id');
            });
        }

        if (DB::table('surveys')->where('title', self::CALLER_ID_TITLE)->exists()) {
            return; // re-run — already seeded
        }

        $now = now();
        DB::table('surveys')->insert([
            'title' => self::CALLER_ID_TITLE,
            'intro' => 'Hum ek naya feature banane ka soch rahe hain: Call aate hi customer ka naam/address screen par aa jaye — order lena tez, ghalti kam. Feature banane se pehle aap ki raye chahiye. Sirf 4 chhote sawal — 30 second.',
            'questions' => json_encode([
                [
                    'key' => 'calls_counter',
                    'text' => 'Kya aap delivery/order calls counter ke mobile par lete hain?',
                    'options' => [
                        ['key' => 'haan', 'label' => 'Haan'],
                        ['key' => 'nahi', 'label' => 'Nahi'],
                    ],
                ],
                [
                    'key' => 'call_type',
                    'text' => 'Zyada calls kis par aati hain?',
                    'options' => [
                        ['key' => 'sim', 'label' => 'SIM call'],
                        ['key' => 'whatsapp', 'label' => 'WhatsApp call'],
                        ['key' => 'dono', 'label' => 'Dono'],
                    ],
                ],
                [
                    'key' => 'android_phone',
                    'text' => 'Counter par Android phone hai ya rakh sakte hain?',
                    'options' => [
                        ['key' => 'hai', 'label' => 'Hai'],
                        ['key' => 'rakh_sakte', 'label' => 'Rakh sakte hain'],
                        ['key' => 'nahi', 'label' => 'Nahi'],
                    ],
                ],
                [
                    'key' => 'importance',
                    'text' => 'Yeh feature aap ke liye?',
                    'options' => [
                        ['key' => 'bohat_zaroori', 'label' => 'Bohat zaroori'],
                        ['key' => 'theek_hai', 'label' => 'Theek hai'],
                        ['key' => 'zaroorat_nahi', 'label' => 'Zaroorat nahi'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'allow_comment' => true,
            'audience' => 'pos_all',
            'is_published' => true,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Results are precious — down() only unpublishes the seeded survey,
        // it never drops the tables or deletes responses.
        if (Schema::hasTable('surveys')) {
            DB::table('surveys')->where('title', self::CALLER_ID_TITLE)->update(['is_published' => false]);
        }
    }
};
