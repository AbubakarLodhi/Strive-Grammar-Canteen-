<?php

namespace App\Http\Controllers;

use App\Models\DemoVisitorSession;
use App\Services\Demo\TemporaryDemoCleanup;
use App\Services\Demo\TemporaryDemoProvisioner;
use App\Support\DemoAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DemoAccountController extends Controller
{
    public function __invoke(
        Request $request,
        TemporaryDemoProvisioner $provisioner,
        TemporaryDemoCleanup $cleanup,
    ): RedirectResponse {
        $visitorSession = DemoAccount::findVisitorSession($request);

        if ($visitorSession?->isExpired()) {
            $cleanup->purgeExpiredSession($visitorSession);

            if (DemoAccount::canStartFreshDemoAfterReset($visitorSession)) {
                $visitorSession->delete();
                $visitorSession = null;
            } else {
                return redirect()
                    ->route('filament.merchant.auth.login')
                    ->with(
                        'demo_expired',
                        'Your demo time has ended. You can start a new demo after the daily reset at '.DemoAccount::dailyResetLabel().'.'
                    );
            }
        }

        if (! $visitorSession) {
            $now = now();
            $sessionId = Str::uuid()->toString();

            $visitorSession = DemoVisitorSession::query()->create([
                'id' => $sessionId,
                'visitor_hash' => DemoAccount::visitorFingerprint($request),
                'ip_address' => (string) $request->ip(),
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes(DemoAccount::sessionTimeoutMinutes()),
                'last_seen_at' => $now,
            ]);
        } else {
            $visitorSession->touchLastSeen();
        }

        $merchant = $provisioner->provisionForSession($visitorSession->id);

        if ($visitorSession->merchant_id !== $merchant->id) {
            $visitorSession->update(['merchant_id' => $merchant->id]);
        }

        if (! $merchant->is_active) {
            return redirect()
                ->route('filament.merchant.auth.login')
                ->with('error', 'Demo account is not available yet. Please contact support.');
        }

        $request->session()->put('demo_visitor_session_id', $visitorSession->id);

        Auth::guard('merchant')->login($merchant, remember: true);

        $request->session()->save();

        return redirect()->route('filament.merchant.pages.dashboard');
    }
}
