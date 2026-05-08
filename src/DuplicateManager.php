<?php

namespace GlpiPlugin\Duplicate;

use CommonGLPI;
use Plugin;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class DuplicateManager extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Inventory Duplicate Checker';
    }

    public static function getMenuName(): string
    {
        return 'Duplicate Checker';
    }

    public static function getMenuContent(): array|false
    {
        if (!Session::haveRight('plugin_duplicate_check', READ)) {
            return false;
        }

        return [
            'title' => static::getMenuName(),
            'page'  => Plugin::getWebDir('duplicate') . '/front/index.php',
            'icon'  => 'ti ti-copy-off',
        ];
    }
}
