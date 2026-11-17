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
use GlpiPlugin\Manageentities\Company;

/**
 * Companies saved without a logo
 */
class CompanyTest extends DbTestCase
{
    /**
     * Without an uploaded file, Company::addFiles() handed back the input: post_addItem() then
     * wrote its keys ("entities_id"…) into the integer logo_id column, which a strict SQL mode
     * rejects with "Incorrect integer value".
     */
    public function testACompanyIsAddedWithoutLogo(): void
    {
        $this->login();
        $entities_id = getItemByTypeName(\Entity::class, '_test_root_entity', true);

        $company = new Company();
        $id = $company->add([
            'name'         => 'Company without logo',
            'entities_id'  => $entities_id,
            'is_recursive' => 0,
            'address'      => 'Somewhere',
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertTrue($company->getFromDB($id));
        $this->assertSame(0, (int) $company->fields['logo_id']);
    }

    public function testACompanyWithoutLogoIsUpdatedWithoutFile(): void
    {
        $this->login();
        $entities_id = getItemByTypeName(\Entity::class, '_test_root_entity', true);

        $company = new Company();
        $id = $company->add([
            'name'         => 'Company to update',
            'entities_id'  => $entities_id,
            'is_recursive' => 0,
        ]);
        $this->assertGreaterThan(0, $id);

        // logo_id is still 0, so post_updateItem() goes through addFiles() too
        $this->assertTrue($company->update([
            'id'      => $id,
            'comment' => 'Updated without a logo',
        ]));
        $this->assertTrue($company->getFromDB($id));
        $this->assertSame(0, (int) $company->fields['logo_id']);
        $this->assertSame('Updated without a logo', $company->fields['comment']);
    }
}
