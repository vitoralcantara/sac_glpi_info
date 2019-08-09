<?php
/**
 * @version $Id: report.class.php 370 2019-03-20 13:53:33Z yllen $
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
 @authors    Nelly Mahu-Lasson, Remi Collet, Dévi Balpe
 @copyright Copyright (c) 2009-2019 Reports plugin team
 @license   AGPL License 3.0 or (at your option) any later version
            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 @link      https://forge.glpi-project.org/projects/reports
 @link      http://www.glpi-project.org/
 @since     2009
 --------------------------------------------------------------------------
 */

class PluginReportsReport extends CommonDBTM {


   /**
    * Return the localized name of the current Type
    * Shoudl be overloaded in each new class
    *
    * @param $nb  integer  for singular / plural
    *
    * @return string
    */
   static function getTypeName($nb=0) {
      return _n('Report', 'Reports', $nb);
   }

   /**
    * Get rights for an item _ may be overload by object
    *
    * @since version 0.85
    *
    * @param $interface   string   (defalt 'central')
    *
    * @return array of rights to display
   **/
   function getRights($interface='central') {
      return [READ => __('Read')];
   }
}
