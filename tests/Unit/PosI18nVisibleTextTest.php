<?php

namespace Tests\Unit;

use App\Support\PosI18n;
use PHPUnit\Framework\TestCase;

final class PosI18nVisibleTextTest extends TestCase
{
    public function test_optional_and_whitespace_translation_accesses_are_extracted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-i18n-');
        self::assertNotFalse($path);
        file_put_contents($path, "TXT ['space_key']; TXT?.optional_key; window.TXT?.['bracket_key'];");

        try {
            $this->assertSame(
                ['bracket_key', 'optional_key', 'space_key'],
                PosI18n::extractKeys($path)
            );
        } finally {
            unlink($path);
        }
    }

    public function test_optional_computed_translation_access_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-i18n-');
        self::assertNotFalse($path);
        file_put_contents($path, 'const value = TXT?.[dynamicKey];');

        try {
            $this->assertNotEmpty(PosI18n::scanProblems($path));
        } finally {
            unlink($path);
        }
    }

    public function test_visible_english_phrase_is_reported(): void
    {
        $findings = PosI18n::scanVisibleText('<button title="Print customer copy">Print customer copy</button>');
        $this->assertNotEmpty($findings);
        $this->assertStringContainsString('Print customer copy', implode("\n", $findings));
    }

    public function test_translation_expression_and_technical_tokens_are_ignored(): void
    {
        $source = '<button title="{{ __(\'pos.print\') }}">{{ __(\'pos.print\') }}</button>'
            . '<span>FBR PRA POS KOT Rs VIP SKU</span>';
        $this->assertSame([], PosI18n::scanVisibleText($source));
    }

    public function test_multiline_alpine_template_and_dom_literals_are_reported(): void
    {
        $source = <<<'BLADE'
<p>
    Print customer copy
</p>
<span x-text="ready ? 'Settings Error' : window.TXT.ready"></span>
<script>
showToast(`Payment failed again`);
statusNode.textContent = 'Printer connection lost';
</script>
BLADE;

        $findings = implode("\n", PosI18n::scanVisibleText($source));
        $this->assertStringContainsString('Print customer copy', $findings);
        $this->assertStringContainsString('Settings Error', $findings);
        $this->assertStringContainsString('Payment failed again', $findings);
        $this->assertStringContainsString('Printer connection lost', $findings);
    }

    public function test_dynamic_translation_expressions_are_not_visible_literal_findings(): void
    {
        $source = <<<'BLADE'
<span x-text="window.TXT?.ready"></span>
<script>
showToast(`${window.TXT.prefix}: ${name}`);
statusNode.textContent = window.TXT ['status_ready'];
</script>
BLADE;

        $this->assertSame([], PosI18n::scanVisibleText($source));
    }

    public function test_static_english_inside_interpolated_templates_is_reported(): void
    {
        $source = <<<'BLADE'
<script>
showToast(`Payment failed for ${customer.name}`);
statusNode.textContent = `Printer failed for ${printerLabel({ id: printer.id })}`;
</script>
BLADE;

        $findings = implode("\n", PosI18n::scanVisibleText($source));
        $this->assertStringContainsString('Payment failed for', $findings);
        $this->assertStringContainsString('Printer failed for', $findings);
    }
}