--
-- -------------------------------------------------------------------------
-- manageentities plugin for GLPI
-- Copyright (C) 2017-2026 by the manageentities Development Team.
--
-- https://github.com/InfotelGLPI/manageentities
-- -------------------------------------------------------------------------
--
-- LICENSE
--
-- This file is part of manageentities.
--
-- manageentities is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- manageentities is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with manageentities. If not, see <http://www.gnu.org/licenses/>.
-- --------------------------------------------------------------------------
--

-- Standard entity columns on the companies table.
--
-- The table has always carried "entity_id" (a TEXT column) and "recursive" instead of the
-- canonical entities_id / is_recursive, so CommonDBTM::isEntityAssign() and maybeRecursive()
-- both returned false: checkEntity() was a no-op, getEntitiesRestrictCriteria() never applied
-- and the search engine added no boundary of its own. The plugin compensated by hand, in the
-- class and in a search hook. The columns are renamed so the framework mechanisms apply.

ALTER TABLE `glpi_plugin_manageentities_companies`
   ADD `entities_id`  int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id)' AFTER `address`,
   ADD `is_recursive` tinyint NOT NULL DEFAULT '0' AFTER `entities_id`;

-- entity_id was declared TEXT, so a plain CAST would abort the statement under a strict
-- sql_mode as soon as one row holds something that is not a number (NULL, empty string, a
-- leftover label). Rows that never held a usable identifier land in the root entity, which is
-- what the (int) cast of the PHP code did with them until now.
UPDATE `glpi_plugin_manageentities_companies`
SET `entities_id`  = CASE WHEN `entity_id` REGEXP '^[0-9]+$' THEN CAST(`entity_id` AS UNSIGNED) ELSE 0 END,
    `is_recursive` = CASE WHEN `recursive` = 1 THEN 1 ELSE 0 END;

ALTER TABLE `glpi_plugin_manageentities_companies`
   DROP COLUMN `entity_id`,
   DROP COLUMN `recursive`,
   ADD KEY `entities_id` (`entities_id`),
   ADD KEY `is_recursive` (`is_recursive`);
