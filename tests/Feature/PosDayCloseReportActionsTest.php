<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosDayCloseReportActionsTest extends TestCase
{
    private function links(?object $report, bool $isolated = false): array
    {
        $html = view('pos.partials.day-close-report-actions', [
            'existingReport' => $report,
            'dcIso' => $isolated,
            'date' => '2026-10-31',
        ])->render();
        $document = new \DOMDocument();
        @$document->loadHTML('<html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            $links[trim($link->textContent)] = html_entity_decode($link->getAttribute('href'));
        }

        return $links;
    }

    public function test_open_day_has_only_pdf_and_thermal_for_selected_date(): void
    {
        $this->assertSame([
            'Day Close Report — PDF' => route('pos.day-close-x-pdf', ['date' => '2026-10-31']),
            'Day Close Report — Thermal' => route('pos.day-close-x-thermal', ['date' => '2026-10-31']),
        ], $this->links(null));
    }

    public function test_closed_day_uses_frozen_report_routes(): void
    {
        $this->assertSame([
            'Day Close Report — PDF' => route('pos.day-close-pdf', 42),
            'Day Close Report — Thermal' => route('pos.day-close-thermal', 42),
        ], $this->links((object) ['id' => 42]));
    }

    public function test_isolated_cashier_does_not_gain_frozen_company_report_links(): void
    {
        $this->assertSame([], $this->links((object) ['id' => 42], true));
        $this->assertCount(2, $this->links(null, true));
    }
}
