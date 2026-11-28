<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrOrganization;
use App\Services\HrEmployeeInvitations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class HrEmployeeInvitationController extends Controller
{
    public function __construct(private HrEmployeeInvitations $invitations)
    {
    }

    public function show(int $organization, int $employee)
    {
        return $this->privatePage('hr.employee-invitation', $this->invitations->forEmployee($organization, $employee, (int) auth('hr')->id()));
    }

    public function issue(Request $request, int $organization, int $employee)
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $issuer = (int) auth('hr')->id();
        $token = $this->invitations->issue($organization, $employee, $issuer, (int) $data['user_id']);
        $url = route('hr.invitations.show', ['token' => $token]);

        return $this->privatePage('hr.employee-invitation', $this->invitations->forEmployee($organization, $employee, $issuer)
            + ['invitationUrl' => $url]);
    }

    public function revoke(int $organization, int $employee, int $invitation)
    {
        $this->invitations->revoke($organization, $employee, (int) auth('hr')->id(), $invitation);

        return redirect()->route('hr.employees.invitation', compact('organization', 'employee'))->with('status', 'Invitation revoked.');
    }

    public function showAcceptance(Request $request, string $token)
    {
        try {
            $invitation = $this->invitations->pending($token);
            $workspace = HrOrganization::query()->where('status', 'active')->findOrFail($invitation->organization_id);
        } catch (ModelNotFoundException) {
            return $this->unavailable($request);
        }

        return $this->privatePage('hr.accept-invitation', compact('workspace', 'token'));
    }

    public function accept(Request $request, string $token)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'two_step_code' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->invalidAcceptance($request, $token);
        }

        $data = $validator->validated();
        try {
            $organization = $this->invitations->accept($token, $data['email'], $data['password'], $data['two_step_code'] ?? null);
        } catch (ModelNotFoundException) {
            return $this->unavailable($request);
        }
        if (!$organization) {
            return $this->invalidAcceptance($request, $token);
        }

        return redirect()->route('hr.login', ['workspace' => $organization])
            ->with('status', 'Your HR workspace access is ready. Sign in with the invited account.')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function invalidAcceptance(Request $request, string $token)
    {
        $error = 'Check the details of the invited account, including its two-step code if enabled, or ask the workspace owner for a new invitation.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $error], 422);
        }

        return redirect()->route('hr.invitations.show', compact('token'))->withErrors(['invitation' => $error])
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function unavailable(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'This invitation is no longer available.'], 404);
        }

        return $this->privatePage('hr.invitation-unavailable', [], 404);
    }

    private function privatePage(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
