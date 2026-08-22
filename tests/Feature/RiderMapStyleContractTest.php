<?php

namespace Tests\Feature;

use Tests\TestCase;

class RiderMapStyleContractTest extends TestCase
{
    public function test_delivery_style_keeps_free_live_sources_and_distinct_road_classes(): void
    {
        $style = json_decode(
            file_get_contents(public_path('vendor/maps/nestpos-en.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $layers = collect($style['layers'])->keyBy('id');

        $this->assertSame(
            'https://tiles.openfreemap.org/styles/liberty',
            $style['metadata']['nestpos:origin']
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $style['metadata']['nestpos:upstream-sha256']
        );
        $this->assertStringContainsString(
            'tiles.openfreemap.org',
            json_encode($style['sources'], JSON_THROW_ON_ERROR)
        );

        $expectedRoadColors = [
            'road_trunk_primary' => '#f6d477',
            'road_secondary_tertiary' => '#fff0b8',
            'road_minor' => '#ffffff',
            'road_service_track' => '#e8f3f5',
            'road_path_pedestrian' => '#d2eaf0',
        ];
        foreach ($expectedRoadColors as $id => $color) {
            $this->assertTrue($layers->has($id), "Missing delivery road layer {$id}");
            $this->assertSame($color, $layers[$id]['paint']['line-color']);
        }
        $this->assertCount(count($expectedRoadColors), array_unique($expectedRoadColors));

        $englishFirst = ['coalesce', ['get', 'name:en'], ['get', 'name:latin'], ['get', 'name']];
        foreach (['highway-name-major', 'highway-name-minor', 'highway-name-path'] as $id) {
            $this->assertSame($englishFirst, $layers[$id]['layout']['text-field']);
            $this->assertSame('#173f54', $layers[$id]['paint']['text-color']);
            $this->assertGreaterThanOrEqual(1.3, $layers[$id]['paint']['text-halo-width']);
        }
        $this->assertSame(14, $layers['highway-name-path']['minzoom']);
    }

    public function test_both_maps_share_versioned_resilient_basemaps_and_trails(): void
    {
        $helper = file_get_contents(public_path('vendor/maps/nestpos-basemaps.js'));
        $shop = file_get_contents(resource_path('views/pos/rider-tracking.blade.php'));
        $public = file_get_contents(resource_path('views/pos/track-public.blade.php'));

        $this->assertStringContainsString("nestpos-en.json?v=2", $helper);
        $this->assertStringContainsString('styleVersion: 2', $helper);
        $this->assertStringContainsString('deliveryTrail: deliveryTrail', $helper);
        $this->assertStringContainsString('World_Imagery', $helper);
        $this->assertStringContainsString('basemaps.cartocdn.com', $helper);
        $this->assertStringContainsString('fallback(', $helper);
        $this->assertStringNotContainsString('maps.googleapis.com', $helper);
        $this->assertStringNotContainsString('api_key', $helper);
        $this->assertStringNotContainsString('access_token', $helper);

        foreach ([$shop, $public] as $view) {
            $this->assertStringContainsString("nestpos-basemaps.js') }}?v=2", $view);
            $this->assertStringContainsString('NestPosBasemaps.streets(', $view);
            $this->assertStringContainsString('NestPosBasemaps.satellite(', $view);
            $this->assertStringContainsString('NestPosBasemaps.deliveryTrail(', $view);
            $this->assertStringContainsString('maxZoom: 21', $view);
            $this->assertStringContainsString('maxBoundsViscosity: 1.0', $view);
        }

        $this->assertStringContainsString('j.approaches || []', $shop);
        $this->assertStringNotContainsString('approaches', $public);
        $this->assertStringNotContainsString('placesDataUrl', $public);
    }
}