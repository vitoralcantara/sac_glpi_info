<?php
/**
 * @version $Id: usertitlecriteria.class.php 370 2019-03-20 13:53:33Z yllen $
 -------------------------------------------------------------------------
  LICENSE

 This file is part of Reports plugin for GLPI.

 Reports is free software: you can redistribute it and/or modify
 it under the terms of the GNU Affero General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 Reports is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU Affero General Public License for more details.

 You should have received a copy of the GNU Affero General Public License
 along with Reports. If not, see <http://www.gnu.org/licenses/>.

 @package   reports
 @authors    Nelly Mahu-Lasson, Remi Collet, Alexandre Delaunay
 @copyright Copyright (c) 2009-2019 Reports plugin team
 @license   AGPL License 3.0 or (at your option) any later version
            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 @link      https://forge.glpi-project.org/projects/reports
 @link      http://www.glpi-project.org/
 @since     2009
 --------------------------------------------------------------------------
 */

/**
 * User titles selection criteria
 */
class PluginReportsUserTitleCriteria extends PluginReportsDropdownCriteria {


   /**
    * @param $report
    * @param $name      (default usertitles_id)
    * @param $label     (default '')
   **/
   function __construct($report, $name='usertitles_id', $label='') {

      parent :: __construct($report, $name, 'glpi_usertitles',
                            ($label ? $label : _n('User title', 'Users titles', 1)));
   }

}
