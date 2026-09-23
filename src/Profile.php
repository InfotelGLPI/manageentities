<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use CommonGLPI;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Html;
use ProfileRight;
use Session;
use GlpiPlugin\Manageentities\Entity;

class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return _n('Right management', 'Rights management', $nb, 'manageentities');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            return self::createTabEntry(__('Entities portal', 'manageentities'));
        }
        return '';
    }

    /**
     * @return string
     */
    public static function getIcon()//self::createTabEntry(
    {
        return "ti ti-user-pentagon";
    }


    /**
     * @param CommonGLPI $item
     * @param int $tabnum
     * @param int $withtemplate
     *
     * @return bool
     */
    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if (!$item instanceof \Profile || !self::canView()) {
            return false;
        }

        $profile = new \Profile();
        $profile->getFromDB($item->getID());

        $rights = self::getAllRights(true);

        $twig = TemplateRenderer::getInstance();
        $twig->display('@manageentities/profile.html.twig', [
            'id' => $item->getID(),
            'profile' => $profile,
            'title' => self::getTypeName(Session::getPluralNumber()),
            'rights' => $rights,
        ]);

        return true;
    }

    public static function getAllRights($all = false)
    {
        $rights = [
            [
                'itemtype' => Entity::class,
                'label' => __('Entities portal', 'manageentities'),
                'field' => 'plugin_manageentities',
            ],
            [
                'itemtype' => CriDetail::class,
                'label' => _n('Intervention report', 'Intervention reports', 1, 'manageentities'),
                'field' => 'plugin_manageentities_cri_create',
            ],
            [
                // Dedicated right for the direct helpdesk feature. DirectHelpdesk extends
                // CommonDBTM, so the rights-choice matrix exposes the standard
                // CREATE/READ/UPDATE/PURGE bits from CommonDBTM::getRights().
                'itemtype' => DirectHelpdesk::class,
                'label' => __('Direct helpdesk', 'manageentities'),
                'field' => 'plugin_manageentities_directhelpdesk',
            ],
        ];

        return $rights;
    }

    /**
     * Init profiles
     *
     **/

    public static function translateARight($old_right)
    {
        switch ($old_right) {
            case '':
                return 0;
            case 'r':
                return READ;
            case 'w':
                return ALLSTANDARDRIGHT;
            case '0':
            case '1':
                return $old_right;

            default:
                return 0;
        }
    }

    /**
     * @param $profiles_id profile ID
     *
     * @since 0.85
     * Migration rights from old system to the new one for one profile
     *
     */
    public static function migrateOneProfile()
    {
        global $DB;
        //Cannot launch migration if there's nothing to migrate...
        if (!$DB->tableExists('glpi_plugin_manageentities_profiles')) {
            return true;
        }
        $dbu = new DbUtils();
        $datas = $dbu->getAllDataFromTable('glpi_plugin_manageentities_profiles');

        foreach ($datas as $profile_data) {
            $matching = [
                'manageentities' => 'plugin_manageentities',
                'cri_create' => 'plugin_manageentities_cri_create',
            ];
            // Search existing rights
            $used = [];
            $existingRights = $dbu->getAllDataFromTable(
                'glpi_profilerights',
                ["`profiles_id`" => $profile_data['profiles_id']],
            );
            foreach ($existingRights as $right) {
                $used[$right['profiles_id']][$right['name']] = $right['rights'];
            }

            // Add or update rights
            foreach ($matching as $old => $new) {
                if (isset($used[$profile_data['profiles_id']][$new])) {
                    $DB->update('glpi_profilerights', ['rights' => self::translateARight($profile_data[$old])], [
                        'name' => $new,
                        'profiles_id' => $profile_data['profiles_id'],
                    ]);
                } else {
                    $DB->add('glpi_profilerights', ['rights' => self::translateARight($profile_data[$old])], [
                        'name' => $new,
                        'profiles_id' => $profile_data['profiles_id'],
                    ]);
                }
            }
        }
    }

    /**
     * Initialize profiles, and migrate it necessary
     */
    public static function initProfile()
    {
        global $DB;
        $profile = new self();
        $dbu = new DbUtils();

        //Add new rights in glpi_profilerights table
        foreach ($profile->getAllRights(true) as $data) {
            if ($dbu->countElementsInTable(
                "glpi_profilerights",
                ["name" => $data['field']],
            ) == 0) {
                ProfileRight::addProfileRights([$data['field']]);

                // First registration of the dedicated direct-helpdesk right: the direct
                // helpdesk feature was previously gated on the generic
                // 'plugin_manageentities' right. Seed the new right from each profile's
                // existing generic value (masked to the four exposed bits) so profiles
                // that could already use the feature keep their access after the split
                // instead of silently losing it on update.
                if ($data['field'] === 'plugin_manageentities_directhelpdesk') {
                    $seed_mask = CREATE | READ | UPDATE | PURGE;
                    foreach ($DB->request([
                        'FROM'  => 'glpi_profilerights',
                        'WHERE' => ['name' => 'plugin_manageentities'],
                    ]) as $row) {
                        $DB->update(
                            'glpi_profilerights',
                            ['rights' => ((int) $row['rights']) & $seed_mask],
                            [
                                'name'        => 'plugin_manageentities_directhelpdesk',
                                'profiles_id' => $row['profiles_id'],
                            ],
                        );
                    }
                }
            }
        }

        // Migration old rights in new ones
        self::migrateOneProfile();

        $it = $DB->request([
            'FROM' => 'glpi_profilerights',
            'WHERE' => [
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'name' => ['LIKE', '%plugin_manageentities%'],
            ],
        ]);
        foreach ($it as $prof) {
            if (isset($_SESSION['glpiactiveprofile'])) {
                $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
            }
        }
    }

    public static function createFirstAccess($profiles_id)
    {
        self::addDefaultProfileInfos(
            $profiles_id,
            [
                'plugin_manageentities' => ALLSTANDARDRIGHT,
                'plugin_manageentities_cri_create' => ALLSTANDARDRIGHT,
                'plugin_manageentities_directhelpdesk' => CREATE | READ | UPDATE | PURGE,
            ],
            true,
        );
    }

    public static function removeRightsFromSession()
    {
        foreach (self::getAllRights(true) as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right['field']])) {
                unset($_SESSION['glpiactiveprofile'][$right['field']]);
            }
        }
    }

    public static function removeRightsFromDB()
    {
        $plugprof = new ProfileRight();
        foreach (self::getAllRights(true) as $right) {
            $plugprof->deleteByCriteria(['name' => $right['field']]);
        }
    }

    /**
     * @param $profile
     **/
    public static function addDefaultProfileInfos($profiles_id, $rights, $drop_existing = false)
    {
        $profileRight = new ProfileRight();
        $dbu = new DbUtils();

        foreach ($rights as $right => $value) {
            if ($dbu->countElementsInTable(
                'glpi_profilerights',
                [
                    "profiles_id" => $profiles_id,
                    "name" => $right,
                ],
            ) && $drop_existing) {
                $profileRight->deleteByCriteria(['profiles_id' => $profiles_id, 'name' => $right]);
            }
            if (!$dbu->countElementsInTable(
                'glpi_profilerights',
                [
                    "profiles_id" => $profiles_id,
                    "name" => $right,
                ],
            )) {
                $myright['profiles_id'] = $profiles_id;
                $myright['name'] = $right;
                $myright['rights'] = $value;
                $profileRight->add($myright);

                //Add right to the current session
                $_SESSION['glpiactiveprofile'][$right] = $value;
            }
        }
    }

    /**
     * Does this user hold $right on $rightname on EVERY one of $entities_ids?
     *
     * Session::haveRight() answers for the CURRENT user only, which is of no use in an
     * automatic action: those run with no session and have to decide per recipient. The
     * callers are the two cross-entity digests: one mail spans many clients, so the recipient
     * must already be able to read each entity it lists, through a profile assignment carrying
     * the right on that entity itself or recursively on one of its ancestors.
     *
     * @param int    $users_id
     * @param string $rightname
     * @param int    $right
     * @param int[]  $entities_ids entities listed in the mail
     *
     * @return bool
     */
    public static function userHasRightOnEntities(int $users_id, string $rightname, int $right, array $entities_ids): bool
    {
        global $DB;

        $entities_ids = array_unique(array_map('intval', $entities_ids));
        // Nothing to cover would pass vacuously: refuse instead.
        if ($users_id <= 0 || $entities_ids === []) {
            return false;
        }

        $iterator = $DB->request([
            'SELECT'     => [
                'glpi_profiles_users.entities_id',
                'glpi_profiles_users.is_recursive',
            ],
            'FROM'       => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profilerights' => [
                    'FKEY' => [
                        'glpi_profiles_users' => 'profiles_id',
                        'glpi_profilerights'  => 'profiles_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_profiles_users.users_id' => $users_id,
                'glpi_profilerights.name'      => $rightname,
                new QueryExpression(
                    $DB::quoteName('glpi_profilerights.rights') . ' & ' . (int) $right,
                ),
            ],
        ]);

        $covered = [];
        foreach ($iterator as $assignment) {
            $assignment_entity = (int) $assignment['entities_id'];
            if ($assignment['is_recursive']) {
                $covered += getSonsOf('glpi_entities', $assignment_entity);
            } else {
                $covered[$assignment_entity] = $assignment_entity;
            }
        }

        return array_diff($entities_ids, array_keys($covered)) === [];
    }
}
