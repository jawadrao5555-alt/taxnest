<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Static regression for the category WSL runner defect:
 * Lab 2B must not run Laravel PHPUnit Feature suites with DB_CONNECTION=mysql
 * because Tests\TestCase requires sqlite :memory:.
 */
class CategoryNativeWslVerifyRunnerTest extends TestCase
{
    private function runner(): string
    {
        return file_get_contents(base_path('scripts/category-native-wsl-verify.sh'));
    }

    #[Test]
    public function runner_keeps_sqlite_phpunit_and_uses_native_mariadb_lab(): void
    {
        $src = $this->runner();
        $this->assertStringContainsString('category-native-lab2-sqlite-check.sh', $src);
        $this->assertStringContainsString('category-native-lab2-mariadb-check.sh', $src);
        $this->assertStringContainsString('detect_loopback_mariadb_port', $src);
        $this->assertStringNotContainsString('phpunit-category-mariadb.xml', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/vendor\/bin\/phpunit[\s\S]{0,200}DB_CONNECTION=mysql|DB_CONNECTION=mysql[\s\S]{0,200}vendor\/bin\/phpunit/',
            $src
        );
    }

    #[Test]
    public function runner_refuses_non_loopback_hosts_and_locks_lab_db_name(): void
    {
        $src = $this->runner();
        $this->assertStringContainsString('LAB_DB=taxnest_category_lab', $src);
        $this->assertStringContainsString('127.0.0.1|localhost', $src);
        $this->assertStringContainsString('DB host must be loopback', $src);
    }

    #[Test]
    public function mariadb_lab_scripts_exist_and_refuse_customer_databases(): void
    {
        $sh = base_path('scripts/tests/category-native-lab2-mariadb-check.sh');
        $php = base_path('scripts/tests/category-native-lab2-mariadb-check.php');
        $this->assertFileExists($sh);
        $this->assertFileExists($php);
        $phpSrc = file_get_contents($php);
        $this->assertStringContainsString("taxnest_category_lab", $phpSrc);
        $this->assertStringContainsString('non-loopback', $phpSrc);
        $this->assertStringContainsString('PosServiceWorkOrderService', $phpSrc);
    }

    #[Test]
    public function category_qa_seed_attaches_subscription_and_marks_whats_new(): void
    {
        $seed = file_get_contents(base_path('scripts/cloud-local-category-qa-seed.php'));
        $this->assertStringContainsString('Subscription', $seed);
        $this->assertStringContainsString('app_update_seens', $seed);
        $this->assertStringContainsString("'override_type' => 'none'", $seed);
        $this->assertStringContainsString("'active' => true", $seed);
    }
}
