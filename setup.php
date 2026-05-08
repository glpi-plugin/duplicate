<?php

define('PLUGIN_DUPLICATE_VERSION', '1.0.0');

function plugin_init_duplicate(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['duplicate'] = true;

    Plugin::registerClass(\GlpiPlugin\Duplicate\Profile::class, ['addtabon' => 'Profile']);
    Plugin::registerClass(\GlpiPlugin\Duplicate\DuplicateManager::class);

    $PLUGIN_HOOKS['change_profile']['duplicate'] = [
        \GlpiPlugin\Duplicate\Profile::class, 'changeProfile',
    ];

    if (Session::haveRight('plugin_duplicate_check', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['duplicate']['tools'] = \GlpiPlugin\Duplicate\DuplicateManager::class;
    }
}

function plugin_version_duplicate(): array
{
    return [
        'name'         => 'Inventory Duplicate Checker',
        'version'      => PLUGIN_DUPLICATE_VERSION,
        'author'       => 'DSI PF',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => '11.0.0'],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_duplicate_check_prerequisites(): bool { return true; }
function plugin_duplicate_check_config(): bool        { return true; }

function plugin_duplicate_install(): bool
{
    global $DB;

    $migration = new Migration(10000);

    if (!$DB->tableExists('glpi_plugin_duplicate_ignored')) {
        $migration->addPostQuery(
            "CREATE TABLE `glpi_plugin_duplicate_ignored` (
                `id`            int(11)      NOT NULL AUTO_INCREMENT,
                `itemtype`      varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                `items_id_a`    int(11)      NOT NULL DEFAULT 0,
                `items_id_b`    int(11)      NOT NULL DEFAULT 0,
                `match_reason`  varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                `ignored_by`    int(11)      NOT NULL DEFAULT 0,
                `date_creation` datetime     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_pair` (`itemtype`, `items_id_a`, `items_id_b`, `match_reason`),
                KEY `itemtype` (`itemtype`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    $migration->executeMigration();

    \GlpiPlugin\Duplicate\Profile::addDefaultProfileRights();

    return true;
}

function plugin_duplicate_uninstall(): bool
{
    ProfileRight::deleteProfileRights(['plugin_duplicate_check']);
    return true;
}
