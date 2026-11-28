<?php

namespace App\Http\Controllers;

use App\Mail\UpcomingProductConsentMail;
use App\Models\UpcomingProduct;
use App\Models\UpcomingProductInterest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

final class UpcomingProductController extends Controller
{
    public function index(): View
    {
        $products = UpcomingProduct::published()->orderBy('sort_order')->orderBy('id')->get();

        return view('public.upcoming-products.index', compact('products'));
    }

    public function interest(Request $request, string $slug): RedirectResponse
    {
        $product = UpcomingProduct::published()->where('slug', $slug)->firstOrFail();
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'purpose' => ['required', 'string', 'in:'.implode(',', array_keys(UpcomingProductInterest::PURPOSES))],
            'consent' => ['accepted'],
        ]);
        if (app()->isProduction() && !$this->hasDeliveryTransport((string) config('mail.default'))) {
            return redirect(route('upcoming-products.index').'#'.$product->slug)
                ->withErrors(['email' => 'Email confirmation is temporarily unavailable. Please try again later.']);
        }

        $email = strtolower($input['email']);
        $purpose = $input['purpose'];
        $token = bin2hex(random_bytes(32));
        $matching = [
            'upcoming_product_id' => $product->id,
            'email_key' => hash_hmac('sha256', 'taxnest-upcoming-consent:'.$email, (string) config('app.key')),
            'purpose' => $purpose,
        ];
        $now = now();
        $submission = [
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'consent_version' => UpcomingProductInterest::CONSENT_VERSION,
            'consented_at' => $now,
            'confirmation_sent_at' => $now,
            'confirmed_at' => null,
            'withdrawn_at' => null,
        ];

        $send = DB::transaction(function () use ($product, $matching, $submission, $now): bool {
            // Closing or unpublishing a preview prevents a stale form submission.
            $upcoming = UpcomingProduct::published()->lockForUpdate()->findOrFail($product->id);
            abort_if($upcoming->status === 'temporarily_unavailable', 404);
            $interest = UpcomingProductInterest::firstOrCreate($matching, $submission);
            if ($interest->wasRecentlyCreated) {
                return true;
            }
            $interest = UpcomingProductInterest::lockForUpdate()->findOrFail($interest->id);
            if (!$interest->withdrawn_at && ($interest->confirmed_at || $interest->confirmation_sent_at->gt($now->copy()->subMinutes(15)))) {
                return false;
            }

            $interest->update($submission);

            return true;
        });

        if ($send) {
            $relativeUrl = route('upcoming-products.manage', ['token' => $token], false);
            try {
                Mail::to($email)->send(new UpcomingProductConsentMail(
                    $product->name,
                    UpcomingProductInterest::PURPOSES[$purpose],
                    rtrim((string) config('app.url'), '/').$relativeUrl,
                ));
            } catch (Throwable) {
                Log::warning('Upcoming product consent mail could not be delivered.', ['product_id' => $product->id]);
            }
        }

        return redirect(route('upcoming-products.index').'#'.$product->slug)->with(
            'interest_status',
            'If there is a new request to confirm, check your inbox for a confirmation link. You can try again later if it does not arrive.',
        );
    }

    public function manage(Request $request): View
    {
        $token = $this->token($request->query('token'));
        $interest = $this->interestByToken($token)->with('product')->firstOrFail();

        return view('public.upcoming-products.manage', [
            'token' => $token,
            'interest' => $interest,
            'expired' => $interest->confirmation_sent_at->lte(now()->subDay()),
        ]);
    }

    public function changeInterest(Request $request): RedirectResponse
    {
        $token = $this->token($request->input('token'));
        $action = $request->validate(['action' => ['required', 'in:confirm,withdraw']])['action'];
        DB::transaction(function () use ($token, $action): void {
            $interest = $this->interestByToken($token)->lockForUpdate()->firstOrFail();
            if ($interest->withdrawn_at) {
                return;
            }
            if ($action === 'withdraw') {
                $interest->update(['withdrawn_at' => now(), 'email' => null]);

                return;
            }
            if ($interest->confirmed_at) {
                return;
            }
            abort_if($interest->confirmation_sent_at->lte(now()->subDay()), 410, 'Confirmation link expired.');
            $interest->update(['confirmed_at' => now()]);
        });

        return redirect()->route('upcoming-products.manage', ['token' => $token]);
    }

    private function token(mixed $token): string
    {
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token), 404);

        return $token;
    }

    private function interestByToken(string $token): Builder
    {
        return UpcomingProductInterest::where('token_hash', hash('sha256', $token));
    }

    private function hasDeliveryTransport(string $mailer, array $visited = []): bool
    {
        $settings = config('mail.mailers.'.$mailer);
        $transport = is_array($settings) ? ($settings['transport'] ?? null) : null;

        // Log/array mailers are useful for fixtures. A production fallback to
        // a log would record a visitor's address and consent link instead.
        if (in_array($mailer, $visited, true) || in_array($transport, [null, 'log', 'array'], true)) {
            return false;
        }
        if (!in_array($transport, ['failover', 'roundrobin'], true)) {
            return true;
        }

        $nested = $settings['mailers'] ?? [];
        return $nested !== [] && collect($nested)->every(fn (string $name) => $this->hasDeliveryTransport($name, [...$visited, $mailer]));
    }
}
