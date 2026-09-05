<?php

namespace Tests\Feature;

use App\Support\HealthPanel;
use Tests\TestCase;

/**
 * HEALTHCARE PRE-PILOT FRONT DOOR.
 *
 * The Healthcare ERP panel is deployed to production before the product is
 * offered for sale, so the code can be proven on a real server. Until a pilot
 * organisation is deliberately onboarded, a stranger must not be able to create
 * a healthcare company for themselves.
 *
 * What this locks:
 *
 *  1. Closed is the DEFAULT. A fresh or rebuilt server comes up shut, never
 *     open because nobody remembered to set an environment value.
 *  2. BOTH register paths refuse, not just the page. Hiding a button hides a
 *     button; the POST is what actually creates a company, and it is reachable
 *     by anyone who can type a URL.
 *  3. The refusal is a not-found, not a 403 — a stranger should not learn that
 *     a healthcare signup exists at this address at all. The panel's own
 *     not-found handler then lands the visitor on the healthcare login rather
 *     than leaking them into another panel, so that redirect IS the refusal as
 *     a browser sees it.
 *  4. Opening the door is a config decision, so the pilot needs one environment
 *     value on the live server and no deploy.
 *
 * No database is required: the gate fires before validation, which is exactly
 * the property being asserted.
 */
class HealthRegistrationPrePilotTest extends TestCase
{
    public function test_registration_is_closed_by_default(): void
    {
        // Nothing set: the shipped default must be shut.
        $this->assertFalse(
            HealthPanel::registrationOpen(),
            'Healthcare self-registration must default to CLOSED while the product is pre-pilot.'
        );
    }

    public function test_register_page_is_not_found_while_pre_pilot(): void
    {
        config(['health.registration_open' => false]);

        $this->get('/health/register')->assertRedirect('/health/login');
    }

    public function test_register_page_is_a_plain_404_to_an_api_client(): void
    {
        config(['health.registration_open' => false]);

        // Behind the panel's redirect the refusal really is a not-found, so a
        // caller that is not a browser is told nothing more than that.
        $this->getJson('/health/register')->assertNotFound();
    }

    public function test_register_post_is_not_found_while_pre_pilot(): void
    {
        config(['health.registration_open' => false]);

        // A complete, otherwise-valid payload: proves the refusal is the gate
        // itself and not a validation failure that a smarter payload defeats.
        $this->post('/health/register', [
            'company_name' => 'Pre Pilot Clinic',
            'company_ntn' => '9999999',
            'company_cnic' => '3520212345671',
            'org_type' => 'clinic',
            'name' => 'Stranger',
            'email' => 'stranger@example.test',
            'phone' => '03001234567',
            'password' => 'Sup3rSecret!pw',
            'password_confirmation' => 'Sup3rSecret!pw',
        ])->assertRedirect('/health/login');
    }

    public function test_login_page_stays_reachable_while_registration_is_closed(): void
    {
        config(['health.registration_open' => false]);

        // Containment must not cost the panel its own front door: a pilot
        // organisation we create by hand still has to be able to sign in.
        $response = $this->get('/health/login');

        $response->assertOk();
        $response->assertDontSee('/health/register');
    }

    public function test_opening_the_door_is_a_config_decision(): void
    {
        config(['health.registration_open' => true]);

        $this->assertTrue(HealthPanel::registrationOpen());

        // Whatever happens next, it is no longer the pre-pilot gate refusing.
        $this->get('/health/register')->assertStatus(200);
    }
}
