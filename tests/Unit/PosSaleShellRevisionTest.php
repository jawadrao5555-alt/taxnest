<?php

namespace Tests\Unit;

use App\Support\PosSaleShellRevision;
use InvalidArgumentException;
use Tests\TestCase;

class PosSaleShellRevisionTest extends TestCase
{
    public function test_legacy_screen_key_changes_when_shell_revision_is_added(): void
    {
        $legacy = '1726940000-en:1726940000';
        $fresh = PosSaleShellRevision::bootScreenRevision(
            'pra',
            '1726940000',
            'en:1726940000'
        );

        $this->assertNotSame(
            $legacy,
            $fresh,
            'A pre-fix cached document compares only the existing `s` key, so the shell revision must change that key.'
        );
        $this->assertStringStartsWith($legacy.'-', $fresh);
    }

    public function test_revision_changes_when_any_shell_dependency_changes(): void
    {
        $dir = sys_get_temp_dir().'/taxnest-shell-revision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $layout = $dir.'/layout.blade.php';
        $manifest = $dir.'/manifest.json';
        file_put_contents($layout, '<header>old</header>');
        file_put_contents($manifest, '{"app":"old.js"}');

        try {
            $before = PosSaleShellRevision::hashFiles([$layout, $manifest]);
            file_put_contents($layout, '<header>new</header>');
            $afterLayout = PosSaleShellRevision::hashFiles([$layout, $manifest]);
            file_put_contents($manifest, '{"app":"new.js"}');
            $afterManifest = PosSaleShellRevision::hashFiles([$layout, $manifest]);

            $this->assertNotSame($before, $afterLayout);
            $this->assertNotSame($afterLayout, $afterManifest);
        } finally {
            @unlink($layout);
            @unlink($manifest);
            @rmdir($dir);
        }
    }

    public function test_unknown_panel_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PosSaleShellRevision::for('unknown');
    }
}