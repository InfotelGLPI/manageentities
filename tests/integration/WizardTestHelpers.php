<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities\Tests\Integration;

/**
 * Shared helpers for wizard integration tests.
 * Provides a minimal valid contract input array satisfying all required fields.
 */
trait WizardTestHelpers
{
    private int $wizard_contracttype_id = 0;
    private int $wizard_state_id        = 0;

    protected function setUpWizardContractTypes(): void
    {
        $uid = $this->getUniqueString();

        $ct = new \ContractType();
        $this->wizard_contracttype_id = (int)$ct->add(['name' => "CT-{$uid}", 'entities_id' => 0]);

        $st = new \State();
        $this->wizard_state_id = (int)$st->add(['name' => "St-{$uid}", 'entities_id' => 0]);
    }

    protected function minimalContractInput(array $override = []): array
    {
        return array_merge([
            'name'             => 'CTR-' . $this->getUniqueString(),
            'num'              => 'NUM-' . $this->getUniqueString(),
            'begin_date'       => '2026-01-01',
            'duration'         => 12,
            'contracttypes_id' => $this->wizard_contracttype_id,
            'states_id'        => $this->wizard_state_id,
        ], $override);
    }
}
