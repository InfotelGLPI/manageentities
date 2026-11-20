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

namespace GlpiPlugin\Manageentities\Tests\Integration;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Cri;

/**
 * Generation of an intervention report (the addCri action of ajax/cri.php)
 */
class CriGenerationTest extends DbTestCase
{
    /**
     * Generates the report of a ticket without contract, with the "Save reports in glpi" option
     * set as given, and returns what is sent back to the modal
     */
    private function generate(int $backup): string
    {
        global $DB;

        $this->login();
        $DB->update(Config::getTable(), ['backup' => $backup], ['id' => 1]);
        Config::getInstance()->fields['backup'] = $backup;

        $ticket = $this->createItem(\Ticket::class, [
            'name'        => 'Ticket with an intervention report',
            'content'     => 'Content',
            'entities_id' => getItemByTypeName(\Entity::class, '_test_root_entity', true),
        ]);
        $this->createItem(\TicketTask::class, [
            'tickets_id'    => $ticket->getID(),
            'content'       => 'Work done',
            'actiontime'    => 3600,
            'begin'         => '2026-11-17 09:00:00',
            'end'           => '2026-11-17 10:00:00',
            'users_id_tech' => \Session::getLoginUserID(),
        ]);

        // The test kernel boots no plugin: the namespace of the templates is registered here
        $loader  = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        $loaders = $loader instanceof \Twig\Loader\ChainLoader ? $loader->getLoaders() : [$loader];
        foreach ($loaders as $filesystem) {
            if ($filesystem instanceof \Twig\Loader\FilesystemLoader
                && !in_array('manageentities', $filesystem->getNamespaces(), true)) {
                $filesystem->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
            }
        }

        ob_start();
        try {
            (new Cri())->generatePdf((object) [
                'REPORT_ID'          => $ticket->getID(),
                'REPORT_DESCRIPTION' => 'Work done',
                'WITHOUTCONTRACT'    => 1,
                'REPORT_ACTIVITE'    => $ticket->fields['name'],
                'enregistrement'     => false,
            ], ['modal' => 'cri_modal', 'toupdate' => '']);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * "Save reports in glpi" off, the default of a fresh install: nothing at all was sent back,
     * so the generation button seemed to do nothing. The report is shown, without saving it.
     */
    public function testTheReportIsShownWhenReportsAreNotSaved(): void
    {
        $html = $this->generate(0);

        $this->assertStringContainsString('/front/cri.send.php?file=_plugins/manageentities/', $html);
        $this->assertStringNotContainsString('name="save_cri"', $html);
    }

    public function testTheReportCanBeSavedWhenReportsAreSaved(): void
    {
        $html = $this->generate(1);

        $this->assertStringContainsString('/front/cri.send.php?file=_plugins/manageentities/', $html);
        $this->assertStringContainsString('name="save_cri"', $html);
        $this->assertStringContainsString('data-manageentities-load-cri-form=', $html);
    }
}
