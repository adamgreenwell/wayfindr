<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\SpreadsheetSafeCsv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Whether support is working, and who is carrying it.
 *
 * Admin-only and account-scoped, following the account audit page it reads
 * from: the same inline admin guard, the same site allowlist including archived
 * sites, and the same rule that a site id in the query string can only narrow
 * the answer (ADR 0015).
 */
class AgentReportController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();

        [$scope, $window, $report] = $this->report($request, $account, $agent);

        $volume = $report->volume();
        $firstResponse = $report->firstResponse();
        $resolution = $report->resolution();

        return view('agent.reports.index', [
            'agent' => $agent,
            'account' => $account,
            'window' => $window,
            'windowChoices' => ReportingWindow::CHOICES,
            'sites' => $scope->sites,
            'siteId' => $scope->requestedSiteId,
            'volume' => $volume,
            'chart' => $this->chart($volume, $window),
            'firstResponse' => $firstResponse,
            'resolution' => $resolution,
            'agentActivity' => $report->agentActivity(),
            'queueHealth' => $report->queueHealth(),
            'historyBeganAt' => $report->historyBeganAt(),
            'historyIsPartial' => $report->historyIsPartial(),
            'reportQuery' => $this->reportQueryParams($window, $scope->requestedSiteId),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();

        [, $window, $report] = $this->report($request, $account, $agent);

        $section = $request->query('report_export');
        $section = $section === 'agents' ? 'agents' : 'daily';

        [$header, $rows] = $section === 'agents'
            ? $this->agentExport($report)
            : $this->dailyExport($report, $window);

        return response()->streamDownload(function () use ($header, $rows): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, $header);

            foreach ($rows as $row) {
                fputcsv($stream, SpreadsheetSafeCsv::row($row));
            }

            fclose($stream);
        }, 'wayfindr-support-report-'.$section.'-'.CarbonImmutable::now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: ReportingScope, 1: ReportingWindow, 2: SupportReport}
     */
    private function report(Request $request, Account $account, User $agent): array
    {
        $scope = ReportingScope::for($account, $agent, $request->query('report_site'));
        $window = ReportingWindow::fromRequestValue($request->query('report_days'));

        return [$scope, $window, new SupportReport($scope, $window)];
    }

    private function accountAdmin(Request $request): User
    {
        $agent = $request->user();

        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        return $agent;
    }

    /**
     * The daily series, shaped for the bars the page draws.
     *
     * The tallest column in the range sets the scale rather than a fixed
     * ceiling, so a quiet week is still readable instead of a flat line at the
     * bottom of the chart.
     *
     * @param  array{opened: array<string, int>, closed: array<string, int>, opened_total: int, closed_total: int, open_now: int}  $volume
     * @return array{max: int, days: list<array{key: string, label: string, opened: int, closed: int}>}
     */
    private function chart(array $volume, ReportingWindow $window): array
    {
        $days = [];

        foreach ($window->days() as $day) {
            $key = $window->bucketKey($day);

            $days[] = [
                'key' => $key,
                'label' => $day->format('j M'),
                'opened' => $volume['opened'][$key] ?? 0,
                'closed' => $volume['closed'][$key] ?? 0,
            ];
        }

        $max = 0;

        foreach ($days as $day) {
            $max = max($max, $day['opened'], $day['closed']);
        }

        return ['max' => $max, 'days' => $days];
    }

    /**
     * @return array{0: list<string>, 1: list<array<int, string>>}
     */
    private function dailyExport(SupportReport $report, ReportingWindow $window): array
    {
        $volume = $report->volume();
        $rows = [];

        foreach ($window->days() as $day) {
            $key = $window->bucketKey($day);

            $rows[] = [
                $key,
                (string) ($volume['opened'][$key] ?? 0),
                (string) ($volume['closed'][$key] ?? 0),
            ];
        }

        return [['date', 'conversations_opened', 'conversations_closed'], $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<array<int, string>>}
     */
    private function agentExport(SupportReport $report): array
    {
        $rows = [];

        foreach ($report->agentActivity() as $row) {
            $rows[] = [
                $row['name'],
                $row['agent']?->email ?? '',
                (string) $row['replies'],
                (string) $row['closes'],
            ];
        }

        return [['agent', 'email', 'replies_sent', 'conversations_closed'], $rows];
    }

    /**
     * @return array<string, string>
     */
    private function reportQueryParams(ReportingWindow $window, ?int $siteId): array
    {
        $params = ['report_days' => (string) $window->days];

        if ($siteId !== null) {
            $params['report_site'] = (string) $siteId;
        }

        return $params;
    }
}
