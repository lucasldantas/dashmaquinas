<?php
include('../../../inc/includes.php');

Session::checkRight('computer', READ);

// ── Snapshot manual (POST) ─────────────────────────────────────────────────
$snapshot_alert = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'snapshot') {
    try {
        $eid_snap      = (int)($_SESSION['glpiactive_entity'] ?? 0);
        $n             = PluginCustomdashboardDashboard::takeSnapshot($eid_snap);
        $snapshot_alert = "ok:{$n} localidade(s) registradas para " . date('m/Y') . '.';
    } catch (\Throwable $e) {
        $snapshot_alert = 'err:' . $e->getMessage();
    }
}

$totals      = PluginCustomdashboardDashboard::getSummaryTotals();
$all_loc     = PluginCustomdashboardDashboard::getAllStatusesByLocation();
$contratos   = PluginCustomdashboardDashboard::getRentedMachinesByContract();
$history_raw = PluginCustomdashboardDashboard::getStockHistory();         // array plano — filtrado no JS

Html::header('Dashboard Máquinas', '', 'tools', 'PluginCustomdashboardDashboard');

// ── Status padrão visíveis na tabela (apenas esses ficam abertos por default) ──
$default_statuses = ['Estoque', 'Manutenção'];

// ── Todos os status reais vindos do banco ───────────────────────────────────
$all_statuses = $all_loc['all_statuses'];   // ordenados por volume total
$loc_data     = $all_loc['locations'];      // ['São Paulo' => ['Estoque'=>10, ...], ...]
$loc_colors   = PluginCustomdashboardDashboard::getLocationColors(array_keys($loc_data));

// ── Datasets do gráfico — um por status, visibilidade inicial = $default_statuses ──
$chart_loc_labels = json_encode(array_keys($loc_data));
$chart_all_datasets = [];
foreach ($all_statuses as $s) {
    $data = [];
    foreach ($loc_data as $loc => $statuses) {
        $data[] = $statuses[$s] ?? 0;
    }
    $hex     = cdStatusColor($s, 'hex');
    $visible = in_array($s, $default_statuses);
    $chart_all_datasets[] = [
        'label'           => $s,
        'data'            => $data,
        'backgroundColor' => $hex . 'b3',
        'borderColor'     => $hex,
        'borderWidth'     => 0,
        'borderRadius'    => 6,
        'hidden'          => !$visible,
    ];
}
$chart_all_datasets_json = json_encode($chart_all_datasets);

// (variáveis de gráfico já preparadas acima em $chart_loc_labels / $chart_all_datasets_json)

// ── Dados para o gráfico de máquinas alugadas ───────────────────────────────
// Coleta todos os status únicos em todos os contratos (preserva ordem de aparição)
$cg_statuses = [];
foreach ($contratos as $c) {
    foreach (array_keys($c['statuses']) as $s) {
        if (!in_array($s, $cg_statuses, true)) {
            $cg_statuses[] = $s;
        }
    }
}
// Ordena os status mais relevantes primeiro
$status_order = ['Em Uso', 'Estoque', 'Manutenção', 'Transporte para FOR',
                 'Recolher', 'Validar', 'Devolvido', 'Inativo', 'Onboard'];
usort($cg_statuses, function($a, $b) use ($status_order) {
    $ia = array_search($a, $status_order);
    $ib = array_search($b, $status_order);
    $ia = $ia === false ? 99 : $ia;
    $ib = $ib === false ? 99 : $ib;
    return $ia - $ib;
});

$cg_labels   = json_encode(array_values(array_map(fn($c) => $c['name'], $contratos)));
$cg_datasets = [];
foreach ($cg_statuses as $s) {
    $data = [];
    foreach ($contratos as $c) {
        $data[] = $c['statuses'][$s] ?? 0;
    }
    $hex = cdStatusColor($s, 'hex');
    $cg_datasets[] = [
        'label'           => $s,
        'data'            => $data,
        'backgroundColor' => $hex . 'cc',
        'borderColor'     => $hex,
        'borderWidth'     => 0,
        'borderRadius'    => 4,
    ];
}
$cg_datasets_json = json_encode($cg_datasets);
$cg_canvas_height = max(180, count($contratos) * 52);

// ── Helper: cor por status ──────────────────────────────────────────────────
function cdStatusColor(string $status, string $type = 'badge'): string {
    $s = mb_strtolower($status);
    // hex: para barras e swatches
    if ($type === 'hex') {
        if (str_contains($s, 'estoque'))          return '#4263eb';
        if (str_contains($s, 'manut'))            return '#f76707';
        if (str_contains($s, 'uso'))              return '#2fb344';
        if (str_contains($s, 'recolh'))           return '#7048e8';
        if (str_contains($s, 'transport'))        return '#1098ad';
        if (str_contains($s, 'inat'))             return '#868e96';
        if (str_contains($s, 'valid'))            return '#f59f00';
        if (str_contains($s, 'devolv'))           return '#d6336c';
        if (str_contains($s, 'onboard'))          return '#0ca678';
        return '#adb5bd';
    }
    // badge: fundo claro + texto colorido (mais legível)
    if ($type === 'badge') {
        if (str_contains($s, 'estoque'))          return 'bg-blue-lt text-blue';
        if (str_contains($s, 'manut'))            return 'bg-orange-lt text-orange';
        if (str_contains($s, 'uso'))              return 'bg-green-lt text-green';
        if (str_contains($s, 'recolh'))           return 'bg-purple-lt text-purple';
        if (str_contains($s, 'transport'))        return 'bg-cyan-lt text-cyan';
        if (str_contains($s, 'inat'))             return 'bg-secondary-lt text-secondary';
        if (str_contains($s, 'valid'))            return 'bg-yellow-lt text-yellow';
        if (str_contains($s, 'devolv'))           return 'bg-pink-lt text-pink';
        if (str_contains($s, 'onboard'))          return 'bg-teal-lt text-teal';
        return 'bg-secondary-lt text-secondary';
    }
    return '#adb5bd';
}
?>

<link rel="stylesheet" href="<?php echo Plugin::getWebDir('customdashboard'); ?>/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="container-fluid cd-dashboard p-4">

    <!-- Feedback do snapshot -->
    <?php if ($snapshot_alert !== ''):
        $is_ok  = str_starts_with($snapshot_alert, 'ok:');
        $msg    = substr($snapshot_alert, 3);
    ?>
    <div class="alert alert-<?php echo $is_ok ? 'success' : 'danger'; ?> alert-dismissible mb-3">
        <i class="ti ti-<?php echo $is_ok ? 'circle-check' : 'alert-circle'; ?> me-2"></i>
        <?php echo $is_ok
            ? 'Snapshot registrado: ' . htmlspecialchars($msg)
            : 'Erro ao registrar snapshot: ' . htmlspecialchars($msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center mb-4">
        <i class="ti ti-layout-dashboard fs-2 me-3 text-primary"></i>
        <h2 class="mb-0 fw-bold">Dashboard de Máquinas</h2>
        <span class="ms-auto text-muted small">
            <i class="ti ti-refresh me-1"></i><?php echo date('d/m/Y H:i'); ?>
        </span>
    </div>

    <!-- ── Cartões de resumo ─────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card cd-card cd-card-estoque">
                <div class="card-body d-flex align-items-center py-3">
                    <div class="cd-card-icon bg-blue-lt text-blue"><i class="ti ti-package"></i></div>
                    <div class="ms-3">
                        <div class="cd-card-value"><?php echo $totals['estoque']; ?></div>
                        <div class="cd-card-label">Total em Estoque</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card cd-card cd-card-manut">
                <div class="card-body d-flex align-items-center py-3">
                    <div class="cd-card-icon bg-orange-lt text-orange"><i class="ti ti-tools"></i></div>
                    <div class="ms-3">
                        <div class="cd-card-value"><?php echo $totals['manutencao']; ?></div>
                        <div class="cd-card-label">Total em Manutenção</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card cd-card cd-card-alugadas">
                <div class="card-body d-flex align-items-center py-3">
                    <div class="cd-card-icon bg-green-lt text-green"><i class="ti ti-file-dollar"></i></div>
                    <div class="ms-3">
                        <div class="cd-card-value"><?php echo $totals['alugadas']; ?></div>
                        <div class="cd-card-label">Total Alugadas</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Status por Localidade (tabela + gráfico combinado) ───────────── -->
    <div class="row g-3 mb-4">

        <!-- Tabela de status por localidade (filtrável) -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <h3 class="card-title mb-0">
                        <i class="ti ti-map-pin me-2 text-primary"></i>Status por Localidade
                    </h3>
                    <!-- Botão de filtro de colunas -->
                    <?php if (count($all_statuses) > count($default_statuses)): ?>
                    <div class="ms-auto dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                type="button" id="cdStatusFilterBtn"
                                data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                aria-expanded="false">
                            <i class="ti ti-filter me-1"></i>Colunas
                        </button>
                        <div class="dropdown-menu p-2 cd-filter-menu"
                             aria-labelledby="cdStatusFilterBtn">
                            <div class="small fw-bold text-muted mb-2 px-1">Exibir status:</div>
                            <?php foreach ($all_statuses as $s):
                                $checked  = in_array($s, $default_statuses);
                                $hex      = cdStatusColor($s, 'hex');
                                $safe_s   = htmlspecialchars($s);
                                $safe_id  = 'cdcol_' . md5($s);
                            ?>
                            <div class="form-check d-flex align-items-center gap-2 mb-1">
                                <input class="form-check-input cd-col-toggle"
                                       type="checkbox" id="<?php echo $safe_id; ?>"
                                       data-status="<?php echo $safe_s; ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>>
                                <label class="form-check-label d-flex align-items-center gap-1"
                                       for="<?php echo $safe_id; ?>">
                                    <span class="cd-col-dot"
                                          style="background:<?php echo $hex; ?>"></span>
                                    <?php echo $safe_s; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($loc_data)): ?>
                        <p class="p-3 text-muted mb-0">Nenhum dado encontrado.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover cd-table-status mb-0"
                               id="cdStatusTable">
                            <thead>
                                <tr>
                                    <th>Localidade</th>
                                    <?php foreach ($all_statuses as $s):
                                        $hex     = cdStatusColor($s, 'hex');
                                        $visible = in_array($s, $default_statuses);
                                    ?>
                                    <th class="text-end cd-status-col"
                                        data-status-col="<?php echo htmlspecialchars($s); ?>"
                                        style="<?php echo $visible ? '' : 'display:none'; ?>">
                                        <span class="cd-col-header">
                                            <span class="cd-col-dot"
                                                  style="background:<?php echo $hex; ?>"></span>
                                            <?php echo htmlspecialchars($s); ?>
                                        </span>
                                    </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($loc_data as $loc => $statuses): ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($loc); ?></td>
                                    <?php foreach ($all_statuses as $s):
                                        $v       = $statuses[$s] ?? 0;
                                        $visible = in_array($s, $default_statuses);
                                        $badge   = cdStatusColor($s, 'badge');
                                    ?>
                                    <td class="text-end cd-status-col"
                                        data-status-col="<?php echo htmlspecialchars($s); ?>"
                                        style="<?php echo $visible ? '' : 'display:none'; ?>">
                                        <?php if ($v > 0): ?>
                                            <span class="badge <?php echo $badge; ?> fw-bold">
                                                <?php echo $v; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gráfico combinado -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0">
                        <i class="ti ti-chart-bar me-2 text-primary"></i>Status por Localidade
                    </h3>
                    <span class="ms-auto text-muted small">
                        <i class="ti ti-refresh me-1"></i>Sincronizado com os filtros
                    </span>
                </div>
                <div class="card-body d-flex align-items-center">
                    <?php if (empty($loc_data)): ?>
                        <p class="text-muted mb-0">Nenhum dado para exibir.</p>
                    <?php else: ?>
                        <canvas id="chartCombinado" style="width:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Histórico de Estoque ─────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center flex-wrap gap-2">
            <h3 class="card-title mb-0">
                <i class="ti ti-trending-up me-2 text-blue"></i>Histórico de Estoque por Localidade
            </h3>
            <!-- Filtros de período (visíveis apenas se houver dados) -->
            <?php if (!empty($history_raw)): ?>
            <div class="btn-group btn-group-sm ms-2" id="cdHistFilter" role="group"
                 aria-label="Período do histórico">
                <button type="button" class="btn btn-outline-primary" data-period="7d">7 dias</button>
                <button type="button" class="btn btn-outline-primary active" data-period="30d">30 dias</button>
                <button type="button" class="btn btn-outline-primary" data-period="monthly">Mensal</button>
            </div>
            <?php endif; ?>
            <div class="ms-auto">
                <form method="POST" class="d-inline">
                    <?php echo Html::hidden('_glpi_csrf_token',
                        ['value' => Session::getNewCSRFToken()]); ?>
                    <input type="hidden" name="action" value="snapshot">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="ti ti-camera me-1"></i>Registrar hoje
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($history_raw)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="ti ti-database-off" style="font-size:2.5rem;display:block;margin-bottom:.75rem;"></i>
                    <p class="mb-1 fw-medium">Nenhum dado histórico ainda.</p>
                    <p class="small mb-0">
                        Clique em <strong>Registrar hoje</strong> para criar o primeiro snapshot.<br>
                        A partir daí, o GLPI registra automaticamente todo dia via cron.
                    </p>
                </div>
            <?php else: ?>
                <canvas id="chartHistorico" style="width:100%;height:320px;"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Máquinas Alugadas por Contrato ───────────────────────────────── -->
    <div class="mb-3 d-flex align-items-center">
        <h4 class="mb-0 fw-bold">
            <i class="ti ti-file-dollar me-2 text-green"></i>Máquinas Alugadas por Contrato
        </h4>
        <span class="ms-3 badge bg-green-lt text-green"><?php echo count($contratos); ?> contratos</span>
    </div>

    <!-- Gráfico de visão geral dos contratos -->
    <?php if (!empty($contratos)): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0">
                <i class="ti ti-chart-bar me-2 text-green"></i>Distribuição por Contrato e Status
            </h3>
        </div>
        <div class="card-body">
            <canvas id="chartContratos"
                    style="width:100%; height:<?php echo $cg_canvas_height; ?>px;"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($contratos)): ?>
        <div class="card"><div class="card-body text-muted">Nenhuma máquina vinculada a contratos.</div></div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($contratos as $contrato):
            $all_statuses = array_keys($contrato['statuses']);
            $total = $contrato['total'];
        ?>
            <div class="col-12">
                <div class="card cd-contract-card">

                    <!-- Cabeçalho do contrato -->
                    <div class="card-header cd-contract-header">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <i class="ti ti-file-text fs-4 text-primary"></i>
                            <span class="fw-bold fs-5"><?php echo htmlspecialchars($contrato['name']); ?></span>
                            <span class="badge bg-blue-lt text-blue ms-1 fw-bold"><?php echo $total; ?> máquinas</span>
                        </div>

                        <!-- Barra de progresso empilhada -->
                        <div class="cd-stacked-bar mt-3">
                            <?php foreach ($contrato['statuses'] as $status => $qty):
                                $pct   = $total > 0 ? round($qty / $total * 100) : 0;
                                $color = cdStatusColor($status, 'hex');
                            ?>
                                <div class="cd-stacked-segment"
                                     style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>;"
                                     title="<?php echo htmlspecialchars($status); ?>: <?php echo $qty; ?> (<?php echo $pct; ?>%)">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Legenda dos status -->
                        <div class="d-flex flex-wrap gap-3 mt-2">
                            <?php foreach ($contrato['statuses'] as $status => $qty):
                                $pct   = $total > 0 ? round($qty / $total * 100) : 0;
                                $color = cdStatusColor($status, 'hex');
                                $badge = cdStatusColor($status, 'badge');
                            ?>
                                <div class="cd-legend-item">
                                    <span class="cd-legend-swatch" style="background:<?php echo $color; ?>;"></span>
                                    <span class="fw-medium"><?php echo htmlspecialchars($status); ?></span>
                                    <span class="badge <?php echo $badge; ?> ms-1"><?php echo $qty; ?></span>
                                    <span class="text-muted small"><?php echo $pct; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tabela por localidade -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover cd-contract-table mb-0">
                            <thead>
                                <tr>
                                    <th><i class="ti ti-map-pin me-1"></i>Localidade</th>
                                    <th class="text-center">Total</th>
                                    <?php foreach ($all_statuses as $s):
                                        $hex = cdStatusColor($s, 'hex'); ?>
                                        <th class="text-center">
                                            <span class="cd-col-header">
                                                <span class="cd-col-dot" style="background:<?php echo $hex; ?>"></span>
                                                <?php echo htmlspecialchars($s); ?>
                                            </span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="cd-col-bar">Distribuição</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($contrato['locations'] as $loc => $loc_data):
                                $loc_total = $loc_data['total'];
                            ?>
                                <tr>
                                    <td class="fw-medium"><?php echo htmlspecialchars($loc); ?></td>
                                    <td class="text-center fw-bold"><?php echo $loc_total; ?></td>
                                    <?php foreach ($all_statuses as $s): ?>
                                        <td class="text-center">
                                            <?php $v = $loc_data['statuses'][$s] ?? 0; ?>
                                            <?php echo $v > 0 ? $v : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="cd-col-bar">
                                        <div class="cd-mini-bar">
                                        <?php foreach ($all_statuses as $s):
                                            $v   = $loc_data['statuses'][$s] ?? 0;
                                            $pct = $loc_total > 0 ? round($v / $loc_total * 100) : 0;
                                            if ($pct === 0) continue;
                                        ?>
                                            <div style="width:<?php echo $pct; ?>%;background:<?php echo cdStatusColor($s,'hex'); ?>;"
                                                 title="<?php echo htmlspecialchars($s); ?>: <?php echo $v; ?>"></div>
                                        <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /.cd-dashboard -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Toggle de colunas da tabela + datasets do gráfico ────────────────
    document.querySelectorAll('.cd-col-toggle').forEach(function (chk) {
        chk.addEventListener('change', function () {
            const status = this.dataset.status;
            const show   = this.checked;

            // 1. Coluna da tabela
            document.querySelectorAll(
                `#cdStatusTable [data-status-col="${CSS.escape(status)}"]`
            ).forEach(function (el) {
                el.style.display = show ? '' : 'none';
            });

            // 2. Dataset do gráfico
            if (window.cdStatusChart) {
                const idx = window.cdStatusChart.data.datasets
                    .findIndex(d => d.label === status);
                if (idx !== -1) {
                    window.cdStatusChart.data.datasets[idx].hidden = !show;
                    window.cdStatusChart.update();
                }
            }
        });
    });

    // ── Gráfico Status por Localidade (sincronizado com os filtros) ──────
    const cdChartLabels   = <?php echo $chart_loc_labels; ?>;
    const cdChartDatasets = <?php echo $chart_all_datasets_json; ?>;

    const ctxCombinado = document.getElementById('chartCombinado');
    if (ctxCombinado && cdChartLabels.length) {
        window.cdStatusChart = new Chart(ctxCombinado, {
            type: 'bar',
            data: { labels: cdChartLabels, datasets: cdChartDatasets },
            options: {
                responsive:          true,
                maintainAspectRatio: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display:  true,
                        position: 'bottom',
                        labels: {
                            boxWidth:        12,
                            boxHeight:       12,
                            borderRadius:    3,
                            useBorderRadius: true,
                            font:    { size: 11 },
                            padding: 14,
                            // Exibe só os datasets visíveis na legenda
                            filter: (item, data) =>
                                !data.datasets[item.datasetIndex].hidden,
                        }
                    },
                    tooltip: {
                        filter: (item) => item.raw > 0,
                        callbacks: {
                            footer: (items) => {
                                const total = items.reduce((s, i) => s + i.parsed.y, 0);
                                return 'Total: ' + total;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, stepSize: 1 },
                        grid:  { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // ── Gráfico Histórico de Estoque (linhas por localidade, filtro JS) ──
    const ctxHistorico = document.getElementById('chartHistorico');
    const historyRaw   = <?php echo json_encode($history_raw); ?>;
    const locColors    = <?php echo json_encode($loc_colors); ?>;

    let cdHistChart  = null;
    let cdHistPeriod = '30d';

    // Formata 'YYYY-MM-DD' → 'DD/MM'
    function cdFmtDate(s) {
        const p = s.split('-');
        return `${p[2]}/${p[1]}`;
    }
    // Formata 'YYYY-MM' → 'Jan/25' etc.
    const cdMonthNames = ['Jan','Fev','Mar','Abr','Mai','Jun',
                          'Jul','Ago','Set','Out','Nov','Dez'];
    function cdFmtMonth(ym) {
        const p = ym.split('-');
        return `${cdMonthNames[parseInt(p[1], 10) - 1]}/${p[0].slice(2)}`;
    }

    const cdPalette = [
        '#4263eb','#2fb344','#f76707','#7048e8','#1098ad',
        '#f59f00','#d6336c','#0ca678','#ea580c','#7c3aed'
    ];

    function cdBuildHistory(period) {
        let locMap    = {};   // location → label → count
        let rawLabels = [];

        if (period === '7d' || period === '30d') {
            const days   = period === '7d' ? 7 : 30;
            const cutoff = new Date();
            cutoff.setDate(cutoff.getDate() - days + 1);
            const cutoffStr = cutoff.toISOString().slice(0, 10);

            const filtered = historyRaw.filter(r => r.date >= cutoffStr);
            rawLabels = [...new Set(filtered.map(r => r.date))].sort();

            filtered.forEach(r => {
                if (!locMap[r.location]) locMap[r.location] = {};
                locMap[r.location][r.date] = r.count;
            });
        } else {
            // Mensal: agrega por YYYY-MM, último valor de cada mês por localidade
            const monthSet = new Set();
            historyRaw.forEach(r => {
                const ym = r.date.slice(0, 7);
                monthSet.add(ym);
                if (!locMap[r.location]) locMap[r.location] = {};
                // dados chegam ordenados por data ASC → o último sobrescreve (mais recente)
                locMap[r.location][ym] = r.count;
            });
            rawLabels = [...monthSet].sort().slice(-12);
        }

        if (!rawLabels.length) return { labels: [], datasets: [] };

        const dispLabels = period === 'monthly'
            ? rawLabels.map(cdFmtMonth)
            : rawLabels.map(cdFmtDate);

        // Ordena localidades pelo total acumulado no período (desc)
        const locations = Object.keys(locMap).sort((a, b) => {
            const ta = rawLabels.reduce((s, l) => s + (locMap[a][l] ?? 0), 0);
            const tb = rawLabels.reduce((s, l) => s + (locMap[b][l] ?? 0), 0);
            return tb - ta;
        });

        const datasets = locations.map((loc, i) => {
            const color = locColors[loc] || cdPalette[i % cdPalette.length];
            return {
                label:            loc,
                data:             rawLabels.map(l => locMap[loc][l] ?? null),
                borderColor:      color,
                backgroundColor:  color + '22',
                tension:          0.35,
                fill:             false,
                pointRadius:      period === 'monthly' ? 5 : 3,
                pointHoverRadius: 7,
                spanGaps:         true,
            };
        });

        return { labels: dispLabels, datasets };
    }

    if (ctxHistorico && historyRaw.length) {
        const initial = cdBuildHistory(cdHistPeriod);
        ctxHistorico.style.height = '320px';

        cdHistChart = new Chart(ctxHistorico, {
            type: 'line',
            data: { labels: initial.labels, datasets: initial.datasets },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12, boxHeight: 12,
                            borderRadius: 3, useBorderRadius: true,
                            font: { size: 11 }, padding: 14,
                        }
                    },
                    tooltip: {
                        callbacks: {
                            footer: (items) => 'Total: ' +
                                items.reduce((s, i) => s + (i.raw ?? 0), 0)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks:  { precision: 0 },
                        grid:   { color: 'rgba(0,0,0,0.05)' },
                        title:  { display: true, text: 'Qtd. em Estoque',
                                  font: { size: 11 }, color: '#6b7280' }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });

        // Botões de filtro de período
        document.querySelectorAll('#cdHistFilter button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#cdHistFilter button')
                    .forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                cdHistPeriod = this.dataset.period;
                const built = cdBuildHistory(cdHistPeriod);
                cdHistChart.data.labels   = built.labels;
                cdHistChart.data.datasets = built.datasets;
                cdHistChart.update();
            });
        });
    }

    // ── Gráfico de distribuição por contrato (barras horizontais empilhadas) ──
    const ctxContratos = document.getElementById('chartContratos');
    const cgLabels     = <?php echo $cg_labels; ?>;
    const cgDatasets   = <?php echo $cg_datasets_json; ?>;

    if (ctxContratos && cgLabels.length) {
        ctxContratos.style.height = '<?php echo $cg_canvas_height; ?>px';

        new Chart(ctxContratos, {
            type: 'bar',
            data: { labels: cgLabels, datasets: cgDatasets },
            options: {
                indexAxis:           'y',
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth:  12,
                            boxHeight: 12,
                            borderRadius: 3,
                            useBorderRadius: true,
                            font: { size: 11 },
                            padding: 16,
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.raw}`,
                            footer: (items) => {
                                const total = items.reduce((s, i) => s + i.raw, 0);
                                return 'Total: ' + total;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked:      true,
                        beginAtZero:  true,
                        ticks:        { precision: 0 },
                        grid:         { color: 'rgba(0,0,0,0.05)' }
                    },
                    y: {
                        stacked:      true,
                        grid:         { display: false },
                        ticks:        { font: { size: 12, weight: '600' } }
                    }
                }
            }
        });
    }

});
</script>

<?php Html::footer(); ?>
