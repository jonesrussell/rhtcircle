<?php

declare(strict_types=1);

namespace App\Admin;

use Anokii\Admin\AdminData;
use Anokii\Admin\AdminModules;
use Anokii\Admin\AdminShell;
use Anokii\Dashboard\DashboardGate;
use Anokii\Support\Auth;
use App\Analytics\AnalyticsReport;
use App\Support\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Middleware\CsrfMiddleware;

/**
 * Framework-auth gate + presentation for rhtcircle's Anokii admin, rendered
 * through the shared Anokii package shell (anokii/_shell.html.twig + the
 * anokii/admin/* templates and Anokii\Admin\AdminModules / AdminShell), so the
 * workspace looks like the same product as every other Anokii install. rhtcircle
 * supplies only the live/preview split (the shared-graph tier), its brand, and
 * its theme stylesheet.
 *
 * Auth: the dashboard routes are registered allowAll() at the framework layer and
 * this controller enforces the session itself, reusing the package's DashboardGate
 * (redirect helpers), Support\Auth (session), and the ACCESS_ADMIN permission
 * ({@see AdminRoles}). Anonymous page request -> /admin/login; signed-in without
 * the permission -> 403; admin -> the shell.
 *
 * Co-Intelligence (graph counts + the no-PII question log) and Analytics are the
 * live modules for this public tier; the internal-workspace modules render as
 * disabled product-preview cards via the package coming-soon page.
 */
final class AdminController extends DashboardGate
{
    /** rhtcircle brand + theme passed to the shared shell. */
    private const BRAND_TITLE = 'Robinson Huron Treaty';
    private const BRAND_TAG = 'Anokii admin';
    private const THEME_HREF = '/css/anokii-rht.css';
    private const HOME_PATH = '/admin/anokii';

    public function __construct(
        ?EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $db,
        private readonly AnalyticsReport $report,
    ) {
        parent::__construct($entityTypeManager);
    }

    protected function loginPath(): string
    {
        return '/admin/login';
    }

    // --- shell pages (live modules) ----------------------------------------

    /** Dashboard home: the "Aanii" hero and the module grid. */
    public function home(Request $request): Response
    {
        $gate = $this->guard($request);
        if ($gate !== null) {
            return $gate;
        }

        return $this->html(View::render('anokii/admin/home.html.twig', $this->shell('dashboard', [
            'page_title' => 'Anokii admin · Robinson Huron Treaty',
            'hero_lead' => 'The Robinson Huron Treaty resource hub workspace, running on Anokii. This public install holds only the shared, public graph: the 21 nations, the land and safety pages, and the corpus the chat answers from. No member data lives here.',
            'hero_chips' => ['Shared public graph', 'Co-Intelligence live', 'Member-led', 'More tools coming'],
        ])));
    }

    /** Co-Intelligence: graph counts + the no-PII recent-questions log. */
    public function cointelligence(Request $request): Response
    {
        $gate = $this->guard($request);
        if ($gate !== null) {
            return $gate;
        }
        $data = new AdminData($this->db);

        return $this->html(View::render('anokii/admin/cointelligence.html.twig', $this->shell('cointelligence', [
            'page_title' => 'Co-Intelligence · Anokii admin',
            'counts' => $data->graphCounts(),
            'log_rows' => $data->recentQuestions(200),
        ])));
    }

    /** Analytics: the first-party analytics dashboard, in the shared shell. */
    public function analytics(Request $request): Response
    {
        $gate = $this->guard($request);
        if ($gate !== null) {
            return $gate;
        }
        $today = gmdate('Y-m-d');
        $from = $this->cleanDate($request->query->get('from'), gmdate('Y-m-d', strtotime('-29 days')));
        $to = $this->cleanDate($request->query->get('to'), $today);

        return $this->html(View::render('admin/analytics.html.twig', $this->shell('analytics', [
            'page_title' => 'Analytics · Anokii admin',
            'report' => $this->report->summary($from, $to),
            'range' => ['from' => $from, 'to' => $to],
        ])));
    }

    /** Product-preview placeholder for a not-yet-live module. */
    public function comingSoon(Request $request, string $module): Response
    {
        $gate = $this->guard($request);
        if ($gate !== null) {
            return $gate;
        }
        $m = AdminModules::find($module);
        if ($m === null || in_array($module, ['dashboard', 'cointelligence', 'analytics'], true)) {
            return new RedirectResponse(self::HOME_PATH);
        }

        return $this->html(View::render('anokii/admin/coming_soon.html.twig', $this->shell($module, [
            'page_title' => $m['label'] . ' · Anokii admin',
            'module' => $m,
        ])));
    }

    // --- login / logout ----------------------------------------------------

    public function loginForm(Request $request): Response
    {
        $already = $this->redirectIfAuthenticated($this->safeNext($request));
        if ($already !== null) {
            return $already;
        }

        return $this->loginPage($this->safeNext($request), $request->query->get('error') !== null);
    }

    public function loginSubmit(Request $request): Response
    {
        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $next = $this->safeNext($request);

        $user = Auth::login($this->entityTypeManager, $email, $password);
        if ($user === null || !$user->hasPermission(AdminRoles::ACCESS_ADMIN)) {
            if ($user !== null) {
                Auth::logout();
            }

            return $this->loginPage($next, true);
        }

        CsrfMiddleware::regenerate();

        return new RedirectResponse($next);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        return new RedirectResponse($this->loginPath());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Build the shared shell context for a page: the resolved shared-graph module
     * set, rhtcircle brand + theme, and the page-specific extras merged on top.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function shell(string $active, array $extra = []): array
    {
        $user = $this->currentUser();

        return AdminShell::context(
            $user,
            $active,
            AdminModules::sharedGraph(),
            [
                'brand_title' => self::BRAND_TITLE,
                'brand_tag' => self::BRAND_TAG,
                'theme_href' => self::THEME_HREF,
                'home_path' => self::HOME_PATH,
                'logout_path' => '/admin/logout',
            ] + $extra,
            ['administrator' => 'Administrator', AdminRoles::ROLE_OPERATOR => 'Operator'],
        );
    }

    private function html(string $body, int $status = 200): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function cleanDate(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
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

        return self::HOME_PATH;
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
