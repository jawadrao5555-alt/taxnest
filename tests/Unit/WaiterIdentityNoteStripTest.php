<?php

namespace Tests\Unit;

use App\Http\Controllers\RestaurantWaiterController;
use Tests\TestCase;

/**
 * Task 632 (ZFC "NOTE: waiter"): notes that exactly match the punching user's
 * login identity (name/username/email/email-prefix/phone) are browser-autofill
 * garbage and must be discarded on every waiter note-persisting path; genuine
 * kitchen instructions must pass through untouched.
 */
class WaiterIdentityNoteStripTest extends TestCase
{
    private function user(): object
    {
        return (object) [
            'id' => 53,
            'name' => 'waiter',
            'username' => 'saifw',
            'email' => 'saifwaiter@gmail.com',
            'phone' => '03001234567',
        ];
    }

    private function strip(array $validated): array
    {
        return RestaurantWaiterController::stripIdentityNotes($validated, $this->user());
    }

    public function test_exact_identity_notes_are_discarded(): void
    {
        $out = $this->strip([
            'items' => [
                ['name' => 'Coke', 'special_notes' => 'waiter'],           // name
                ['name' => 'Burger', 'special_notes' => 'SaifW'],          // username (case-insensitive)
                ['name' => 'Pizza', 'special_notes' => 'saifwaiter@gmail.com'], // email
                ['name' => 'Fries', 'special_notes' => ' saifwaiter '],    // email prefix, padded
                ['name' => 'Shake', 'special_notes' => '03001234567'],     // phone
            ],
            'kitchen_notes' => 'Waiter',
        ]);

        foreach ($out['items'] as $it) {
            $this->assertNull($it['special_notes'], $it['name'] . ' note should be discarded');
        }
        $this->assertNull($out['kitchen_notes']);
    }

    public function test_real_kitchen_instructions_pass_through(): void
    {
        $out = $this->strip([
            'items' => [
                ['name' => 'Coke', 'special_notes' => 'extra mayo salad'],
                ['name' => 'Burger', 'special_notes' => 'waiter ko bolna spicy ho'], // contains word — kept
                ['name' => 'Pizza', 'special_notes' => null],
                ['name' => 'Fries', 'special_notes' => ''],
            ],
            'kitchen_notes' => 'kam mirch',
        ]);

        $this->assertSame('extra mayo salad', $out['items'][0]['special_notes']);
        $this->assertSame('waiter ko bolna spicy ho', $out['items'][1]['special_notes']);
        $this->assertNull($out['items'][2]['special_notes']);
        $this->assertSame('', $out['items'][3]['special_notes'] ?? '');
        $this->assertSame('kam mirch', $out['kitchen_notes']);
    }

    public function test_missing_kitchen_notes_key_is_left_absent(): void
    {
        $out = $this->strip(['items' => [['name' => 'Coke', 'special_notes' => 'spicy']]]);
        $this->assertArrayNotHasKey('kitchen_notes', $out);
        $this->assertSame('spicy', $out['items'][0]['special_notes']);
    }
}
