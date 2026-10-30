<?php
namespace Tests\Unit;
use App\Models\Ingredient;
use App\Services\IngredientUnitConversion as Units;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class IngredientUnitConversionTest extends TestCase
{
    public function test_standard_defaults_and_explicit_saved_mapping_are_distinct(): void
    {
        $this->assertSame(1000.0, Units::configuredFactor('kg', 'g', null));
        $this->assertSame(.001, Units::configuredFactor('ml', 'ltr', null));
        $saved = new Ingredient(['unit'=>'g','base_unit'=>'kg','conversion_factor'=>900]);
        $this->assertSame(900.0, Units::factor($saved, 'kg'));
        $this->assertSame(900.0, Units::configuredFactor('kg','g',null,$saved));
        $this->assertSame(12.0, Units::configuredFactor('pack','pcs',12));
        $this->assertSame(1.0, Units::factor($saved, 'g'));
    }
    public function test_incompatible_dimensions_cannot_be_waived_by_a_factor(): void
    {
        $this->expectException(ValidationException::class);
        Units::configuredFactor('kg','ml',1000);
    }
    public function test_unconfigured_pack_size_is_never_guessed(): void
    {
        $this->expectException(ValidationException::class);
        Units::configuredFactor('pack','g',null);
    }
}
