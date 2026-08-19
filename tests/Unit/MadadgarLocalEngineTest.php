<?php

namespace Tests\Unit;

use App\Services\MadadgarLocalEngine;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Quality pass for the Madadgar local answer engine (task: hybrid local mode,
 * Aug 2026). No DB needed — engine reads resources/madadgar/knowledge-pos.md.
 *
 * Philosophy: a confidently-WRONG answer is worse than a decline, so the
 * decline cases here are as important as the answered ones.
 */
class MadadgarLocalEngineTest extends TestCase
{
    /**
     * Realistic Roman-Urdu questions covering every KB section. Each entry:
     * [question, substring that MUST appear in the answer (case-insensitive)].
     */
    public static function answeredQuestions(): array
    {
        return [
            // Billing basics
            'nayi bill kaise banau' => ['nayi bill kaise banau?', 'sale'],
            'provisional bill kya hota hai' => ['provisional bill kya hota hai?', 'provisional'],
            'bill hold kaise karte hain' => ['bill hold kaise karte hain?', 'hold'],
            'discount kaise dun' => ['bill par discount kaise dun?', 'discount'],
            'bill return kaise karun' => ['bill wapas (return) kaise karun?', 'return'],
            'purana bill kahan milega' => ['purana bill kahan se dekhun?', 'bill'],
            // Shortcuts
            'shortcut keys kya hain' => ['keyboard shortcut keys kya hain?', 'F9'],
            'f9 kya karta hai' => ['F9 se kya hota hai?', 'F9'],
            // Printer / receipt
            'printer kaise lagayen' => ['printer kaise lagayen?', 'printer'],
            'receipt par logo kaise aayega' => ['receipt par apna logo kaise lagaun?', 'logo'],
            'parchi print nahi ho rahi' => ['parchi print nahi ho rahi, kya karun?', 'print'],
            // Day close
            'day close kahan hai' => ['day close kahan se hota hai?', 'day-close'],
            'z report kaise nikalun' => ['z report kaise nikalti hai?', 'day'],
            'opening cash kahan dalen' => ['opening cash kahan enter karte hain?', 'opening'],
            // Products / inventory
            'naya product kaise add karun' => ['naya product kaise add karun?', 'product'],
            'stock kaise update karun' => ['stock kaise update hota hai?', 'stock'],
            'barcode scanner kaise use karun' => ['barcode scanner kaise chalega?', 'barcode'],
            // Customers
            'customer ki history kahan dekhun' => ['customer ki purchase history kahan dekhun?', 'history'],
            // Riders / delivery
            'rider kaise banayen' => ['delivery rider kaise add karun?', 'rider'],
            // Restaurant
            'kot kya hota hai' => ['KOT kya hota hai?', 'KOT'],
            'table kaise assign karun' => ['table par order kaise lagayen?', 'table'],
            'kitchen display kaise on karun' => ['kitchen display (KDS) kaise chalta hai?', 'kitchen'],
            // Team
            'cashier account kaise banayen' => ['naya cashier account kaise banaun?', 'team'],
            'cashier ka password kaise change karun' => ['cashier ka password kaise badlun?', 'password'],
            // Packages / limits
            'bill ki limit kitni hai' => ['mahine ke bill ki limit kitni hai?', 'limit'],
            'package upgrade kaise hoga' => ['package upgrade kaise karun?', 'package'],
            // PRA / tax
            'pra kya hai' => ['PRA integration kya hai?', 'PRA'],
            'tax rate kaise set karun' => ['tax rate kahan set hota hai?', 'tax'],
            // Troubleshooting
            'login nahi ho raha' => ['login nahi ho raha, kya karun?', 'password'],
            'app slow chal rahi hai' => ['system bohat slow chal raha hai', 'internet'],
        ];
    }

    public function test_answers_common_questions_locally(): void
    {
        $failures = [];
        foreach (self::answeredQuestions() as [$question, $expect]) {
            $answer = MadadgarLocalEngine::answer($question);
            if ($answer === null) {
                $failures[] = "DECLINED: {$question}";
                continue;
            }
            if (mb_stripos($answer, $expect) === false) {
                $failures[] = "WRONG ({$question}): expected '{$expect}' in: ".mb_substr($answer, 0, 160);
            }
        }

        $this->assertSame([], $failures, "Local engine quality failures:\n".implode("\n", $failures));
    }

    public function test_answers_are_plain_short_text(): void
    {
        foreach (['printer kaise lagayen?', 'day close kahan hai?', 'shortcut keys kya hain?'] as $q) {
            $answer = MadadgarLocalEngine::answer($q);
            $this->assertNotNull($answer, $q);
            $this->assertStringNotContainsString('**', $answer, $q);
            $this->assertStringNotContainsString('##', $answer, $q);
            $this->assertLessThanOrEqual(700, mb_strlen($answer), $q);
        }
    }

    public function test_declines_when_unsure(): void
    {
        $mustDecline = [
            'salam',                       // greeting, no content
            'cricket ka score kya hai',    // off-topic
            'youtube se video download kaise karun', // off-topic tech
            'tum kaun ho',                 // meta
            'mausam kaisa hai aaj',        // off-topic
            'mujhe coding sikha do',       // off-topic
        ];
        foreach ($mustDecline as $q) {
            $this->assertNull(MadadgarLocalEngine::answer($q), "Should have declined: {$q}");
        }
    }

    public function test_rule_escalation_intents(): void
    {
        // Explicit "admin ko batao" => card
        $card = MadadgarLocalEngine::ruleEscalation('yeh masla admin ko bata dein please', false);
        $this->assertNotNull($card);
        $this->assertArrayHasKey('title', $card);
        $this->assertArrayHasKey('summary', $card);
        $this->assertContains($card['kind'], ['problem', 'feature_request']);

        // Strong complaint wording => card
        $this->assertNotNull(MadadgarLocalEngine::ruleEscalation('mujhe complaint karni hai printer kharab hai', false));

        // Feature demand => feature_request kind
        $feat = MadadgarLocalEngine::ruleEscalation('naya feature chahiye whatsapp par bill bhejne ka', true);
        $this->assertNotNull($feat);
        $this->assertSame('feature_request', $feat['kind']);

        // Unanswered turn (fallback) => card even without complaint wording
        $this->assertNotNull(MadadgarLocalEngine::ruleEscalation('is ka jawab kb mein nahi hai bilkul', true));

        // Plain answered how-to => NO card
        $this->assertNull(MadadgarLocalEngine::ruleEscalation('printer kaise lagayen?', false));
        $this->assertNull(MadadgarLocalEngine::ruleEscalation('day close kahan hai', false));
    }

    public function test_cache_roundtrip_and_kb_hash_keying(): void
    {
        Cache::flush();
        $q = 'printer kaise lagayen?';

        $this->assertNull(MadadgarLocalEngine::cachedAnswer($q));
        MadadgarLocalEngine::cacheAnswer($q, 'TEST-ANSWER', 'local');
        $this->assertSame('TEST-ANSWER', MadadgarLocalEngine::cachedAnswer($q));

        // Same tokens, different surface form => same cache entry.
        $this->assertSame('TEST-ANSWER', MadadgarLocalEngine::cachedAnswer('Printer KAISE lagayen'));

        // Follow-up-ish / tiny questions are never cached.
        MadadgarLocalEngine::cacheAnswer('ok', 'X', 'local');
        $this->assertNull(MadadgarLocalEngine::cachedAnswer('ok'));

        Cache::flush();
    }

    public function test_openai_answers_are_never_cached_globally(): void
    {
        Cache::flush();
        // NB: needs >= 2 canonical tokens to be cacheable at all ("day close"
        // collapses to the single token "dayclose" and is never cached).
        $q = 'printer kaise lagayen?';

        // OpenAI replies are generated with per-session chat history and can
        // embed one user's context — the shared (cross-user, cross-company)
        // cache must refuse them so another tenant can never be served that
        // reply. Regression for the cross-tenant cache review finding.
        MadadgarLocalEngine::cacheAnswer($q, 'CONTEXTUAL-OPENAI-REPLY', 'openai');
        $this->assertNull(MadadgarLocalEngine::cachedAnswer($q), 'OpenAI answer leaked into the shared cache');

        // Any non-local source is refused, not just "openai".
        MadadgarLocalEngine::cacheAnswer($q, 'X', 'weird-source');
        $this->assertNull(MadadgarLocalEngine::cachedAnswer($q));

        // Deterministic KB-derived local answers still cache fine (control).
        MadadgarLocalEngine::cacheAnswer($q, 'LOCAL-ANSWER', 'local');
        $this->assertSame('LOCAL-ANSWER', MadadgarLocalEngine::cachedAnswer($q));

        Cache::flush();
    }

    public function test_fallback_text_is_polite_roman_urdu(): void
    {
        $text = MadadgarLocalEngine::fallbackText();
        $this->assertNotSame('', $text);
        $this->assertStringContainsStringIgnoringCase('whatsapp', $text);
        $this->assertStringNotContainsString('**', $text);
    }
}
