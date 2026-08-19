<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

class AppUrlSchemeFallbackTest extends TestCase
{
    public function test_local_artisan_server_keeps_http_product_links_when_app_url_is_https(): void
    {
        $urls = $this->productUrlGenerator('http://127.0.0.1:5000/fbr-pos/products', 'cli-server');

        $this->assertFalse(AppServiceProvider::shouldApplyAppUrlSchemeFallback('cli-server'));
        $this->assertSame(
            'http://127.0.0.1:5000/fbr-pos/products/create',
            $urls->route('fbrpos.products.create')
        );
    }

    public function test_production_web_request_keeps_https_fallback_when_proxy_omits_proto(): void
    {
        $urls = $this->productUrlGenerator('http://taxnest.com.pk/fbr-pos/products', 'fpm-fcgi');

        $this->assertTrue(AppServiceProvider::shouldApplyAppUrlSchemeFallback('fpm-fcgi'));
        $this->assertSame(
            'https://taxnest.com.pk/fbr-pos/products/create',
            $urls->route('fbrpos.products.create')
        );
    }

    private function productUrlGenerator(string $requestUrl, string $sapi): UrlGenerator
    {
        $routes = new RouteCollection();
        $route = new Route(['GET'], '/fbr-pos/products/create', []);
        $route->name('fbrpos.products.create');
        $routes->add($route);

        $urls = new UrlGenerator($routes, Request::create($requestUrl));
        if (AppServiceProvider::shouldApplyAppUrlSchemeFallback($sapi)) {
            $urls->forceScheme('https');
        }

        return $urls;
    }
}