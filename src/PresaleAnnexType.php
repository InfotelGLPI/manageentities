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

use CommonDropdown;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Features\Clonable;
use MassiveAction;
use Migration;
use Session;

/**
 * Annex models of the pre-sales proposals (title, purpose, details, VAT rate). An annex can
 * describe a publisher subscription: its level then prefills the subscription of the customer
 * once the proposal is won.
 */
class PresaleAnnexType extends CommonDropdown
{
    /** @use Clonable<static> */
    use Clonable;

    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Annex model', 'Annex models', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-file-description';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public function getAdditionalFields(): array
    {
        // Kind of annex first: it decides the fields below (no estimate for a subscription)
        $fields = [
            [
                'name'  => 'is_active',
                'label' => __('Active'),
                'type'  => 'bool',
                'list'  => true,
            ],
        ];
        if (Config::useEditorSubscriptions()) {
            $fields[] = [
                'name'        => 'is_subscription',
                'label'       => __('Publisher subscription annex', 'manageentities'),
                'type'        => 'bool',
                'list'        => true,
                // Shows or hides the default estimate (public/scripts/presale-wizard.js)
                'form_params' => ['on_change' => CriDetail::CHANGE_EVENT_JS],
            ];
            $fields[] = [
                'name'  => 'plugin_manageentities_subscriptionlevels_id',
                'label' => SubscriptionLevel::getTypeName(1),
                'type'  => 'dropdownValue',
                'list'  => true,
            ];
        }

        $is_subscription = !empty($this->fields['is_subscription']);

        return array_merge($fields, [
            [
                'name'        => 'internal_name',
                'label'       => __('Internal name', 'manageentities'),
                'type'        => 'text',
                'list'        => true,
                // Names the proposal (type of contract, internal label), e.g. "Expertise"
                'form_params' => [
                    'helper' => __('Names the proposals holding this annex (type of contract and internal label, e.g. "Client - Expertise & Souscription"). Empty: the first word of the name of the model.', 'manageentities'),
                ],
            ],
            [
                'name'  => 'objet',
                'label' => __('Purpose', 'manageentities'),
                'type'  => 'tinymce',
                'list'  => false,
            ],
            [
                'name'  => 'description',
                'label' => __('Details', 'manageentities'),
                'type'  => 'tinymce',
                'list'  => false,
            ],
            [
                'name'  => 'default_vat',
                'label' => __('VAT rate (%)', 'manageentities'),
                'type'  => 'vat_rate',
                'list'  => true,
            ],
            [
                'name'        => 'default_estimate',
                'label'       => __('Default estimate', 'manageentities'),
                'type'        => 'default_estimate',
                'list'        => false,
                // Full width, like the rich text fields above; regular annexes only
                'form_params' => [
                    'full_width'        => true,
                    'label_class'       => 'col-xxl-2',
                    'input_class'       => 'col-xxl-10',
                    'add_field_attribs' => ['data-presale-regular-only' => '1'],
                    'add_field_class'   => $is_subscription ? 'd-none' : '',
                ],
            ],
            [
                'name'        => 'default_lines',
                'label'       => __('Default lines', 'manageentities'),
                'type'        => 'default_lines',
                'list'        => false,
                'form_params' => ['full_width' => true, 'label_class' => 'col-xxl-2', 'input_class' => 'col-xxl-10'],
            ],
        ], $this->isNewItem() ? [] : [
            [
                'name'  => 'export_json',
                'label' => __('Export', 'manageentities'),
                'type'  => 'export_json',
                'list'  => false,
            ],
        ]);
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = []): void
    {
        if ($field['type'] === 'vat_rate') {
            echo \Html::input('default_vat', [
                'value' => $this->fields['default_vat'] ?? Config::getDefaultPresaleVat(),
                'type'  => 'number',
                'min'   => 0,
                'max'   => 100,
                'step'  => '0.01',
            ]);
        } elseif ($field['type'] === 'default_lines') {
            TemplateRenderer::getInstance()->display('@manageentities/presale/annextype_default_lines.html.twig', [
                'lines' => self::getDefaultLines($this->fields),
                // Columns of the wizard: days, quantity, or subscriptions × monthly price × months
                'mode'  => $this->isNewItem() ? PresalePricing::MODE_DAYS : self::getPricingMode($this->fields),
                'level_price' => $this->isNewItem() ? null : self::getCloudPrice($this->fields),
            ]);
        } elseif ($field['type'] === 'export_json') {
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
                . htmlescape(PresaleAnnexTypeTransfer::getExportUrl([(int) $ID])) . '">'
                . '<i class="ti ti-file-export me-1"></i>' . __s('Export as JSON', 'manageentities') . '</a>';
        } elseif ($field['type'] === 'default_estimate') {
            $rows = PresaleEstimate::decode($this->fields['default_estimate'] ?? null);
            TemplateRenderer::getInstance()->display('@manageentities/presale/estimate_table.html.twig', [
                'prefix' => 'default_estimate',
                'rows'   => $rows,
                'total'  => PresaleEstimate::formatTotal($rows),
                'helper' => __('Estimate of a new annex of this model in the pre-sales wizard, where it can be changed. Printed under the purpose (placeholder {{CHIFFRAGE}} of the annex template). Not used by the subscription annexes.', 'manageentities'),
            ]);
        }
    }

    /**
     * A new model is active, with the default VAT rate of the configuration: the form posts
     * the values it shows, the database defaults never apply
     */
    public function post_getEmpty()
    {
        parent::post_getEmpty();
        $this->fields['is_active']   = 1;
        $this->fields['default_vat'] = Config::getDefaultPresaleVat();
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareEstimate($this->prepareDefaultLines($this->prepareVatInput(parent::prepareInputForAdd($input))));
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareEstimate($this->prepareDefaultLines($this->prepareVatInput(parent::prepareInputForUpdate($input))));
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (static::canView()) {
            $actions[self::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'export_json']
                = '<i class="ti ti-file-export"></i>' . __s('Export as JSON', 'manageentities');
        }

        return $actions;
    }

    /**
     * Export: a download, not an action applied to each item. The sub-form opens the JSON of
     * the checked models (front/presaleannextype.transfer.php) instead of posting the action.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() === 'export_json') {
            $ids = array_keys($ma->getItems()[self::class] ?? []);
            echo '<a class="btn btn-primary" href="' . htmlescape(PresaleAnnexTypeTransfer::getExportUrl($ids)) . '">'
                . '<i class="ti ti-download me-1"></i>' . __s('Download the JSON file', 'manageentities') . '</a>';
            if (static::canCreate()) {
                echo ' <a class="btn btn-ghost-secondary" href="' . htmlescape(PresaleAnnexTypeTransfer::getImportUrl()) . '">'
                    . '<i class="ti ti-file-import me-1"></i>' . __s('Import annex models', 'manageentities') . '</a>';
            }
            return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    public function getCloneRelations(): array
    {
        return [];
    }

    /**
     * The clone carries the stored JSON of the default lines: decode it to the layout posted
     * by the form, which prepareDefaultLines() expects, or the clone would lose them
     */
    public function prepareInputForClone($input)
    {
        if (array_key_exists('default_lines', $input)) {
            $input['default_lines'] = self::getDefaultLines($input);
        }
        if (array_key_exists('default_estimate', $input)) {
            $input['default_estimate'] = PresaleEstimate::decode($input['default_estimate']);
        }

        return $input;
    }

    /**
     * Lines a new annex of this model starts with in the wizard, where they can be changed
     *
     * @param array $model row of the model
     *
     * @return array<int, array{designation: string, quantity: string, unit_price: string, months: string}>
     */
    public static function getDefaultLines(array $model): array
    {
        $lines = json_decode((string) ($model['default_lines'] ?? ''), true);
        if (!is_array($lines)) {
            return [];
        }
        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $result[] = [
                'designation' => (string) ($line['designation'] ?? ''),
                'quantity'    => (string) ($line['quantity'] ?? ''),
                'unit_price'  => (string) ($line['unit_price'] ?? ''),
                'months'      => (string) ($line['months'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Default lines posted by the form: the empty ones dropped, numbers checked as in the
     * wizard (a default line may leave its quantity or its price empty, to be typed there)
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function prepareDefaultLines($input)
    {
        if (!is_array($input) || !array_key_exists('default_lines', $input)) {
            return $input;
        }
        $lines = [];
        foreach (is_array($input['default_lines']) ? $input['default_lines'] : [] as $line) {
            $line = [
                'designation' => trim((string) ($line['designation'] ?? '')),
                'quantity'    => trim((string) ($line['quantity'] ?? '')),
                'unit_price'  => trim((string) ($line['unit_price'] ?? '')),
                // Cloud subscription only: number of months
                'months'      => trim((string) ($line['months'] ?? '')),
            ];
            if ($line['designation'] === '') {
                continue;
            }
            if ($line['months'] === '') {
                unset($line['months']);
            }
            foreach (['quantity', 'unit_price', 'months'] as $amount) {
                if (!isset($line[$amount])) {
                    continue;
                }
                if ($line[$amount] !== '' && PresalePricing::parseNumber($line[$amount]) === null) {
                    Session::addMessageAfterRedirect(
                        sprintf(__('Default line "%s": invalid number', 'manageentities'), $line['designation']),
                        false,
                        ERROR,
                    );
                    return false;
                }
            }
            $lines[] = $line;
        }
        // Cloud subscription: the monthly price is the one of its level, never typed
        $level_price = self::getCloudPrice($input + $this->fields);
        if ($level_price !== null) {
            foreach ($lines as &$line) {
                $line['unit_price'] = (string) $level_price;
            }
            unset($line);
        }
        $input['default_lines'] = $lines === [] ? null : json_encode($lines, JSON_UNESCAPED_UNICODE);

        return $input;
    }

    /**
     * Default estimate posted by the form, stored as JSON
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function prepareEstimate($input)
    {
        if (!is_array($input) || !array_key_exists('default_estimate', $input)) {
            return $input;
        }
        // A subscription annex has no estimate in days
        if (!empty($input['is_subscription'] ?? $this->fields['is_subscription'] ?? 0)) {
            $input['default_estimate'] = null;
            return $input;
        }
        $checked = PresaleEstimate::validate($input['default_estimate']);
        if ($checked['errors'] !== []) {
            foreach ($checked['errors'] as $message) {
                Session::addMessageAfterRedirect($message, false, ERROR);
            }
            return false;
        }
        $input['default_estimate'] = PresaleEstimate::encode($checked['rows']);

        return $input;
    }

    /**
     * Bound the VAT rate to 0-100, French decimal comma accepted
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function prepareVatInput($input)
    {
        if (!is_array($input) || !isset($input['default_vat'])) {
            return $input;
        }
        $rate = str_replace(',', '.', trim((string) $input['default_vat']));
        if ($rate === '') {
            $rate = '0';
        }
        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            Session::addMessageAfterRedirect(__('The VAT rate must be between 0 and 100.', 'manageentities'), false, ERROR);
            return false;
        }
        $input['default_vat'] = round((float) $rate, 2);

        return $input;
    }

    /**
     * How the lines of the annexes of a model are priced (PresalePricing::MODE_*): days for a
     * regular annex; for a subscription, subscriptions × monthly price × months when its level
     * is a cloud one, a quantity at a price otherwise
     *
     * @param array $model row of the model
     */
    public static function getPricingMode(array $model): string
    {
        return PresalePricing::getMode(!empty($model['is_subscription']), self::isCloud($model));
    }

    /**
     * Whether the subscription level of a model is a cloud one
     *
     * @param array $model row of the model
     */
    /**
     * Monthly price per subscription of the cloud level of a model, null when none is set
     *
     * @param array $model row of the model
     */
    public static function getCloudPrice(array $model): ?float
    {
        if (!self::isCloud($model)) {
            return null;
        }
        $level = new SubscriptionLevel();
        $level->getFromDB((int) $model['plugin_manageentities_subscriptionlevels_id']);

        return $level->fields['price_per_user'] !== null ? (float) $level->fields['price_per_user'] : null;
    }

    public static function isCloud(array $model): bool
    {
        if (empty($model['is_subscription'])) {
            return false;
        }
        $level = new SubscriptionLevel();
        return $level->getFromDB((int) $model['plugin_manageentities_subscriptionlevels_id'])
            && (int) $level->fields['subscription_type'] === SubscriptionLevel::TYPE_CLOUD;
    }

    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '14',
            'table'    => $this->getTable(),
            'field'    => 'internal_name',
            'name'     => __('Internal name', 'manageentities'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => '10',
            'table'    => $this->getTable(),
            'field'    => 'default_vat',
            'name'     => __('VAT rate (%)', 'manageentities'),
            'datatype' => 'decimal',
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '12',
            'table'    => $this->getTable(),
            'field'    => 'is_subscription',
            'name'     => __('Publisher subscription annex', 'manageentities'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '13',
            'table'    => SubscriptionLevel::getTable(),
            'field'    => 'name',
            'name'     => SubscriptionLevel::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `name` varchar(255) DEFAULT NULL,
                            `comment` text,
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `is_recursive` tinyint NOT NULL DEFAULT '1',
                            `objet` mediumtext,
                            `description` mediumtext,
                            `default_vat` decimal(5,2) NOT NULL DEFAULT '20.00',
                            `default_lines` text COMMENT 'JSON list of designation, quantity, unit_price prefilled in the wizard',
                            `default_estimate` text COMMENT 'JSON estimate prefilled in the wizard: [{type: title|line, label, days}]',
                            `internal_name` varchar(255) DEFAULT NULL COMMENT 'names the proposals holding this annex',
                            `is_subscription` tinyint NOT NULL DEFAULT '0',
                            `plugin_manageentities_subscriptionlevels_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_subscriptionlevels (id)',
                            `is_active` tinyint NOT NULL DEFAULT '1',
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `name` (`name`),
                            KEY `entities_id` (`entities_id`),
                            KEY `is_recursive` (`is_recursive`),
                            KEY `is_active` (`is_active`),
                            KEY `plugin_manageentities_subscriptionlevels_id` (`plugin_manageentities_subscriptionlevels_id`),
                            KEY `date_creation` (`date_creation`),
                            KEY `date_mod` (`date_mod`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Default lines of the annexes, prefilled in the wizard (added after the first release)
        if (!$DB->fieldExists($table, 'default_lines')) {
            $migration->addField($table, 'default_lines', 'text', [
                'after'   => 'default_vat',
                'comment' => 'JSON list of designation, quantity, unit_price prefilled in the wizard',
            ]);
            $migration->migrationOneTable($table);
        }
        // Internal name, naming the proposals (added after the first release)
        if (!$DB->fieldExists($table, 'internal_name')) {
            $migration->addField($table, 'internal_name', 'string', [
                'after'   => 'name',
                'comment' => 'names the proposals holding this annex',
            ]);
            $migration->migrationOneTable($table);
        }
        // Default estimate of the regular annexes (added after the first release)
        if (!$DB->fieldExists($table, 'default_estimate')) {
            $migration->addField($table, 'default_estimate', 'text', [
                'after'   => 'default_lines',
                'comment' => 'JSON estimate prefilled in the wizard: [{type: title|line, label, days}]',
            ]);
            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
