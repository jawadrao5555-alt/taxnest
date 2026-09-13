<?php

namespace Tests\Unit;

use Tests\TestCase;

class ComposerPlatformContractTest extends TestCase
{
    public function test_composer_json_php_constraint_matches_lock_and_locked_packages(): void
    {
        $json = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);

        $declared = $json['require']['php'] ?? '';
        $this->assertNotSame('', $declared);
        $this->assertSame($declared, $lock['platform']['php'] ?? null, 'composer.lock platform.php must match composer.json require.php');

        $this->assertFalse($this->caretSatisfies($declared, '8.2.99'), 'declared PHP must not allow 8.2');
        $this->assertFalse($this->caretSatisfies($declared, '8.3.99'), 'declared PHP must not allow 8.3');
        $this->assertTrue($this->caretSatisfies($declared, '8.4.1'));
        $this->assertTrue($this->caretSatisfies($declared, PHP_VERSION), 'running PHP must satisfy composer.json');

        $needs84 = false;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $req = $package['require']['php'] ?? null;
            if (is_string($req) && (str_contains($req, '8.4') || str_contains($req, '>=8.4'))) {
                $needs84 = true;
            }
        }
        $this->assertTrue($needs84, 'lockfile is expected to contain packages requiring PHP 8.4');
    }

    /** Caret constraints used by this repo (`^8.4.1`). */
    private function caretSatisfies(string $constraint, string $version): bool
    {
        if (!preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $constraint, $m)) {
            return false;
        }
        $min = sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        $nextMajor = ((int) $m[1] + 1).'.0.0';

        return version_compare($version, $min, '>=') && version_compare($version, $nextMajor, '<');
    }
}
