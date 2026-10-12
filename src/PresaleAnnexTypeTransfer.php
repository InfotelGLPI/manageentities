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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Presale\Docx\RichText;
use Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export and import of the annex models as JSON, to move them from one GLPI to another.
 *
 * A file holds a format, its version and the models. A model carries its texts, VAT rate,
 * default lines and estimate, and the NAME of its subscription level: ids differ from one base
 * to the other. The images of the texts point to documents of the base they come from: they
 * are dropped at the import. An imported model whose name already exists is created as a copy.
 */
class PresaleAnnexTypeTransfer
{
    public const FORMAT  = 'glpi-manageentities-annex-models';
    public const VERSION = 1;

    /** Largest file accepted, in bytes */
    private const MAX_SIZE = 5 * 1024 * 1024;

    /** Fields of a model carried by the file, as stored */
    private const FIELDS = ['name', 'internal_name', 'comment', 'is_active', 'objet', 'description', 'default_vat', 'is_subscription'];

    public static function getExportUrl(array $ids): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presaleannextype.transfer.php?' . http_build_query(['export' => array_values($ids)]);
    }

    public static function getImportUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presaleannextype.transfer.php';
    }

    /**
     * JSON file of the models the user can read, the others skipped
     *
     * @param list<int> $ids
     */
    public static function getExportResponse(array $ids): Response
    {
        $models = [];
        $model  = new PresaleAnnexType();
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (!$model->can($id, READ)) {
                continue;
            }
            $models[] = self::export($model->fields);
        }

        $json = json_encode([
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => date('c'),
            'models'      => $models,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $filename = count($models) === 1
            ? 'annex-model-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $models[0]['name']) . '.json'
            : 'annex-models.json';

        return new Response((string) $json, 200, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . trim($filename, '-') . '"',
        ]);
    }

    /**
     * One model as written in the file
     *
     * @param array $fields row of the model
     */
    private static function export(array $fields): array
    {
        $model = [];
        foreach (self::FIELDS as $field) {
            $model[$field] = $fields[$field] ?? null;
        }
        $model['is_active']        = (bool) $model['is_active'];
        $model['is_subscription']  = (bool) $model['is_subscription'];
        $model['default_vat']      = (float) $model['default_vat'];
        $model['default_lines']    = PresaleAnnexType::getDefaultLines($fields);
        $model['default_estimate'] = PresaleEstimate::decode($fields['default_estimate'] ?? null);
        $model['subscription_level'] = (int) $fields['plugin_manageentities_subscriptionlevels_id'] > 0
            ? \Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int) $fields['plugin_manageentities_subscriptionlevels_id'])
            : null;

        return $model;
    }

    public static function showImportForm(): void
    {
        TemplateRenderer::getInstance()->display('@manageentities/presale/annextype_import.html.twig', [
            'form_url'   => self::getImportUrl(),
            'models_url' => PresaleAnnexType::getSearchURL(),
            'max_size'   => self::MAX_SIZE,
        ]);
    }

    /**
     * Imports the models of the uploaded file in the active entity, each one as a new model
     * (a copy when the name exists), with a message per model
     *
     * @param array|null $file entry of $_FILES
     */
    public static function import(?array $file): void
    {
        $data = self::read($file);
        if (is_string($data)) {
            Session::addMessageAfterRedirect($data, false, ERROR);
            return;
        }

        $imported = 0;
        foreach ($data['models'] as $rank => $model) {
            if (!is_array($model) || trim((string) ($model['name'] ?? '')) === '') {
                Session::addMessageAfterRedirect(sprintf(__('Model #%d: no name, skipped.', 'manageentities'), $rank + 1), false, WARNING);
                continue;
            }
            if (self::importModel($model)) {
                $imported++;
            }
        }
        Session::addMessageAfterRedirect(sprintf(
            _n('%d annex model imported.', '%d annex models imported.', $imported, 'manageentities'),
            $imported,
        ), false, $imported > 0 ? INFO : WARNING);
    }

    /**
     * Content of the uploaded file, or the error message
     *
     * @return array{models: list<mixed>}|string
     */
    private static function read(?array $file): array|string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return __('Choose the JSON file to import.', 'manageentities');
        }
        if ((int) $file['size'] > self::MAX_SIZE) {
            return __('The file is too large (5 MB at most).', 'manageentities');
        }
        $data = json_decode((string) file_get_contents($file['tmp_name']), true);
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT || !is_array($data['models'] ?? null)) {
            return __('This file is not an export of annex models.', 'manageentities');
        }
        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            return __('This file comes from a more recent version of the plugin.', 'manageentities');
        }

        return ['models' => array_values($data['models'])];
    }

    /**
     * Creates one model through PresaleAnnexType::add(): same checks as its form
     */
    private static function importModel(array $model): bool
    {
        $name  = trim((string) $model['name']);
        $label = $name;

        $input = [
            'name'             => self::freeName($name),
            'internal_name'    => (string) ($model['internal_name'] ?? ''),
            'comment'          => (string) ($model['comment'] ?? ''),
            'is_active'        => !empty($model['is_active']) ? 1 : 0,
            'objet'            => self::withoutImages((string) ($model['objet'] ?? ''), $label),
            'description'      => self::withoutImages((string) ($model['description'] ?? ''), $label),
            'default_vat'      => (string) ($model['default_vat'] ?? Config::getDefaultPresaleVat()),
            'is_subscription'  => !empty($model['is_subscription']) ? 1 : 0,
            'default_lines'    => is_array($model['default_lines'] ?? null) ? $model['default_lines'] : [],
            'default_estimate' => is_array($model['default_estimate'] ?? null) ? $model['default_estimate'] : [],
            'entities_id'      => Session::getActiveEntity(),
            'is_recursive'     => 1,
        ];

        // Subscription level found by its name
        $level_name = trim((string) ($model['subscription_level'] ?? ''));
        if ($input['is_subscription'] && $level_name !== '') {
            $level = (new SubscriptionLevel())->find(['name' => $level_name], [], 1);
            if ($level !== []) {
                $input['plugin_manageentities_subscriptionlevels_id'] = (int) array_key_first($level);
            } else {
                Session::addMessageAfterRedirect(sprintf(
                    __('%1$s: subscription level "%2$s" unknown here, imported without level.', 'manageentities'),
                    $label,
                    $level_name,
                ), false, WARNING);
            }
        }

        $item = new PresaleAnnexType();
        if (!$item->can(-1, CREATE, $input) || !$item->add($input)) {
            Session::addMessageAfterRedirect(sprintf(__('%s: the model could not be imported.', 'manageentities'), $label), false, ERROR);
            return false;
        }
        if ($input['name'] !== $name) {
            Session::addMessageAfterRedirect(sprintf(
                __('%1$s: a model of this name exists, imported as "%2$s".', 'manageentities'),
                $label,
                $input['name'],
            ), false, INFO);
        }

        return true;
    }

    /**
     * The name, or "name (copy)", "name (copy 2)"... when a model already has it
     */
    private static function freeName(string $name): string
    {
        $candidate = $name;
        for ($n = 1; countElementsInTable(PresaleAnnexType::getTable(), ['name' => $candidate]) > 0; $n++) {
            $candidate = $n === 1
                ? sprintf(__('%s (copy)', 'manageentities'), $name)
                : sprintf(__('%1$s (copy %2$d)', 'manageentities'), $name, $n);
        }

        return $candidate;
    }

    /**
     * Rich text without its images: they point to documents of the other base
     */
    private static function withoutImages(string $html, string $label): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }
        Session::addMessageAfterRedirect(sprintf(
            __('%s: the images of the texts are not imported, add them again in the model.', 'manageentities'),
            $label,
        ), false, WARNING);

        return (string) preg_replace(['#<a\b[^>]*>\s*<img\b[^>]*>\s*</a>#i', '#<img\b[^>]*>#i'], '', $html);
    }
}
