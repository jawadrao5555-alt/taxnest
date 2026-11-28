<?php

namespace App\Http\Controllers;

use App\Models\WebsiteClient;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

final class WebsiteClientDirectoryController extends Controller
{
    private const ITEMS_PER_PAGE = 12;

    public function __invoke(Request $request): View
    {
        $products = [
            'di' => WebsiteClient::PRODUCTS['di'],
            'pos' => WebsiteClient::PRODUCTS['pos'],
            'fbrpos' => WebsiteClient::PRODUCTS['fbrpos'],
            'erp' => WebsiteClient::PRODUCTS['erp'],
        ];
        $requested = $request->query('product');
        $product = is_string($requested) && isset($products[$requested]) ? $requested : null;
        $page = Paginator::resolveCurrentPage();
        $offset = min($page - 1, intdiv(PHP_INT_MAX, self::ITEMS_PER_PAGE)) * self::ITEMS_PER_PAGE;
        $cards = WebsiteClient::publicCards($product === 'pos' ? ['pos', 'standalone'] : $product, self::ITEMS_PER_PAGE + 1, $offset);
        $clients = new Paginator($cards, self::ITEMS_PER_PAGE, $page, ['path' => route('website-clients.index')]);
        if ($product !== null) {
            $clients->appends('product', $product);
        }

        $groupedCards = $clients->getCollection()->groupBy(fn (WebsiteClient $card) => $card->product === 'standalone' ? 'pos' : $card->product);
        $groups = collect($products)->map(fn (string $label, string $key) => $groupedCards->get($key))->filter();

        return view('public.website-client-directory', compact('products', 'product', 'clients', 'groups'));
    }
}
