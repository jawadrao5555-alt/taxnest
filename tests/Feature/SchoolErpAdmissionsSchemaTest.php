<?php

namespace Tests\Feature;

use App\Services\SchoolAdmissionsService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolErpAdmissionsSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', fn (Blueprint $table) => $table->id());

        $migration = require database_path('migrations/2027_01_01_000006_create_school_erp_admissions_tables.php');
        $migration->up();

        foreach ([1, 2] as $id) {
            DB::table('companies')->insert(['id' => $id]);
            DB::table('school_erp_students')->insert([
                'id' => $id, 'company_id' => $id, 'student_reference' => 'ADM-001', 'display_name' => 'Synthetic Student',
            ]);
            DB::table('school_erp_terms')->insert([
                'id' => $id, 'company_id' => $id, 'code' => '2027', 'name' => '2027',
                'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            ]);
        }
    }

    public function test_each_school_uses_its_own_student_references_and_terms(): void
    {
        foreach ([1, 2] as $id) {
            $this->enroll($id, $id, $id);
        }
        $this->assertDatabaseCount('school_erp_enrollments', 2);
        $this->assertDatabaseHas('school_erp_enrollments', ['company_id' => 2, 'student_id' => 2, 'term_id' => 2]);

        $this->expectException(QueryException::class);
        $this->enroll(1, 1, 1);
    }

    #[DataProvider('crossTenantEnrollments')]
    public function test_a_school_cannot_enroll_another_schools_student_or_use_its_term(int $student, int $term): void
    {
        $this->expectException(QueryException::class);
        $this->enroll(1, $student, $term);
    }

    public static function crossTenantEnrollments(): array
    {
        return ['another school student' => [2, 1], 'another school term' => [1, 2]];
    }

    public function test_admission_retries_preserve_the_same_enrollment_and_date(): void
    {
        $admissions = app(SchoolAdmissionsService::class);
        $id = $admissions->enroll(1, 1, 1, '2027-01-01');
        $this->assertSame($id, $admissions->enroll(1, 1, 1, '2027-01-01'));
        $this->assertDatabaseCount('school_erp_enrollments', 1);

        $this->expectException(ValidationException::class);
        $admissions->enroll(1, 1, 1, '2027-01-02');
    }

    #[DataProvider('invalidAdmissions')]
    public function test_admission_requires_same_school_and_a_valid_term_date(int $student, int $term, string $date, string $field): void
    {
        try {
            app(SchoolAdmissionsService::class)->enroll(1, $student, $term, $date);
            $this->fail('The admission should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('school_erp_enrollments', 0);
        }
    }

    public static function invalidAdmissions(): array
    {
        return [
            'another school student' => [2, 1, '2027-01-01', 'student_id'],
            'another school term' => [1, 2, '2027-01-01', 'term_id'],
            'invalid calendar date' => [1, 1, '2027-02-30', 'admitted_on'],
            'outside term' => [1, 1, '2028-01-01', 'admitted_on'],
        ];
    }

    private function enroll(int $company, int $student, int $term): void
    {
        DB::table('school_erp_enrollments')->insert([
            'company_id' => $company, 'student_id' => $student, 'term_id' => $term, 'admitted_on' => '2027-01-01',
        ]);
    }
}
