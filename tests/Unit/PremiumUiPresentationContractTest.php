<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The premium visual layer is deliberately opt-in.  These checks protect the
 * opt-in seam (and its accessibility/responsive affordances), rather than
 * asserting colours, spacing, or any other design detail.
 */
class PremiumUiPresentationContractTest extends TestCase
{
    private const LAYOUTS = [
        'admin-app',
        'pos-app',
        'fbr-pos-app',
        'health-app',
        'app',
    ];

    private const PREMIUM_STYLESHEETS = [
        'taxnest-premium.css',
        'premium-native.css',
        'premium-admin.css',
        'premium-pos.css',
    ];

    public function test_all_application_shells_opt_in_to_the_premium_partial_and_shell_class(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $source = $this->read("views/layouts/{$layout}.blade.php");

            $this->assertMatchesRegularExpression(
                "/@include\\s*\\(\\s*['\"]partials\\.premium-ui['\"]/",
                $source,
                "{$layout} must include the premium-ui partial",
            );

            $body = $this->openingTag($source, 'body');
            $this->assertMatchesRegularExpression(
                '/(?:^|\\s)tn-premium-shell(?:\\s|$)/',
                $this->classValue($body),
                "{$layout} body must carry the tn-premium-shell opt-in class",
            );
        }
    }

    public function test_premium_stylesheets_are_present_loaded_and_cache_versioned(): void
    {
        $sources = [
            $this->read('views/partials/premium-ui.blade.php'),
            ...array_map(
                fn (string $layout): string => $this->read("views/layouts/{$layout}.blade.php"),
                self::LAYOUTS,
            ),
            $this->read('views/pos/universal.blade.php'),
            $this->read('views/fbr-pos/universal.blade.php'),
        ];

        foreach (self::PREMIUM_STYLESHEETS as $stylesheet) {
            $path = public_path("css/{$stylesheet}");
            $this->assertFileExists($path, "missing premium stylesheet {$stylesheet}");
            $this->assertNotEmpty(
                trim((string) file_get_contents($path)),
                "premium stylesheet {$stylesheet} must not be empty",
            );

            $loadedAndVersioned = false;
            foreach ($sources as $source) {
                $offset = 0;
                while (($position = strpos($source, "css/{$stylesheet}", $offset)) !== false) {
                    /*
                     * Keep this placement-agnostic: Blade's asset() call and
                     * its ?v=filemtime suffix are commonly separated by a
                     * closing expression, but remain close to the same link.
                     */
                    $window = substr($source, max(0, $position - 180), 420);
                    if (preg_match('/\\?v\\s*=/', $window)) {
                        $loadedAndVersioned = true;
                        break 2;
                    }
                    $offset = $position + 1;
                }
            }

            $this->assertTrue(
                $loadedAndVersioned,
                "{$stylesheet} must be referenced by a stylesheet link with a cache version",
            );
        }
    }

    public function test_both_sale_templates_have_the_premium_root_without_changing_their_markup_contract(): void
    {
        foreach (['pos', 'fbr-pos'] as $surface) {
            $source = $this->read("views/{$surface}/universal.blade.php");
            $this->assertMatchesRegularExpression(
                '/<[a-z][^>]*\\bdata-tn-sale-root\\b[^>]*>/i',
                $source,
                "{$surface} universal sale template must expose its sale root",
            );

            preg_match('/<[a-z][^>]*\\bdata-tn-sale-root\\b[^>]*>/i', $source, $root);
            $this->assertMatchesRegularExpression(
                '/(?:^|\\s)tn-premium-sale(?:\\s|$)/',
                $this->classValue($root[0]),
                "{$surface} sale root must carry the tn-premium-sale presentation class",
            );
        }
    }

    public function test_new_css_covers_dark_mode_motion_preferences_and_responsive_layouts_without_remote_dependencies(): void
    {
        $css = '';
        foreach (self::PREMIUM_STYLESHEETS as $stylesheet) {
            $path = public_path("css/{$stylesheet}");
            $this->assertFileExists($path, "missing premium stylesheet {$stylesheet}");
            $css .= "\n" . (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                '/(?:https?:)?\\/\\/|@import\\s/i',
                (string) file_get_contents($path),
                "{$stylesheet} must not pull a remote CSS dependency",
            );
        }

        $this->assertMatchesRegularExpression(
            '/\\.dark\\b/i',
            $css,
            'premium CSS must retain an explicit dark-mode contract',
        );
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion\\s*:\\s*reduce/i',
            $css,
            'premium CSS must provide a reduced-motion path',
        );
        $this->assertMatchesRegularExpression(
            '/@media\\s*\\([^)]*(?:max|min)-width\\s*:/i',
            $css,
            'premium CSS must include responsive media rules',
        );
    }

    private function read(string $relativePath): string
    {
        $path = resource_path($relativePath);
        $this->assertFileExists($path, "missing presentation contract file {$relativePath}");

        return (string) file_get_contents($path);
    }

    private function openingTag(string $source, string $tag): string
    {
        $this->assertMatchesRegularExpression(
            "/<{$tag}\\b[^>]*>/i",
            $source,
            "missing <{$tag}> opening tag",
        );

        preg_match("/<{$tag}\\b[^>]*>/i", $source, $matches);

        return $matches[0];
    }

    private function classValue(string $openingTag): string
    {
        preg_match('/\\bclass\\s*=\\s*(["\'])(.*?)\\1/is', $openingTag, $matches);

        return $matches[2] ?? '';
    }
}