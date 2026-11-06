<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AdminRecoveryController extends Controller
{
    private function contacts()
    {
        return DB::table('admin_recovery_contacts');
    }

    private function owner(): AdminUser
    {
        $admin = auth('admin')->user();
        abort_unless($admin?->isSuperAdmin(), 403);

        return $admin;
    }

    public function settings()
    {
        $contacts = $this->contacts()->where('admin_user_id', $this->owner()->id)->get()->keyBy('slot');

        return response()->view('admin.recovery', ['screen' => 'settings', 'contacts' => $contacts])
            ->header('Cache-Control', 'no-store');
    }

    public function enroll(Request $request)
    {
        $admin = $this->owner();
        $data = $request->validate([
            'slot' => 'required|integer|in:1,2',
            'email' => 'required|email|max:254',
            'password' => 'required|current_password:admin',
        ]);
        $email = Str::lower($data['email']);
        $slot = (int) $data['slot'];
        $nonce = Str::random(64);
        $request->session()->put('admin.recovery_enrollment', [
            'nonce' => $nonce,
            'email' => $email,
            'slot' => $slot,
            'admin' => $admin->id,
        ]);
        $link = URL::temporarySignedRoute('admin.recovery.verify', now()->addMinutes(15), ['nonce' => $nonce]);
        if (!$this->mail($email, 'Verify TaxNest Admin recovery email', "Open this link in the browser where you requested it, then confirm the email:\n".$link)) {
            return back()->withErrors(['email' => 'The verification email could not be sent. Try again later.']);
        }

        return back()->with('status', 'Check that inbox and open the verification link in this browser.');
    }

    public function verify(Request $request, string $nonce)
    {
        $admin = $this->owner();
        $pending = $request->session()->get('admin.recovery_enrollment');
        abort_unless($pending && $pending['admin'] === $admin->id && hash_equals($pending['nonce'], $nonce), 403);
        if ($request->isMethod('get')) {
            return $this->page('verify', ['action' => $request->fullUrl()]);
        }
        $request->validate(['password' => 'required|current_password:admin']);

        $code = bin2hex(random_bytes(24));
        $prior = DB::transaction(function () use ($admin, $pending, $code) {
            $fresh = AdminUser::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->isSuperAdmin() && hash_equals($admin->getAuthPassword(), $fresh->getAuthPassword()), 403);
            $scope = $this->contacts()->where('admin_user_id', $admin->id);
            $existing = $this->contacts()->where('email', $pending['email'])->first();
            if ($existing && ((int) $existing->admin_user_id !== (int) $admin->id || (int) $existing->slot !== $pending['slot'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Choose a different recovery email for this slot.']);
            }
            $prior = (clone $scope)->pluck('email')->all();
            $this->contacts()->updateOrInsert(['admin_user_id' => $admin->id, 'slot' => $pending['slot']], [
                'email' => $pending['email'],
                'code_hash' => hash('sha256', $code),
                'link_hash' => null,
                'link_expires_at' => null,
            ]);

            return $prior;
        });
        $request->session()->forget('admin.recovery_enrollment');
        foreach (array_unique([...$prior, $pending['email']]) as $email) {
            $this->mail($email, 'TaxNest Admin recovery settings changed', 'A recovery email or backup code was updated. Contact the owner if you did not expect this change.');
        }

        return $this->page('code', ['backup' => $code]);
    }

    public function forgot()
    {
        return $this->page('forgot');
    }

    public function send(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        $email = Str::lower($data['email']);
        $key = 'admin-recovery-email:' . hash('sha256', $email);
        $contact = $this->contacts()->where('email', $email)->first();
        if ($contact && RateLimiter::attempt($key, 3, static fn () => true, 900)) {
            $token = bin2hex(random_bytes(32));
            $issued = DB::transaction(function () use ($contact, $token, $email) {
                $admin = AdminUser::whereKey($contact->admin_user_id)->lockForUpdate()->first();
                return $admin?->isSuperAdmin() && $this->contacts()->where('id', $contact->id)->where('email', $email)->update([
                    'link_hash' => hash('sha256', $token),
                    'link_expires_at' => now()->addMinutes(15),
                ]);
            });
            if ($issued) {
                $link = route('admin.recovery.reset', ['token' => $token]);
                $this->mail($email, 'Reset your TaxNest Admin password', "This one-time link expires in 15 minutes. If you did not request it, ignore this email.\n".$link);
            }
        }

        return back()->with('status', 'If this is a verified recovery email, a reset link will be sent.');
    }

    public function reset(string $token)
    {
        return $this->page('reset', ['token' => $token]);
    }

    public function backup()
    {
        return $this->page('backup');
    }

    public function finish(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required_without:code|prohibits:code|nullable|regex:/\A[a-f0-9]{64}\z/',
            'code' => 'required_without:token|nullable|regex:/\A[a-f0-9]{48}\z/',
            'password' => ['required', 'confirmed', 'string', 'max:72', Password::min(14), function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must be at most 72 bytes.');
                }
            }],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        $viaLink = isset($data['token']);
        $column = $viaLink ? 'link_hash' : 'code_hash';
        $hash = hash('sha256', $viaLink ? $data['token'] : $data['code']);
        $contact = $this->contacts()->where($column, $hash)->first();
        $result = $contact ? DB::transaction(function () use ($contact, $column, $hash, $viaLink, $data) {
            $admin = AdminUser::whereKey($contact->admin_user_id)->lockForUpdate()->first();
            $fresh = $this->contacts()->where('id', $contact->id)->where($column, $hash)
                ->when($viaLink, fn ($q) => $q->where('link_expires_at', '>', now()))->first();
            if (!$admin?->isSuperAdmin() || !$fresh) {
                return null;
            }
            $admin->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
                'recovery_session_version' => (string) Str::uuid(),
            ])->save();
            $scope = $this->contacts()->where('admin_user_id', $admin->id);
            (clone $scope)->update(['link_hash' => null, 'link_expires_at' => null]);
            if (!$viaLink) {
                (clone $scope)->where('id', $fresh->id)->update(['code_hash' => null]);
            }

            return ['admin' => $admin->id, 'slot' => $fresh->slot, 'emails' => $scope->pluck('email')->all()];
        }) : null;
        if (!$result) {
            return back()->withErrors(['recovery' => 'This recovery link or code is invalid, expired, or already used.']);
        }

        \Log::notice('SaaS admin password recovered', ['admin_id' => $result['admin'], 'slot' => $result['slot'], 'method' => $viaLink ? 'email' : 'code']);
        foreach ($result['emails'] as $email) {
            $this->mail($email, 'TaxNest Admin password changed', 'The shared admin password was reset. Existing admin sessions have been revoked. Contact the owner if you did not expect this reset.');
        }
        auth('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Password reset. Sign in with the new password.');
    }

    private function page(string $screen, array $data = [])
    {
        return response()->view('admin.recovery', ['screen' => $screen] + $data)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function mail(string $email, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, fn ($message) => $message->to($email)->subject($subject));
            return true;
        } catch (\Throwable) {
            \Log::warning('SaaS admin recovery email delivery failed', ['subject' => $subject]);
            return false;
        }
    }
}
