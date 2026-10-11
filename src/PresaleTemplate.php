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

use Document;
use Glpi\Exception\Http\NotFoundHttpException;
use Migration;
use RuntimeException;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Toolbox;
use ZipArchive;

/**
 * Word templates of the pre-sales documents: the proposal and the three kinds of annexes.
 *
 * No template is shipped with the plugin: each one is uploaded in the configuration and stored
 * in files/_plugins/manageentities/presale, under the name of its kind (annex.docx...). The
 * placeholders they can hold are listed in docs/avant-vente.md. The files are not GLPI
 * documents: they are only read by the generator and downloaded from the configuration.
 */
class PresaleTemplate
{
    /** Uploaded templates, GLPI_PLUGIN_DOC_DIR relative */
    private const DIR = '/manageentities/presale';

    /** Largest template accepted, in bytes */
    private const MAX_SIZE = 10 * 1024 * 1024;

    public const PROPOSAL           = 'proposal';
    public const ANNEX              = 'annex';
    public const SUBSCRIPTION_ANNEX = 'subscription_annex';
    public const CLOUD_ANNEX        = 'subscription_cloud_annex';

    /**
     * @return array<string, string> label of each kind of template
     */
    public static function getKinds(): array
    {
        return [
            self::PROPOSAL           => __('Word template of the proposals', 'manageentities'),
            self::ANNEX              => __('Word template of the annexes', 'manageentities'),
            self::SUBSCRIPTION_ANNEX => __('Word template of the subscription annexes', 'manageentities'),
            self::CLOUD_ANNEX        => __('Word template of the cloud subscription annexes', 'manageentities'),
        ];
    }

    /**
     * What a template of each kind is for, shown under its field
     *
     * @return array<string, string>
     */
    public static function getHelpers(): array
    {
        return [
            self::PROPOSAL           => __('Cover, presentation and signatures of the proposal.', 'manageentities'),
            self::ANNEX              => __('Annexes priced in days at a daily rate.', 'manageentities'),
            self::SUBSCRIPTION_ANNEX => __('Annexes of an on premise subscription: quantity and price, no daily rate column.', 'manageentities'),
            self::CLOUD_ANNEX        => __('Annexes of a cloud subscription: monthly price per subscription, number of subscriptions, number of months.', 'manageentities'),
        ];
    }

    private static function getDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . self::DIR;
    }

    /**
     * File of a kind of template. The name comes from this fixed list, never from the kind
     * itself: a kind may come from a request, and must not be able to build a path.
     *
     * @throws RuntimeException for an unknown kind
     */
    private static function getUploadedFile(string $kind): string
    {
        $name = match ($kind) {
            self::PROPOSAL           => 'proposal.docx',
            self::ANNEX              => 'annex.docx',
            self::SUBSCRIPTION_ANNEX => 'subscription_annex.docx',
            self::CLOUD_ANNEX        => 'subscription_cloud_annex.docx',
            default                  => throw new RuntimeException('Unknown kind of template'),
        };

        return self::getDir() . '/' . $name;
    }

    public static function isUploaded(string $kind): bool
    {
        return isset(self::getKinds()[$kind]) && is_file(self::getUploadedFile($kind));
    }

    /**
     * File of an uploaded template
     *
     * @throws RuntimeException when it is not uploaded yet
     */
    public static function getFile(string $kind): string
    {
        if (!self::isUploaded($kind)) {
            throw new RuntimeException(sprintf(
                __('%s: no template uploaded in the configuration (Pre-sales tab).', 'manageentities'),
                self::getKinds()[$kind] ?? $kind,
            ));
        }
        return self::getUploadedFile($kind);
    }

    /**
     * Labels of the templates that are not uploaded yet, among the ones given
     *
     * @param list<string> $kinds
     *
     * @return list<string>
     */
    public static function getMissing(array $kinds): array
    {
        $labels  = self::getKinds();
        $missing = [];
        foreach (array_unique($kinds) as $kind) {
            if (!self::isUploaded($kind)) {
                $missing[] = $labels[$kind] ?? $kind;
            }
        }

        return $missing;
    }

    /**
     * Stores the templates uploaded with the configuration form ($_FILES['presale_template'])
     * and removes the ones asked for ($input['presale_template_delete'][kind])
     *
     * @param array $files $_FILES
     * @param array $input $_POST
     */
    public static function handleUpload(array $files, array $input): void
    {
        $kinds  = self::getKinds();
        $delete = (array) ($input['presale_template_delete'] ?? []);
        // The known kinds drive the loop: the posted keys only say which ones to delete
        foreach (array_keys($kinds) as $kind) {
            if (isset($delete[$kind]) && self::isUploaded($kind)) {
                @unlink(self::getUploadedFile($kind));
                Session::addMessageAfterRedirect(sprintf(
                    __('%s: template deleted.', 'manageentities'),
                    $kinds[$kind],
                ));
            }
        }

        $upload = $files['presale_template'] ?? null;
        if (!is_array($upload) || !is_array($upload['error'] ?? null)) {
            return;
        }
        foreach ($kinds as $kind => $label) {
            $error = (int) ($upload['error'][$kind] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $message = self::store(
                $kind,
                $error,
                (string) ($upload['tmp_name'][$kind] ?? ''),
                (int) ($upload['size'][$kind] ?? 0),
            );
            Session::addMessageAfterRedirect(
                sprintf('%s: %s', $label, $message ?? __('template saved.', 'manageentities')),
                false,
                $message === null ? INFO : ERROR,
            );
        }
    }

    /**
     * @return string|null error message, null when stored
     */
    private static function store(string $kind, int $error, string $tmp_name, int $size): ?string
    {
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_name)) {
            return __('the file could not be uploaded.', 'manageentities');
        }
        if ($size > self::MAX_SIZE) {
            return __('the file is too large (10 MB at most).', 'manageentities');
        }
        if (!self::isDocx($tmp_name)) {
            return __('the file is not a Word document (.docx).', 'manageentities');
        }
        if (!is_dir(self::getDir()) && !@mkdir(self::getDir(), 0o755, true)) {
            return __('the templates folder could not be created.', 'manageentities');
        }
        if (!move_uploaded_file($tmp_name, self::getUploadedFile($kind))) {
            return __('the file could not be saved.', 'manageentities');
        }

        return null;
    }

    /** A .docx is a zip archive holding word/document.xml */
    private static function isDocx(string $file): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }
        $valid = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $valid;
    }

    /**
     * Download of a template from the configuration (front/presaletemplate.send.php)
     */
    public static function getDownloadResponse(string $kind): Response
    {
        if (!self::isUploaded($kind)) {
            throw new NotFoundHttpException();
        }

        return Toolbox::getFileAsResponse(
            self::getFile($kind),
            $kind . '.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
    }

    /**
     * Pre-sales tab of the configuration, where the templates are uploaded
     */
    public static function getConfigUrl(): string
    {
        return Config::getFormURLWithID(1) . '&forcetab=' . urlencode(PresaleConfig::class . '$1');
    }

    public static function getDownloadUrl(string $kind): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presaletemplate.send.php?kind=' . urlencode($kind);
    }

    /**
     * Templates chosen as GLPI documents by the first versions of the configuration: copied once
     * into the templates folder, then their configuration fields are dropped
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        $table   = Config::getTable();
        $columns = [
            self::PROPOSAL           => 'presale_template_documents_id',
            self::ANNEX              => 'presale_annex_template_documents_id',
            self::SUBSCRIPTION_ANNEX => 'presale_subscription_template_documents_id',
        ];
        if (!is_dir(self::getDir())) {
            @mkdir(self::getDir(), 0o755, true);
        }

        $dropped = false;
        foreach ($columns as $kind => $column) {
            if (!$DB->fieldExists($table, $column)) {
                continue;
            }
            $config   = $DB->request(['SELECT' => [$column], 'FROM' => $table, 'WHERE' => ['id' => 1]])->current();
            $document = new Document();
            if (
                !is_file(self::getUploadedFile($kind))
                && (int) ($config[$column] ?? 0) > 0
                && $document->getFromDB((int) $config[$column])
                && is_file(GLPI_DOC_DIR . '/' . $document->fields['filepath'])
            ) {
                copy(GLPI_DOC_DIR . '/' . $document->fields['filepath'], self::getUploadedFile($kind));
            }
            $migration->dropField($table, $column);
            $dropped = true;
        }
        if ($dropped) {
            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall(): void
    {
        foreach (array_keys(self::getKinds()) as $kind) {
            @unlink(self::getUploadedFile($kind));
        }
        @rmdir(self::getDir());
    }
}
