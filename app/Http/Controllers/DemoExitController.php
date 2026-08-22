<?php

namespace App\Http\Controllers;

use App\Services\Demo\TemporaryDemoCleanup;
use App\Support\DemoAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoExitController extends Controller
{
    public function __invoke(Request $request, TemporaryDemoCleanup $cleanup): RedirectResponse
    {
        $visitorSession = DemoAccount::currentVisitorSession()
            ?? DemoAccount::findVisitorSession($request);

        if ($visitorSession?->isExpired()) {
            $cleanup->purgeExpiredSession($visitorSession);

            Auth::guard('merchant')->logout();
            $request->session()->forget('demo_visitor_session_id');

            return $this->redirectWithNotice('demo_expired');
        }

        if (DemoAccount::isDemoMerchant()) {
            Auth::guard('merchant')->logout();
        }

        $request->session()->forget('demo_visitor_session_id');

        return $this->redirectWithNotice('demo_left');
    }

    private function redirectWithNotice(string $notice): RedirectResponse
    {
        $message = (string) config("demo.notices.{$notice}", '');

        $flashKey = $notice === 'demo_expired' ? 'demo_expired' : 'status';

        return redirect()
            ->route('filament.merchant.auth.login', ['notice' => $notice])
            ->with($flashKey, $message);
    }
}
