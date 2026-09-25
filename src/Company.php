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

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use DbUtils;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Config;
use Html;
use Migration;
use Session;
use Toolbox;

class Company extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';
    // From CommonDBTM
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Company', 'Companies', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-building";
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === Config::class) {
            return self::createTabEntry(self::getTypeName(2), 0, $item->getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Config::class) {
            self::showList();
        }
        return true;
    }

    public static function showList(): void
    {
        $dbu = new DbUtils();
        $plugin_company = new self();
        // find() applies no boundary of its own, so the criteria are explicit. Now that the
        // table carries entities_id / is_recursive, the core helper builds exactly the
        // perimeter this class used to assemble by hand - the row is visible from its own
        // entity, a recursive one from its descendants too - and it honours "see all
        // entities" without a special case.
        $result = $plugin_company->find(
            $dbu->getEntitiesRestrictCriteria(self::getTable(), '', '', true),
        );
        $companies = [];
        $link = Toolbox::getItemTypeFormURL(self::class);
        foreach ($result as $data) {
            $plugin_company->getFromDB($data['id']);
            // getLink() builds the whole anchor itself and escapes both the href and the label,
            // which is what the column needs: the template used to concatenate the url and the
            // raw getNameID() into an <a> and hand the result to the raw_html formatter, so a
            // company name carrying markup - it is free text, saved by anyone holding the
            // plugin's update right - was executed in the browser of every reader of the tab.
            $companies[] = [
                'name' => $plugin_company->getLink(),
            ];
        }

        TemplateRenderer::getInstance()->display(
            '@manageentities/company_list.html.twig',
            [
                'companies' => $companies,
                'can_add'   => Session::haveRight('plugin_manageentities', UPDATE),
                'add_title' => __('Add a company', 'manageentities'),
                'form_url'  => $link,
            ],
        );
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id' => '2',
            'table' => $this->getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id' => '9',
            'table' => $this->getTable(),
            'field' => 'address',
            'name' => __('Address'),
            'massiveaction' => false,
            'datatype' => 'text',
        ];

        return $tab;
    }

    /**
     * Display the company form
     *
     * @param $ID integer ID of the item
     * @param $options array
     *     - target filename : where to go when done.
     *     - withtemplate boolean : template or basic item
     *
     * @return boolean item found
     * */
    public function showForm($ID, $options = [])
    {
        global $CFG_GLPI;

        if ($ID > 0) {
            $this->check($ID, READ);
        } else {
            // Create item
            $this->check(-1, CREATE);
        }

        // Set session saved if exists
        $this->setSessionValues();

        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@manageentities/company_form.html.twig', [
            'item'     => $this,
            'params'   => $options,
            'logo_url' => !empty($this->fields['logo_id'])
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $this->fields['logo_id']
                : '',
        ]);

        return true;
    }

    public function setSessionValues()
    {
        if (isset($_SESSION['plugin_manageentities']['company']) && !empty($_SESSION['plugin_manageentities']['company'])) {
            foreach ($_SESSION['plugin_manageentities']['company'] as $key => $val) {
                $this->fields[$key] = $val;
            }
        }
        unset($_SESSION['plugin_manageentities']['company']);
    }

    // canViewItem(), canUpdateItem() and canPurgeItem() used to be overridden here to rebuild
    // the entity boundary by hand, because the table named its columns entity_id / recursive
    // and CommonDBTM::isEntityAssign() therefore returned false. The columns are now the
    // canonical entities_id / is_recursive (install/sql/update-4.2.13.sql), so the inherited
    // implementations call checkEntity() themselves and the overrides are gone: access control
    // belongs to the framework, and an override is precisely what used to make it disappear.

    /**
     * Whether an uploaded logo really is a JPEG.
     *
     * The name is the last path segment of a file taken from GLPI_TMP_DIR, so any separator in it
     * would point the check somewhere else entirely, and the extension of that name says nothing
     * about the content: a file whose bytes are HTML or SVG used to be accepted and served back as
     * the company logo. Fail-closed - an absent or unreadable file is refused.
     *
     * @param mixed $file
     *
     * @return bool
     */
    private static function isJpegUpload($file): bool
    {
        if (!is_string($file) || $file === ''
            || basename($file) !== $file
            || strpbrk($file, "/\\") !== false) {
            return false;
        }

        $tmpfile = GLPI_TMP_DIR . "/" . $file;

        return is_file($tmpfile) && mime_content_type($tmpfile) === 'image/jpeg';
    }

    public function prepareInputForUpdate($input)
    {
        // Moving a company into an entity the session cannot see would put it - and its logo -
        // out of reach of its own owner, so the destination is checked like the row itself.
        if (isset($input['entities_id'])
            && !Session::haveAccessToEntity((int) $input['entities_id'], (bool) ($input['is_recursive'] ?? 0))) {
            Session::addMessageAfterRedirect(
                __('Entity not found', 'manageentities'),
                false,
                ERROR,
            );
            return false;
        }

        if (isset($input["_filename"])) {
            $plugin_company = new Company();
            $company = $plugin_company->find(['id' => $input['id']]);
            $company = reset($company);

            if (!self::isJpegUpload($input["_filename"][0] ?? null)) {
                Session::addMessageAfterRedirect(
                    __('The format of the image must be in JPG or JPEG', 'manageentities'),
                    false,
                    ERROR,
                );

                // This branch used to unset($input) and fall through to the return below, which
                // read a variable that no longer existed: PHP warned and the method returned null.
                // prepareInputForUpdate() is contracted to return either an array or false, and
                // null is not false, so the refusal depended on how loosely the core tested the
                // result. Refuse the update explicitly.
                return false;
            } elseif ($company['logo_id'] != 0) {
                $doc = new Document();
                $img = $doc->find(['id' => $company["logo_id"]]);
                $img = reset($img);
                $doc->delete($img, 1);
            }
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        // Same entity check as on update. check(-1, CREATE, $_POST) in front/company.form.php
        // now covers the web form, but the check is kept here so it also holds on the paths that
        // never go through that controller (massive actions, API, another plugin calling add()).
        if (!Session::haveAccessToEntity((int) ($input['entities_id'] ?? 0), (bool) ($input['is_recursive'] ?? 0))) {
            Session::addMessageAfterRedirect(
                __('Entity not found', 'manageentities'),
                false,
                ERROR,
            );
            return false;
        }

        if (isset($input["_filename"])) {
            if (!self::isJpegUpload($input["_filename"][0] ?? null)) {
                Session::addMessageAfterRedirect(
                    __('The format of the image must be in JPG or JPEG', 'manageentities'),
                    false,
                    ERROR,
                );
                return [];
            }
        }
        return $input;
    }

    public function post_addItem($history = 1)
    {
        $img = $this->addFiles($this->input);
        foreach ($img as $key => $name) {
            $this->fields['logo_id'] = $key;
            $this->updateInDB(['logo_id']);
        }
    }

    public function post_updateItem($history = 1)
    {
        if ($this->fields['logo_id'] == 0) {
            $img = $this->addFiles($this->input);
            foreach ($img as $key => $name) {
                $this->fields['logo_id'] = $key;
                $this->updateInDB(['logo_id']);
            }
        }
    }

    /**
     *
     * @param int $donotif
     * @param  $disablenotif
     *
     * @return array|mixed[]
     * @global  $CFG_GLPI
     *
     */
    public function addFiles(array $input, $options = [])
    {
        global $CFG_GLPI;

        $default_options = [
            'force_update' => false,
            'content_field' => 'content',
        ];
        $options = array_merge($default_options, $options);

        if (!isset($input['_filename'])
            || (count($input['_filename']) == 0)) {
            return $input;
        }
        $docadded = [];
        $donotif = isset($input['_donotif']) ? $input['_donotif'] : 0;
        $disablenotif = isset($input['_disablenotif']) ? $input['_disablenotif'] : 0;

        foreach ($this->input['_filename'] as $key => $file) {
            // Path traversal + MIME bypass hardening (aligns Company with the sibling
            // EntityLogo::addLogo()): _filename is client-supplied and is joined onto
            // GLPI_TMP_DIR below, then handed to Toolbox::resizePicture(). The same check now
            // guards prepareInputForAdd() and prepareInputForUpdate(), so a file that is not a
            // JPEG no longer gets as far as deleting the logo it was meant to replace.
            if (!self::isJpegUpload($file)) {
                Session::addMessageAfterRedirect(
                    __('The format of the image must be in JPG or JPEG', 'manageentities'),
                    false,
                    ERROR,
                );
                continue;
            }
            $tmpfile = GLPI_TMP_DIR . "/" . $file;

            $doc = new Document();
            $docitem = new Document_Item();
            $docID = 0;
            $filename = GLPI_TMP_DIR . "/" . $file;
            $input2 = [];

            // Crop/Resize image file if needed
            if (isset($this->input['_coordinates']) && !empty($this->input['_coordinates'][$key])) {
                $image_coordinates = json_decode(urldecode($this->input['_coordinates'][$key]), true);
                Toolbox::resizePicture(
                    $filename,
                    $filename,
                    $image_coordinates['img_w'],
                    $image_coordinates['img_h'],
                    $image_coordinates['img_y'],
                    $image_coordinates['img_x'],
                    $image_coordinates['img_w'],
                    $image_coordinates['img_h'],
                    0,
                );
            } else {
                Toolbox::resizePicture($filename, $filename);
            }

            //If file tag is present
            if (isset($input['_tag_filename'])
                && !empty($input['_tag_filename'][$key])) {
                $input['_tag'][$key] = $input['_tag_filename'][$key];
            }

            //retrieve entity
            $entities_id = isset($this->fields["entities_id"])
                ? $this->fields["entities_id"]
                : $_SESSION['glpiactive_entity'];

            // Check for duplicate
            if ($doc->getFromDBbyContent($entities_id, $filename)) {
                if (!$doc->fields['is_blacklisted']) {
                    $docID = $doc->fields["id"];
                }
                // File already exist, we replace the tag by the existing one
                if (isset($input['_tag'][$key])
                    && ($docID > 0)
                    && isset($input[$options['content_field']])) {
                    $input[$options['content_field']]
                        = preg_replace(
                            '/' . Document::getImageTag($input['_tag'][$key]) . '/',
                            Document::getImageTag($doc->fields["tag"]),
                            $input[$options['content_field']],
                        );
                    $docadded[$docID]['tag'] = $doc->fields["tag"];
                }
            } else {
                //TRANS: Default document to files attached to tickets : %d is the ticket id
                // No addslashes()/stripslashes() here: GLPI stores raw data since version 10 and
                // the query builder escapes on its own, so the pair used to write literal
                // backslashes into the database and strip legitimate ones back out.
                $input2["name"] = sprintf(__('Logo %d', 'manageentities'), $this->getID());
                // The logo belongs to the entity of the company, not to the one the uploader
                // happens to be working in.
                $input2["entities_id"] = $this->fields["entities_id"];
                $input2["_only_if_upload_succeed"] = 1;
                $input2["_filename"] = [$file];
                $input2["is_recursive"] = 1;
                $docID = $doc->add($input2);
            }

            if ($docID > 0) {
                if ($docitem->add([
                    'documents_id' => $docID,
                    '_do_notif' => $donotif,
                    '_disablenotif' => $disablenotif,
                    'itemtype' => $this->getType(),
                    'items_id' => $this->getID(),
                ])) {
                    $docadded[$docID]['data'] = sprintf(
                        __('%1$s - %2$s'),
                        $doc->fields["name"],
                        $doc->fields["filename"],
                    );

                    // Read the generated tag from the saved Document ($input2 is passed by value to
                    // add(), so it never carries the tag back); mirrors the duplicate-document branch above.
                    if (!empty($doc->fields["tag"])) {
                        $docadded[$docID]['tag'] = $doc->fields["tag"];
                        unset($this->input['_filename'][$key]);
                        unset($this->input['_tag'][$key]);
                    }
                    if (isset($this->input['_coordinates'][$key])) {
                        unset($this->input['_coordinates'][$key]);
                    }
                }
            }
            // Only notification for the first New doc
            $donotif = 0;
        }
        return $docadded;
    }

    /**
     * Returns the company's address
     *
     * @param $obj
     *
     * @return string address
     */
    public static function getAddress($obj)
    {
        $plugin_company = new Company();
        $company = $plugin_company->find(['entities_id' => $obj->entite[0]->fields['id']]);
        $company = reset($company);
        $dbu = new DbUtils();
        if ($company == false) {
            $companies = $plugin_company->find();
            foreach ($companies as $data) {
                if ($data['is_recursive'] == 1) {
                    $sons = $dbu->getSonsOf("glpi_entities", $data['entities_id']);
                    foreach ($sons as $son) {
                        if ($son == $obj->entite[0]->fields['id']) {
                            return $data['address'];
                        }
                    }
                }
            }
        } else {
            return $company['address'];
        }
    }

    /**
     * Returns the company logo
     *
     * @param $obj
     *
     * @return array|mixed[]
     */
    public static function getLogo($obj)
    {
        $plugin_company = new Company();
        $company = $plugin_company->find(['entities_id' => $obj->entite[0]->fields['id']]);
        $company = reset($company);
        $doc = new Document();
        $dbu = new DbUtils();
        if ($company == false) {
            $companies = $plugin_company->find();
            foreach ($companies as $data) {
                if ($data['is_recursive'] == 1) {
                    $sons = $dbu->getSonsOf("glpi_entities", $data['entities_id']);
                    foreach ($sons as $son) {
                        if ($son == $obj->entite[0]->fields['id']) {
                            if ($doc->getFromDB($data["logo_id"])) {
                                return $doc->fields['filepath'];
                            }
                        }
                    }
                }
            }
        } else {
            if ($company["logo_id"] != 0) {
                $doc->getFromDB($company["logo_id"]);
                return $doc->fields['filepath'];
            }
        }
        return null;
    }

    /**
     * Returns company comments
     *
     * @param  $obj
     *
     * @return array|mixed[]
     */
    public static function getComment($obj)
    {
        $plugin_company = new Company();
        $company = $plugin_company->find(['entities_id' => $obj->entite[0]->fields['id']]);
        $company = reset($company);
        $dbu = new DbUtils();
        if ($company == false) {
            $companies = $plugin_company->find();
            foreach ($companies as $data) {
                if ($data['is_recursive'] == 1) {
                    $sons = $dbu->getSonsOf("glpi_entities", $data['entities_id']);
                    foreach ($sons as $son) {
                        if ($son == $obj->entite[0]->fields['id']) {
                            return $data['comment'];
                        }
                    }
                }
            }
        } else {
            return $company['comment'];
        }
        return null;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                        `id` int {$default_key_sign} NOT NULL auto_increment,
                        `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
                        `address` text collate utf8mb4_unicode_ci COMMENT 'address of the company shown on CRI',
                        `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0 COMMENT 'RELATION to glpi_entities (id)',
                        `is_recursive` tinyint NOT NULL DEFAULT 0,
                        `logo_id` int {$default_key_sign} DEFAULT 0 COMMENT 'RELATION to glpi_documents',
                        `comment` text collate utf8mb4_unicode_ci,
                        PRIMARY KEY  (`id`),
                        KEY `entities_id` (`entities_id`),
                        KEY `is_recursive` (`is_recursive`),
                        KEY `logo_id` (`logo_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
