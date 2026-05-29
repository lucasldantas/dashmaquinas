<?php
class PluginCustomdashboardDashboard extends CommonGLPI {

    static $rightname = 'computer';

    static function getTypeName($nb = 0) {
        return 'Custom Dashboard';
    }

    static function getMenuName() {
        return 'Dashboard Máquinas';
    }

    static function getMenuContent() {
        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('customdashboard') . '/front/dashboard.php',
            'icon'  => 'ti ti-layout-dashboard',
        ];
    }

    /**
     * Retorna instância de QueryExpression compatível com GLPI 10 e 11.
     */
    private static function qe(string $expr): object {
        if (class_exists('\Glpi\DBAL\QueryExpression')) {
            return new \Glpi\DBAL\QueryExpression($expr);
        }
        return new \QueryExpression($expr);
    }

    /**
     * Retorna contagem de computadores por localidade para um dado status.
     * Mantido para compatibilidade com getSummaryTotals e o gráfico combinado.
     */
    static function getStatusByLocation(string $status_name): array {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                self::qe("COALESCE(`l`.`name`, 'Sem localização') AS `location`"),
                self::qe('COUNT(`c`.`id`) AS `total`'),
            ],
            'FROM'      => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_states AS s' => [
                    'FKEY' => ['s' => 'id', 'c' => 'states_id'],
                ],
                'glpi_locations AS l' => [
                    'FKEY' => ['l' => 'id', 'c' => 'locations_id'],
                ],
            ],
            'WHERE'   => [
                's.name'        => $status_name,
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
            'GROUPBY' => ['c.locations_id', 'l.name'],
            'ORDER'   => [self::qe('`total` DESC')],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Retorna TODOS os status reais do banco agrupados por localidade.
     * Os nomes dos status vêm de glpi_states — sem hardcode.
     *
     * Retorno:
     *   [
     *     'locations'   => [ 'São Paulo' => ['Estoque'=>10, 'Em Uso'=>5, ...], ... ],
     *     'all_statuses'=> ['Estoque', 'Em Uso', 'Manutenção', ...],  // ordem: maior total primeiro
     *   ]
     */
    static function getAllStatusesByLocation(): array {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                self::qe("COALESCE(`l`.`name`, 'Sem localização') AS `location`"),
                self::qe("COALESCE(`s`.`name`, 'Sem status')      AS `status_name`"),
                self::qe('COUNT(`c`.`id`) AS `total`'),
            ],
            'FROM'      => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_states AS s' => [
                    'FKEY' => ['s' => 'id', 'c' => 'states_id'],
                ],
                'glpi_locations AS l' => [
                    'FKEY' => ['l' => 'id', 'c' => 'locations_id'],
                ],
            ],
            'WHERE'   => [
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
            'GROUPBY' => ['c.locations_id', 'l.name', 'c.states_id', 's.name'],
            'ORDER'   => [self::qe('`total` DESC')],
        ]);

        $locations     = [];
        $status_totals = [];

        foreach ($iterator as $row) {
            $loc    = $row['location'];
            $status = $row['status_name'];
            $total  = (int)$row['total'];

            $locations[$loc][$status]  = $total;
            $status_totals[$status]   = ($status_totals[$status] ?? 0) + $total;
        }

        // Ordena localidades pelo total descendente
        uksort($locations, function ($a, $b) use ($locations) {
            return array_sum($locations[$b]) - array_sum($locations[$a]);
        });

        // Ordena status pelo total descendente
        arsort($status_totals);

        return [
            'locations'    => $locations,
            'all_statuses' => array_keys($status_totals),
        ];
    }

    /**
     * Retorna máquinas alugadas agrupadas por contrato → localidade → status.
     */
    static function getRentedMachinesByContract(): array {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'co.id AS contract_id',
                'co.name AS contract_name',
                self::qe("COALESCE(`s`.`name`, 'Sem status') AS `status`"),
                self::qe("COALESCE(`l`.`name`, 'Sem localização') AS `location`"),
                self::qe('COUNT(`c`.`id`) AS `total`'),
            ],
            'FROM'       => 'glpi_computers AS c',
            'INNER JOIN' => [
                'glpi_contracts_items AS ci' => [
                    'FKEY' => ['ci' => 'items_id', 'c' => 'id'],
                    'AND'  => ['ci.itemtype' => 'Computer'],
                ],
                'glpi_contracts AS co' => [
                    'FKEY' => ['co' => 'id', 'ci' => 'contracts_id'],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_states AS s' => [
                    'FKEY' => ['s' => 'id', 'c' => 'states_id'],
                ],
                'glpi_locations AS l' => [
                    'FKEY' => ['l' => 'id', 'c' => 'locations_id'],
                ],
            ],
            'WHERE'   => [
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
                'co.is_deleted' => 0,
            ],
            'GROUPBY' => [
                'co.id', 'co.name',
                'c.states_id', 's.name',
                'c.locations_id', 'l.name',
            ],
            'ORDER' => ['co.name ASC', self::qe('`total` DESC')],
        ]);

        $contracts = [];
        foreach ($iterator as $row) {
            $cid = $row['contract_id'];

            if (!isset($contracts[$cid])) {
                $contracts[$cid] = [
                    'name'      => $row['contract_name'],
                    'total'     => 0,
                    'statuses'  => [],
                    'locations' => [],
                ];
            }

            $contracts[$cid]['total'] += $row['total'];

            $status   = $row['status'];
            $location = $row['location'];

            $contracts[$cid]['statuses'][$status] =
                ($contracts[$cid]['statuses'][$status] ?? 0) + $row['total'];

            if (!isset($contracts[$cid]['locations'][$location])) {
                $contracts[$cid]['locations'][$location] = ['total' => 0, 'statuses' => []];
            }
            $contracts[$cid]['locations'][$location]['total'] += $row['total'];
            $contracts[$cid]['locations'][$location]['statuses'][$status] =
                ($contracts[$cid]['locations'][$location]['statuses'][$status] ?? 0) + $row['total'];
        }

        return $contracts;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HISTÓRICO DE ESTOQUE (snapshots mensais)
    // ══════════════════════════════════════════════════════════════════════

    /** Paleta de cores para as linhas do gráfico histórico. */
    private static array $history_colors = [
        '#4263eb', '#2fb344', '#f76707', '#7048e8', '#1098ad',
        '#f59f00', '#d6336c', '#0ca678', '#ea580c', '#7c3aed',
    ];

    /**
     * Retorna mapa location → cor hex para uso no gráfico.
     * Usa a mesma paleta de $history_colors para consistência.
     */
    static function getLocationColors(array $locations): array {
        $result = [];
        $n = count(self::$history_colors);
        foreach (array_values($locations) as $i => $loc) {
            $result[$loc] = self::$history_colors[$i % $n];
        }
        return $result;
    }

    /**
     * Tira um snapshot do Estoque atual por localidade e grava na tabela de histórico.
     * Se já existe registro para o mês corrente, atualiza (idempotente).
     * Retorna quantas localidades foram gravadas.
     */
    static function takeSnapshot(int $entities_id = 0): int {
        global $DB;

        plugin_customdashboard_create_history_table();

        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        $iterator = $DB->request([
            'SELECT' => [
                self::qe("COALESCE(`l`.`name`, 'Sem localizacao') AS `location`"),
                self::qe('COUNT(`c`.`id`) AS `total`'),
            ],
            'FROM'      => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_states AS s'    => ['FKEY' => ['s' => 'id', 'c' => 'states_id']],
                'glpi_locations AS l' => ['FKEY' => ['l' => 'id', 'c' => 'locations_id']],
            ],
            'WHERE'   => [
                's.name'        => 'Estoque',
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
            'GROUPBY' => ['c.locations_id', 'l.name'],
        ]);

        $count = 0;
        foreach ($iterator as $row) {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_plugin_customdashboard_stockhistory',
                'WHERE'  => ['location_name' => $row['location'], 'snapshot_date' => $today],
            ])->current();

            $data = ['estoque_count' => (int)$row['total'], 'entities_id' => $entities_id];

            if ($existing) {
                $DB->update('glpi_plugin_customdashboard_stockhistory',
                            $data, ['id' => (int)$existing['id']]);
            } else {
                $DB->insert('glpi_plugin_customdashboard_stockhistory', array_merge($data, [
                    'location_name' => $row['location'],
                    'snapshot_date' => $today,
                    'date_creation' => $now,
                ]));
            }
            $count++;
        }
        return $count;
    }

    /**
     * Retorna os snapshots de estoque como array plano — sem agregação.
     * A filtragem e agrupamento (7 dias / 30 dias / mensal) são feitos no cliente (JS).
     *
     * Retorno:
     *   [ ['date' => 'YYYY-MM-DD', 'location' => '...', 'count' => N], ... ]
     */
    static function getStockHistory(): array {
        global $DB;

        // Garante que a tabela existe e tem o schema atual (migra se necessário)
        plugin_customdashboard_create_history_table();

        if (!$DB->tableExists('glpi_plugin_customdashboard_stockhistory')) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT' => ['location_name', 'snapshot_date', 'estoque_count'],
            'FROM'   => 'glpi_plugin_customdashboard_stockhistory',
            'ORDER'  => ['snapshot_date ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = [
                'date'     => $row['snapshot_date'],
                'location' => $row['location_name'],
                'count'    => (int)$row['estoque_count'],
            ];
        }
        return $rows;
    }

    // ── Cron ──────────────────────────────────────────────────────────────────

    /**
     * Executado pelo cron mensal do GLPI.
     */
    static function cronStocksnapshot(CronTask $task): int {
        $count = self::takeSnapshot(0);
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    /**
     * Descrição da tarefa cron exibida no painel de agendamento do GLPI.
     */
    static function cronInfo(string $name): array {
        return match($name) {
            'stocksnapshot' => ['description' => 'Snapshot mensal do estoque de máquinas por localidade'],
            default         => [],
        };
    }

    /**
     * Retorna totais gerais para os cartões de resumo.
     */
    static function getSummaryTotals(): array {
        global $DB;

        $totals = ['estoque' => 0, 'manutencao' => 0, 'alugadas' => 0];

        // Total em Estoque
        $iter = $DB->request([
            'COUNT'     => 'cpt',
            'FROM'      => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_states AS s' => ['FKEY' => ['s' => 'id', 'c' => 'states_id']],
            ],
            'WHERE' => [
                's.name'        => 'Estoque',
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
        ]);
        $totals['estoque'] = (int)($iter->current()['cpt'] ?? 0);

        // Total em Manutenção
        $iter = $DB->request([
            'COUNT'     => 'cpt',
            'FROM'      => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_states AS s' => ['FKEY' => ['s' => 'id', 'c' => 'states_id']],
            ],
            'WHERE' => [
                's.name'        => 'Manutenção',
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ],
        ]);
        $totals['manutencao'] = (int)($iter->current()['cpt'] ?? 0);

        // Total alugadas (vinculadas a contratos)
        $iter = $DB->request([
            'SELECT'     => [self::qe('COUNT(DISTINCT `c`.`id`) AS `cpt`')],
            'FROM'       => 'glpi_computers AS c',
            'INNER JOIN' => [
                'glpi_contracts_items AS ci' => [
                    'FKEY' => ['ci' => 'items_id', 'c' => 'id'],
                    'AND'  => ['ci.itemtype' => 'Computer'],
                ],
                'glpi_contracts AS co' => [
                    'FKEY' => ['co' => 'id', 'ci' => 'contracts_id'],
                ],
            ],
            'WHERE' => [
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
                'co.is_deleted' => 0,
            ],
        ]);
        $totals['alugadas'] = (int)($iter->current()['cpt'] ?? 0);

        return $totals;
    }
}
