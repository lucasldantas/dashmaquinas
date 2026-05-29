# Dashboard de Máquinas — Plugin GLPI

Plugin para GLPI 11 que exibe um dashboard visual com status de máquinas por localidade e visão consolidada de contratos de aluguel.

---

## Funcionalidades

### Status por Localidade
- Tabela com contagem de máquinas por localidade e status
- Padrão exibe **Estoque** e **Manutenção** — botão **Colunas** permite adicionar/remover qualquer outro status dinamicamente
- Os nomes dos status são lidos diretamente do banco (`glpi_states`), sem hardcode

### Gráfico Status por Localidade
- Gráfico de barras agrupadas mostrando a distribuição por localidade
- **Sincronizado com o filtro de colunas**: marcar/desmarcar um status atualiza o gráfico em tempo real
- Legenda dinâmica — exibe apenas os status ativos

### Máquinas Alugadas por Contrato
- Gráfico de barras horizontais empilhadas mostrando todos os contratos e seus status
- Cards por contrato com barra de progresso empilhada e legenda de status
- Tabela por localidade dentro de cada contrato com mini-barras de distribuição

### Cartões de Resumo
- Total em Estoque
- Total em Manutenção
- Total de Máquinas Alugadas (vinculadas a contratos)

---

## Requisitos

| Item | Versão |
|---|---|
| GLPI | 11.x |
| PHP | 8.1+ |
| Chart.js | 4.4.0 (carregado via CDN) |

---

## Instalação

1. Copie a pasta `customdashboard` para `/var/www/html/glpi/plugins/`
2. Ajuste as permissões:
   ```bash
   chown -R www-data:www-data /var/www/html/glpi/plugins/customdashboard
   ```
3. No GLPI: **Configuração → Plugins → Dashboard de Máquinas → Instalar → Ativar**
4. Acesse pelo menu **Ferramentas → Dashboard Máquinas**

---

## Estrutura de Arquivos

```
customdashboard/
├── setup.php                    # Registro do plugin
├── hook.php                     # Hooks (mínimo)
├── css/
│   └── style.css                # Estilos do dashboard
├── front/
│   └── dashboard.php            # Página principal
└── inc/
    └── dashboard.class.php      # Queries e lógica de dados
```

---

## Tabelas do Banco Utilizadas

| Tabela | Uso |
|---|---|
| `glpi_computers` | Máquinas e seus status/localidades |
| `glpi_states` | Nomes dos status |
| `glpi_locations` | Nomes das localidades |
| `glpi_contracts` | Contratos de aluguel |
| `glpi_contracts_items` | Vínculo máquina ↔ contrato |

---

## Tecnologias

- PHP 8.1+ / GLPI 11 API
- Bootstrap 5 + Tabler Icons (embutidos no GLPI)
- [Chart.js 4.4](https://www.chartjs.org/) via CDN
