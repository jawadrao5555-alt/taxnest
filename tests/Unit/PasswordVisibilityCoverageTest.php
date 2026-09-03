<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PasswordVisibilityCoverageTest extends TestCase
{
    public function test_shared_enhancer_is_loaded_and_accessible(): void
    {
        $app = file_get_contents(__DIR__ . '/../../resources/js/app.js');
        $script = file_get_contents(__DIR__ . '/../../resources/js/password-visibility.js');

        $this->assertStringContainsString("import './password-visibility'", $app);
        $this->assertStringContainsString("button.type = 'button'", $script);
        $this->assertStringContainsString("'aria-label'", $script);
        $this->assertStringContainsString("'aria-pressed'", $script);
        $this->assertStringContainsString("input.setSelectionRange(start, end)", $script);
        $this->assertStringContainsString('MutationObserver', $script);
        $this->assertStringContainsString("input.type = visible ? 'text' : 'password'", $script);
        $this->assertStringContainsString("input.dataset.passwordToggleReady === 'true'", $script);
        $this->assertStringContainsString("input.hasAttribute(':type')", $script);
        $this->assertStringContainsString('[data-password-group-toggle]', $script);
        $this->assertStringContainsString('ensureStyles()', $script);
        $this->assertStringContainsString("event.target.closest?.('[data-password-group-toggle]')", $script);
        $this->assertStringContainsString('focused?.setSelectionRange(start, end)', $script);
    }

    public function test_all_blade_password_inputs_are_covered_or_explicitly_exempt(): void
    {
        $views = realpath(__DIR__ . '/../../resources/views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views));
        $failures = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/<input\b[^>]*(?:type\s*=\s*["\']password["\']|:type\s*=\s*["\'][^"\']*password[^"\']*["\'])[^>]*>/i', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$tag, $offset]) {
                // Static password inputs are covered by the global enhancer.
                // Dynamic :type inputs must already provide their own control.
                if (! str_contains($tag, ':type=')) {
                    continue;
                }

                $nearby = substr($source, $offset, 1800);
                if (
                    ! str_contains($nearby, 'data-password-toggle-exempt')
                    && ! preg_match('/<(?:button|input)\b[^>]*(?:showPw|showKey|showPassword)/i', $nearby)
                ) {
                    $line = 1 + substr_count(substr($source, 0, $offset), "\n");
                    $failures[] = str_replace($views . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ':' . $line;
                }
            }
        }

        $this->assertSame([], $failures, "Dynamic masked inputs need their own toggle or data-password-toggle-exempt:\n" . implode("\n", $failures));
    }

    public function test_localized_show_and_hide_labels_exist(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $lang = file_get_contents(__DIR__ . "/../../lang/{$locale}/pos.php");
            $this->assertStringContainsString("'ti_show_password'", $lang, "{$locale} show-password label missing");
            $this->assertStringContainsString("'ti_hide_password'", $lang, "{$locale} hide-password label missing");
        }
    }

    public function test_standalone_login_pages_load_the_enhancer_bundle(): void
    {
        $views = realpath(__DIR__ . '/../../resources/views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views));
        $failures = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (
                ! str_starts_with(ltrim($source), '<!DOCTYPE html>')
                || ! preg_match('/type\s*=\s*["\']password["\']/i', $source)
            ) {
                continue;
            }

            if (
                ! str_contains($source, 'resources/js/app.js')
                && ! str_contains($source, "partials.fast-first-paint")
            ) {
                $failures[] = str_replace($views . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $failures, "Standalone masked-input pages do not load password visibility:\n" . implode("\n", $failures));
    }

    public function test_split_pin_fields_keep_their_sibling_navigation_and_use_one_group_toggle(): void
    {
        foreach (['pos/partials/pin-modal.blade.php', 'fbr-pos/partials/pin-modal.blade.php'] as $view) {
            $source = file_get_contents(__DIR__ . "/../../resources/views/{$view}");
            $this->assertSame(6, substr_count($source, 'data-password-toggle-exempt="true"'), "{$view} PIN digits must not be wrapped");
            $this->assertSame(1, substr_count($source, 'data-password-group-toggle='), "{$view} needs exactly one group eye control");
            $this->assertStringContainsString('nextElementSibling', $source);
            $this->assertStringContainsString('previousElementSibling', $source);
        }
    }
}