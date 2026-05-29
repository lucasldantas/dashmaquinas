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
