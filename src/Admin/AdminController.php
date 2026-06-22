<?php

declare(strict_types=1);

namespace App\Admin;

use Anokii\Controller\AnokiiAdminController;
use Anokii\Dashboard\DashboardGate;
use Anokii\Support\Auth;
use App\Controller\AnalyticsDashboardController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Middleware\CsrfMiddleware;

/**
 * Framework-auth gate for rhtcircle's admin dashboards.
 *
 * Replaces the Caddy basic_auth that previously sat in front of /admin/*. The
 * dashboard routes are registered allowAll() at the framework layer (so the
 * framework AccessChecker does not gate them), and this controller enforces the
 * session itself, reusing the Anokii package primitives rather than inventing a
 * new check:
 *
 *   - {@see DashboardGate} for the page redirect helpers (login path is app-owned,
 *     never the framework default /login);
 *   - {@see Auth} for the session-backed login / logout / current account, which
 *     ignores the framework dev-fallback account so only a genuine login opens
 *     the gate;
 *   - {@see AdminRoles}::ACCESS_ADMIN for authorization (administrator passes by
 *     short-circuit; a dashboard-only operator passes via the stamped permission).
 *
 * An unauthenticated page request is redirected to /admin/login; an authenticated
 * account that lacks the admin permission gets a 403. After the gate passes, the
 * request is delegated to the underlying dashboard controller (the lean Anokii
 * admin from the package, or the rhtcircle analytics dashboard).
 *
 * NOTE (package follow-up, alpha.4): this gate belongs in the Anokii package's
 * admin surface so oiatc and fnpi share it. Lifting it requires a package release,
 * so it is wired here for rhtcircle now and flagged for the package line.
 */
final class AdminController extends DashboardGate
{
    public function __construct(
        ?EntityTypeManager $entityTypeManager,
        private readonly AnokiiAdminController $anokiiAdmin,
        private readonly AnalyticsDashboardController $analyticsDashboard,
    ) {
        parent::__construct($entityTypeManager);
    }

    protected function loginPath(): string
    {
        return '/admin/login';
    }

    /** Gated: the lean Anokii admin (graph counts + the no-PII content-gap log). */
    public function anokii(Request $request): Response
    {
        $gate = $this->guard($request);

        return $gate ?? $this->anokiiAdmin->index($request);
    }

    /** Gated: the first-party analytics dashboard. */
    public function analytics(Request $request): Response
    {
        $gate = $this->guard($request);

        return $gate ?? $this->analyticsDashboard->index($request);
    }

    /** The login form. An already-signed-in admin is sent on to the dashboard. */
    public function loginForm(Request $request): Response
    {
        $already = $this->redirectIfAuthenticated($this->safeNext($request));
        if ($already !== null) {
            return $already;
        }

        return $this->loginPage($this->safeNext($request), $request->query->get('error') !== null);
    }

    /** Validate credentials and open a session, then redirect to the dashboard. */
    public function loginSubmit(Request $request): Response
    {
        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $next = $this->safeNext($request);

        $user = Auth::login($this->entityTypeManager, $email, $password);
        if ($user === null || !$user->hasPermission(AdminRoles::ACCESS_ADMIN)) {
            // Do not leave a half-open session for a real account without access.
            if ($user !== null) {
                Auth::logout();
            }

            return $this->loginPage($next, true);
        }

        // Rotate the CSRF token across the auth boundary (login fixation hygiene).
        CsrfMiddleware::regenerate();

        return new RedirectResponse($next);
    }

    /** Clear the session and return to the login form. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        return new RedirectResponse($this->loginPath());
    }

    /**
     * Gate a dashboard page. Null when the request may proceed; a redirect to the
     * login form when anonymous; a 403 when signed in without the admin permission.
     */
    private function guard(Request $request): ?Response
    {
        $redirect = Auth::requireAccountOrRedirect(
            $this->entityTypeManager,
            $this->loginPath() . '?next=' . rawurlencode($request->getPathInfo()),
        );
        if ($redirect !== null) {
            return $redirect;
        }

        $user = $this->currentUser();
        if ($user === null || !$user->hasPermission(AdminRoles::ACCESS_ADMIN)) {
            return new Response($this->forbiddenPage(), 403, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        return null;
    }

    /**
     * A same-origin redirect target after login: only an app-local /admin path is
     * accepted, defaulting to /admin/anokii. Prevents open-redirect via ?next=.
     */
    private function safeNext(Request $request): string
    {
        $next = (string) $request->query->get('next', '');
        if ($next !== '' && str_starts_with($next, '/admin') && !str_starts_with($next, '/admin/login')) {
            return $next;
        }

        return '/admin/anokii';
    }

    private function loginPage(string $next, bool $error): Response
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $token = CsrfMiddleware::token();
        $err = $error
            ? '<p class="err">Incorrect email or password, or this account is not an administrator.</p>'
            : '';
        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Admin sign in · Robinson Huron Treaty</title>
            <style>
              :root { --bg:#fbfaff; --surface:#fff; --ink:#221d33; --ink-3:#6f6688; --rule:#e4def2; --indigo:#4f2fb0; --indigo-deep:#38217f; --magenta:#c41d8f; --sans:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
              * { box-sizing:border-box; }
              body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font-family:var(--sans); }
              .card { width:100%; max-width:360px; background:var(--surface); border:1px solid var(--rule); border-radius:14px; padding:28px 26px; margin:24px; }
              h1 { font-size:19px; margin:0 0 4px; color:var(--indigo-deep); }
              p.sub { margin:0 0 20px; color:var(--ink-3); font-size:13.5px; }
              label { display:block; font-size:13px; font-weight:600; margin:14px 0 5px; }
              input { width:100%; padding:11px 13px; font-size:15px; border:1px solid #d6cdea; border-radius:9px; background:#fff; color:var(--ink); }
              input:focus { outline:3px solid #6a3cd9; outline-offset:1px; border-color:var(--indigo); }
              button { width:100%; margin-top:20px; padding:12px; font-size:15px; font-weight:600; color:#fff; background:var(--indigo); border:none; border-radius:999px; cursor:pointer; }
              button:hover { background:var(--indigo-deep); }
              .err { background:#fbe9f3; color:#98146d; border-radius:8px; padding:9px 12px; font-size:13.5px; margin:0 0 6px; }
              a { color:var(--magenta); font-size:13px; }
            </style>
            </head>
            <body>
              <form class="card" method="post" action="/admin/login">
                <h1>Sign in</h1>
                <p class="sub">Administrator access for the Robinson Huron Treaty hub.</p>
                {$err}
                <input type="hidden" name="_csrf_token" value="{$e($token)}">
                <input type="hidden" name="next" value="{$e($next)}">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" autofocus required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="submit">Sign in</button>
                <p style="margin:18px 0 0"><a href="/">Back to the public site</a></p>
              </form>
            </body>
            </html>
            HTML;

        return new Response($html, $error ? 401 : 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function forbiddenPage(): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">
            <title>Not authorized</title></head>
            <body style="font-family:system-ui,sans-serif;max-width:32rem;margin:12vh auto;padding:0 1.5rem;color:#221d33">
            <h1 style="color:#38217f">Not authorized</h1>
            <p>You are signed in, but this account does not have administrator access.</p>
            <p><a href="/admin/logout">Sign out</a> &middot; <a href="/">Back to the public site</a></p>
            </body></html>
            HTML;
    }
}
