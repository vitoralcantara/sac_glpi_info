<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 ocsinventoryng plugin for GLPI
 Copyright (C) 2015-2016 by the ocsinventoryng Development Team.

 https://github.com/pluginsGLPI/ocsinventoryng
 -------------------------------------------------------------------------

 LICENSE

 This file is part of ocsinventoryng.

 ocsinventoryng is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 ocsinventoryng is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with ocsinventoryng. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkRight("plugin_ocsinventoryng", UPDATE);

Html::header('OCSInventory NG', '', "tools", "pluginocsinventoryngmenu", "deleted_equiv");

global $CFG_GLPI;

echo "<div class='center'>";
$ocsClient = PluginOcsinventoryngOcsServer::getDBocs($_SESSION["plugin_ocsinventoryng_ocsservers_id"]);
if ($ocsClient->getConnectionType() == "PluginOcsinventoryngOcsSoapClient") {
   PluginOcsinventoryngOcsProcess::manageDeleted($_SESSION["plugin_ocsinventoryng_ocsservers_id"]);
   if ($_SESSION["ocs_deleted_equiv"]['computers_to_del']) {
      echo "<div class='center b'>" . $_SESSION["ocs_deleted_equiv"]['computers_deleted'] . " " . __('Pc deleted', 'ocsinventoryng');
      Html::redirect($_SERVER['PHP_SELF']);
   } else {
      if ($_SESSION["ocs_deleted_equiv"]['computers_deleted'] === 0) {
         echo "<div class='center b'>" . __('No new computers to delete', 'ocsinventoryng') . ".</div>";
      } else {
         echo "<div class='center b'>";
         echo "<table class='tab_cadre_fixe'>";
         echo "<th colspan='2'>" . __('Clean OCSNG deleted computers', 'ocsinventoryng');
         echo "</th>";
         echo "<tr class='tab_bg_1'><td>" . __('Pc deleted', 'ocsinventoryng') . "</td><td>" . $_SESSION["ocs_deleted_equiv"]['computers_deleted'] . "</td></tr>";
         echo "</table></div>";

      }
      echo "<a href='" . $CFG_GLPI["root_doc"] . "/plugins/ocsinventoryng/front/ocsng.php'>";
      echo __('Back');
      echo "</a>";
   }
} else {
   if (empty($_SESSION["ocs_deleted_equiv"]["total"])) {
      PluginOcsinventoryngOcsProcess::manageDeleted($_SESSION["plugin_ocsinventoryng_ocsservers_id"]);
   }
   if ($_SESSION["ocs_deleted_equiv"]["total"] != $_SESSION["ocs_deleted_equiv"]["deleted"] && $_SESSION["ocs_deleted_equiv"]["last_req"]) {

      echo $_SESSION["ocs_deleted_equiv"]["deleted"] . "/" . $_SESSION["ocs_deleted_equiv"]["total"];
      $count = $_SESSION["ocs_deleted_equiv"]["deleted"];
      $percent = min(100,
         round(100 * ($count) / $_SESSION["ocs_deleted_equiv"]["total"],
            0));
      PluginOcsinventoryngOcsProcess::manageDeleted($_SESSION["plugin_ocsinventoryng_ocsservers_id"]);
      Html::displayProgressBar(400, $percent, $param['forcepadding'] = true);
      Html::redirect($_SERVER['PHP_SELF']);
   } else {

      if ($_SESSION["ocs_deleted_equiv"]["total"] === 0) {
         echo "<div class='center b'>" . __('No new computers to delete', 'ocsinventoryng') . ".</div>";
      } else {
         $total = $_SESSION["ocs_deleted_equiv"]["total"];
         $_SESSION["ocs_deleted_equiv"]["total"] = 0;
         $count = $_SESSION["ocs_deleted_equiv"]["deleted"];
         $_SESSION["ocs_deleted_equiv"]["deleted"] = 0;
         echo "<div class='center b'>";
         echo "<table class='tab_cadre_fixe'>";
         echo "<th colspan='2'>" . __('Clean OCSNG deleted computers', 'ocsinventoryng');
         echo "</th>";
         echo "<tr class='tab_bg_1'><td>" . __('Pc deleted', 'ocsinventoryng') . "</td><td>" . $count . "/" . $total . "</td></tr>";
         echo "</table></div>";
      }
      echo "<a href='" . $CFG_GLPI["root_doc"] . "/plugins/ocsinventoryng/front/ocsng.php'>";
      echo __('Back');
      echo "</a>";
   }
}

echo "</div>";

Html::footer();
