<?php

namespace App\Http\Controllers;

use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\VpnDomainAudit;
use App\Models\VpnDomainProbeJob;
use App\Services\VpnAgent\VpnAgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminVpnDomainController extends Controller
{
    private function ensureFeatureEnabled(): void
    {
        abort_unless((bool) config('support.vpn_domains_enabled'), 404);
    }

    public function index(Request $request): View
    {
        $this->ensureFeatureEnabled();

        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->orderBy('id')
            ->get();

        $activeServerId = (int) $request->query('server_id', $servers->first()?->id ?? 0);
        if ($activeServerId <= 0 && $servers->isNotEmpty()) {
            $activeServerId = (int) $servers->first()->id;
        }

        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', 'domains');
        if (!in_array($view, ['domains', 'base'], true)) {
            $view = 'domains';
        }
        $sort = (string) $request->query('sort', 'default');
        $baseFilter = trim((string) $request->query('base', ''));

        if ($view === 'base') {
            $query = VpnDomainAudit::query()
                ->selectRaw('COALESCE(vpn_domain_audits.base_domain, vpn_domain_audits.domain) as base_domain')
                ->selectRaw('COUNT(*) as domains_count')
                ->selectRaw('SUM(count) as total_count')
                ->selectRaw('MAX(last_seen_at) as last_seen_at')
                ->selectRaw('SUM(CASE WHEN allow_vpn = 1 THEN 1 ELSE 0 END) as allow_count')
                ->selectRaw('MAX(bp.status) as probe_status')
                ->selectRaw('MAX(bp.latency_ms) as probe_latency_ms')
                ->selectRaw('MAX(bp.http_code) as probe_http_code')
                ->selectRaw('MAX(bp.checked_at) as probe_checked_at')
                ->selectRaw('MAX(bp.fail_streak) as probe_fail_streak')
                ->when($activeServerId > 0, fn ($q) => $q->where('vpn_domain_audits.server_id', $activeServerId));

            $query->leftJoin('vpn_domain_base_probes as bp', function ($join) use ($activeServerId) {
                $join->on('bp.base_domain', '=', DB::raw('COALESCE(vpn_domain_audits.base_domain, vpn_domain_audits.domain)'));
                if ($activeServerId > 0) {
                    $join->where('bp.server_id', '=', $activeServerId);
                }
            });

            if ($search !== '') {
                $query->whereRaw('COALESCE(vpn_domain_audits.base_domain, vpn_domain_audits.domain) like ?', ['%' . $search . '%']);
            }

            $query = $query->groupBy('base_domain');

            if ($sort === 'probe') {
                $query->orderByRaw("
                    CASE
                        WHEN MAX(bp.status) IS NULL THEN 4
                        WHEN MAX(bp.status) = 'unreachable' AND MAX(bp.fail_streak) >= 3 THEN 0
                        WHEN MAX(bp.status) = 'unreachable' THEN 1
                        WHEN MAX(bp.status) = 'reachable_slow' THEN 2
                        WHEN MAX(bp.status) = 'reachable_fast' THEN 3
                        ELSE 4
                    END ASC
                ");
                $query->orderByDesc('total_count');
            } else {
                $query->orderByDesc('total_count')->orderBy('base_domain');
            }

            $domains = $query->paginate(100)->withQueryString();
        } else {
            $query = VpnDomainAudit::query()
                ->with('server')
                ->when($activeServerId > 0, fn ($q) => $q->where('server_id', $activeServerId));

            if ($status === 'allow') {
                $query->where('allow_vpn', true);
            } elseif ($status === 'pending') {
                $query->where('allow_vpn', false);
            }

            if ($baseFilter !== '') {
                $query->where('base_domain', $baseFilter);
            }

            if ($search !== '') {
                $query->where('domain', 'like', '%' . $search . '%');
            }

            $domains = $query
                ->orderByDesc('count')
                ->orderBy('domain')
                ->paginate(100)
                ->withQueryString();
        }

        $mode = ProjectSetting::getValue('xray_allowlist_mode', 'full');

        return view('admin.vpn-domains', [
            'domains' => $domains,
            'servers' => $servers,
            'activeServerId' => $activeServerId,
            'statusFilter' => $status,
            'search' => $search,
            'viewMode' => $view,
            'sort' => $sort,
            'baseFilter' => $baseFilter,
            'mode' => $mode,
            'applyResults' => session('applyResults', []),
            'status' => session('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
            'domain_ids' => ['array'],
            'domain_ids.*' => ['integer', 'exists:vpn_domain_audits,id'],
            'allow' => ['array'],
            'allow.*' => ['integer'],
            'base_domains' => ['array'],
            'base_domains.*' => ['string'],
            'allow_base' => ['array'],
            'allow_base.*' => ['string'],
        ]);

        $baseDomains = array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $data['base_domains'] ?? []
        ))));
        if (!empty($baseDomains)) {
            $allowBases = array_values(array_unique(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $data['allow_base'] ?? []
            ))));
            $allowBases = array_values(array_intersect($allowBases, $baseDomains));
            $denyBases = array_values(array_diff($baseDomains, $allowBases));

            $serverId = (int) ($data['server_id'] ?? 0);

            $apply = function (array $bases, bool $allow) use ($serverId): void {
                if (empty($bases)) {
                    return;
                }

                $q = VpnDomainAudit::query();
                if ($serverId > 0) {
                    $q->where('server_id', $serverId);
                }

                $q->where(function ($query) use ($bases) {
                    $query->whereIn('base_domain', $bases)
                        ->orWhere(function ($sub) use ($bases) {
                            $sub->whereNull('base_domain')
                                ->whereIn('domain', $bases);
                        });
                })->update(['allow_vpn' => $allow]);
            };

            $apply($allowBases, true);
            $apply($denyBases, false);

            return back()->with('status', 'saved');
        }

        $domainIds = array_values(array_unique(array_map('intval', $data['domain_ids'] ?? [])));
        if (empty($domainIds)) {
            return back()->with('status', 'empty');
        }

        $allowIds = array_values(array_unique(array_map('intval', $data['allow'] ?? [])));
        $allowIds = array_values(array_intersect($allowIds, $domainIds));
        $denyIds = array_values(array_diff($domainIds, $allowIds));

        $serverId = (int) ($data['server_id'] ?? 0);

        if (!empty($allowIds)) {
            $q = VpnDomainAudit::query()->whereIn('id', $allowIds);
            if ($serverId > 0) {
                $q->where('server_id', $serverId);
            }
            $q->update(['allow_vpn' => true]);
        }

        if (!empty($denyIds)) {
            $q = VpnDomainAudit::query()->whereIn('id', $denyIds);
            if ($serverId > 0) {
                $q->where('server_id', $serverId);
            }
            $q->update(['allow_vpn' => false]);
        }

        return back()->with('status', 'saved');
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
        ]);

        $serverId = (int) ($data['server_id'] ?? 0);

        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->when($serverId > 0, fn ($q) => $q->where('id', $serverId))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($servers as $server) {
            $label = $server->ip1 ?: ($server->node1_api_url ?: ('server-' . $server->id));
            $entry = [
                'server_id' => $server->id,
                'label' => $label,
                'ok' => false,
                'error' => null,
            ];

            $configured = !empty($server->node1_api_url)
                && !empty($server->node1_api_ca_path)
                && !empty($server->node1_api_cert_path)
                && !empty($server->node1_api_key_path);

            if (!$configured) {
                $entry['error'] = 'node1_api_misconfigured';
                $results[] = $entry;
                continue;
            }

            $domains = VpnDomainAudit::query()
                ->where('server_id', $server->id)
                ->where('allow_vpn', true)
                ->orderBy('domain')
                ->pluck('domain')
                ->all();

            try {
                $client = new VpnAgentClient($server, 15);
                $res = $client->updateXrayAllowDomains($domains);
                $entry['ok'] = (bool) ($res['ok'] ?? false);
                if (!$entry['ok']) {
                    $entry['error'] = (string) ($res['error'] ?? 'unknown_error');
                }
            } catch (\Throwable $e) {
                $entry['ok'] = false;
                $entry['error'] = $e->getMessage();
            }

            $results[] = $entry;
        }

        return back()->with('status', 'synced')->with('applyResults', $results);
    }

    public function setMode(Request $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
            'mode' => ['required', 'string', 'in:full,allow'],
        ]);

        $serverId = (int) ($data['server_id'] ?? 0);
        $mode = (string) $data['mode'];

        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->when($serverId > 0, fn ($q) => $q->where('id', $serverId))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($servers as $server) {
            $label = $server->ip1 ?: ($server->node1_api_url ?: ('server-' . $server->id));
            $entry = [
                'server_id' => $server->id,
                'label' => $label,
                'ok' => false,
                'error' => null,
            ];

            $configured = !empty($server->node1_api_url)
                && !empty($server->node1_api_ca_path)
                && !empty($server->node1_api_cert_path)
                && !empty($server->node1_api_key_path);

            if (!$configured) {
                $entry['error'] = 'node1_api_misconfigured';
                $results[] = $entry;
                continue;
            }

            try {
                $client = new VpnAgentClient($server, 10);
                $res = $client->setXrayMode($mode);
                $entry['ok'] = (bool) ($res['ok'] ?? false);
                if (!$entry['ok']) {
                    $entry['error'] = (string) ($res['error'] ?? 'unknown_error');
                }
            } catch (\Throwable $e) {
                $entry['ok'] = false;
                $entry['error'] = $e->getMessage();
            }

            $results[] = $entry;
        }

        ProjectSetting::setValue('xray_allowlist_mode', $mode, auth()->id() ? (int) auth()->id() : null);

        return back()->with('status', 'mode-updated')->with('applyResults', $results);
    }

    public function collect(Request $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
        ]);

        $serverId = (int) ($data['server_id'] ?? 0);
        $args = ['--once' => true];
        if ($serverId > 0) {
            $args['--server-id'] = $serverId;
        }

        $exitCode = Artisan::call('vpn:domain-audit-collect', $args);
        $output = trim((string) Artisan::output());

        return back()
            ->with('status', 'collected')
            ->with('collectOk', $exitCode === 0)
            ->with('collectOutput', $output);
    }

    public function probe(Request $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        $data = $request->validate([
            'server_id' => ['nullable', 'integer'],
        ]);

        $serverId = (int) ($data['server_id'] ?? 0);
        if ($serverId <= 0) {
            return back()->with('status', 'probe-queued')->with('probeOutput', 'server_id missing');
        }

        VpnDomainProbeJob::create([
            'server_id' => $serverId,
            'limit' => 60,
            'days' => 30,
            'fresh_hours' => 24,
            'status' => 'pending',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        return back()
            ->with('status', 'probe-queued')
            ->with('probeOutput', 'Задача поставлена в очередь. Результаты появятся после фоновой обработки.');
    }
}
