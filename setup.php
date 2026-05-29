<?php
define('PLUGIN_CUSTOMDASHBOARD_VERSION', '1.0.0');
define('PLUGIN_CUSTOMDASHBOARD_MIN_GLPI', '10.0.0');
define('PLUGIN_CUSTOMDASHBOARD_MAX_GLPI', '12.0.0');

function plugin_init_customdashboard() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['customdashboard'] = true;

    if (Session::haveRight('computer', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['customdashboard'] = [
            'tools' => 'PluginCustomdashboardDashboard'
        ];
    }
}

function plugin_version_customdashboard() {
    return [
        'name'         => 'Custom Dashboard',
        'version'      => PLUGIN_CUSTOMDASHBOARD_VERSION,
        'author'       => '',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CUSTOMDASHBOARD_MIN_GLPI,
                'max' => PLUGIN_CUSTOMDASHBOARD_MAX_GLPI,
            ]
        ]
    ];
}

function plugin_customdashboard_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_CUSTOMDASHBOARD_MIN_GLPI, 'lt')) {
        echo sprintf("Este plugin requer GLPI >= %s", PLUGIN_CUSTOMDASHBOARD_MIN_GLPI);
        return false;
    }
    return true;
}

function plugin_customdashboard_check_config() {
    return true;
}

function plugin_customdashboard_install() {
    return true;
}

function plugin_customdashboard_uninstall() {
    return true;
}
