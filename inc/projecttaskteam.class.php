<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2018 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

//!  ProjectTaskTeam Class
/**
 * This class is used to manage the project task team
 * @see ProjectTask
 * @author Julien Dombre
 * @since 0.85
 **/
class ProjectTaskTeam extends CommonDBRelation {

   // From CommonDBTM
   public $dohistory                  = true;
   public $no_form_page               = true;

   // From CommonDBRelation
   static public $itemtype_1          = 'ProjectTask';
   static public $items_id_1          = 'projecttasks_id';

   static public $itemtype_2          = 'itemtype';
   static public $items_id_2          = 'items_id';
   static public $checkItem_2_Rights  = self::DONT_CHECK_ITEM_RIGHTS;

   static public $available_types     = ['User', 'Group', 'Supplier', 'Contact'];


   /**
    * @see CommonDBTM::getNameField()
   **/
   static function getNameField() {
      return 'id';
   }


   static function getTypeName($nb = 0) {
      return _n('Task team', 'Task teams', $nb);
   }


   function getForbiddenStandardMassiveAction() {

      $forbidden   = parent::getForbiddenStandardMassiveAction();
      $forbidden[] = 'update';
      return $forbidden;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

      if (!$withtemplate && static::canView()) {
         $nb = 0;
         switch ($item->getType()) {
            case 'ProjectTask' :
               if ($_SESSION['glpishow_count_on_tabs']) {
                  $nb = $item->getTeamCount();
               }
               return self::createTabEntry(self::getTypeName(1), $nb);
         }
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      switch ($item->getType()) {
         case 'ProjectTask' :
            $item->showTeam($item);
            return true;
      }
   }


   /**
    * Get team for a project
    *
    * @param $projects_id
   **/
   static function getTeamFor($projects_id) {
      global $DB;

      $team = [];

      $iterator = $DB->request([
         'FROM'   => self::getTable(),
         'WHERE'  => ['projecttasks_id' => $projects_id]
      ]);

      while ($data = $iterator->next()) {
         if (!isset($team[$data['itemtype']])) {
            $team[$data['itemtype']] = [];
         }
         $team[$data['itemtype']][] = $data;
      }

      // Define empty types
      foreach (static::$available_types as $type) {
         if (!isset($team[$type])) {
            $team[$type] = [];
         }
      }

      return $team;
   }

}
