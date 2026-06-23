<?php

declare(strict_types=1);

namespace App\Admin;

use Anokii\Access\AdminRoles;
use Anokii\Admin\AdminData;
use Anokii\Admin\AdminModules;
use Anokii\Admin\AdminShell;
use Anokii\Dashboard\DashboardGate;
use App\Analytics\AnalyticsReport;
use App\Support\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Presentation for rhtcircle's Anokii admin, rendered through the shared Anokii
 * package shell. The login flow, role model, and account command now come from
 * the package (Anokii\Dashboard\AdminLoginController, Anokii\Access\AdminRoles,
 * Anokii\Admin\CreateAdminHandler); this controller is just the rhtcircle-specific
 * dashboard pages, gated by the package DashboardGate::requirePermission().
 *
 * Auth: the dashboard routes are registered allowAll() at the framework layer and
 * this controller enforces the session + admin permission via the inherited
 * requirePermission(): anonymous -> /admin/login; signed-in without the permission
 * -> 403; admin -> the shell. (The single admin account holds the framework
 * administrator role, which short-circuits the permission check.)
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

    // --- helpers -----------------------------------------------------------

    /**
     * Gate a dashboard page on the shared admin permission via the package
     * DashboardGate: null when the request may proceed, a redirect to /admin/login
     * when anonymous, a 403 when signed in without the permission.
     */
    private function guard(Request $request): ?Response
    {
        return $this->requirePermission($request, AdminRoles::DEFAULT_PERMISSION);
    }

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
}
