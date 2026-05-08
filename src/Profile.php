<?php

namespace GlpiPlugin\Duplicate;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class Profile extends CommonDBTM
{
    public static $rightname = 'config';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof GlpiProfile) {
            return 'Duplicate Checker';
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof GlpiProfile) {
            self::showProfileForm($item);
        }
        return true;
    }

    public static function showProfileForm(GlpiProfile $profile): void
    {
        $rights  = self::getAllRights();
        $canedit = Session::haveRight('config', UPDATE);
        $id      = $profile->getID();

        echo '<div class="card mt-3">';
        echo '<div class="card-header"><h5>Duplicate Checker - Permissions</h5></div>';
        echo '<div class="card-body">';

        if ($canedit) {
            echo '<form method="POST" action="' . GlpiProfile::getFormURL() . '">';
            echo Html::hidden('id', ['value' => $id]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        }

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit' => $canedit,
            'title'   => 'Duplicate Checker plugin',
        ]);

        if ($canedit) {
            echo '<div class="mt-3 text-center">';
            echo '<button type="submit" name="update" class="btn btn-primary">Save</button>';
            echo '</div>';
            echo '</form>';
        }

        echo '</div></div>';
    }

    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => DuplicateManager::class,
                'label'    => 'Duplicate Checker',
                'field'    => 'plugin_duplicate_check',
                'rights'   => [
                    READ   => 'View duplicates',
                    UPDATE => 'Merge / Delete',
                ],
            ],
        ];
    }

    public static function changeProfile(): void
    {
        $profile_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!$profile_id) {
            return;
        }

        global $DB;
        $row = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['profiles_id' => $profile_id, 'name' => 'plugin_duplicate_check'],
            'LIMIT'  => 1,
        ])->current();

        $_SESSION['glpiactiveprofile']['plugin_duplicate_check'] = (int) ($row['rights'] ?? 0);
    }

    public static function addDefaultProfileRights(): void
    {
        global $DB;

        $right_name = 'plugin_duplicate_check';

        // Collect profiles that already have this right
        $existing = [];
        foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right_name]]) as $row) {
            $existing[] = (int) $row['profiles_id'];
        }

        // Insert rights=0 for any profile that doesn't have it yet
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            if (!in_array((int) $profile['id'], $existing, true)) {
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $profile['id'],
                    'name'        => $right_name,
                    'rights'      => 0,
                ]);
            }
        }

        // Find Super-Admin profile
        $super_admin_id = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin'], 'LIMIT' => 1]) as $row) {
            $super_admin_id = (int) $row['id'];
        }

        if (!$super_admin_id) {
            // Fallback: find a profile with config UPDATE right
            foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config', 'rights' => ['>', 0]], 'ORDER' => 'profiles_id ASC', 'LIMIT' => 1]) as $row) {
                $super_admin_id = (int) $row['profiles_id'];
            }
        }

        if (!$super_admin_id) {
            return;
        }

        $full = READ | UPDATE;

        $has_row = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $super_admin_id, 'name' => $right_name],
        ])->count() > 0;

        if ($has_row) {
            $DB->update('glpi_profilerights', ['rights' => $full], ['profiles_id' => $super_admin_id, 'name' => $right_name]);
        } else {
            $DB->insert('glpi_profilerights', ['profiles_id' => $super_admin_id, 'name' => $right_name, 'rights' => $full]);
        }
    }

    public static function removeRights(): void
    {
        ProfileRight::deleteProfileRights(['plugin_duplicate_check']);
    }
}
