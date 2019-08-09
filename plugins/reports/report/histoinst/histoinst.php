<?php
/**
 * @version $Id: histoinst.php 346 2018-01-15 11:08:20Z yllen $
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
 @authors    Nelly Mahu-Lasson, Remi Collet
 @copyright Copyright (c) 2009-2018 Reports plugin team
 @license   AGPL License 3.0 or (at your option) any later version
            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 @link      https://forge.glpi-project.org/projects/reports
 @link      http://www.glpi-project.org/
 @since     2009
 --------------------------------------------------------------------------
 */

$USEDBREPLICATE        = 1;
$DBCONNECTION_REQUIRED = 1; // Really a big SQL request

include ("../../../../inc/includes.php");

includeLocales("histoinst");

Session::checkRight("plugin_reports_histoinst", READ);
$computer = new Computer();
$computer->checkGlobal(READ);
$software = new Software();
$software->checkGlobal(READ);

//TRANS: The name of the report = History of last software's installations
Html::header(__('histoinst_report_title', 'reports'), $_SERVER['PHP_SELF'], "utils", "report");

Report::title();

echo "<div class='center'>";
echo "<table class='tab_cadrehov' cellpadding='5'>\n";
echo "<tr class='tab_bg_1 center'>".
      "<th colspan='4'>" . __("History of last software's installations", "reports") .
      "</th></tr>\n";

echo "<tr class='tab_bg_2'><th>". __('Date of inventory', 'reports') . "</th>" .
      "<th>". __('User') . "</th>".
      "<th>". __("Computer's name") . "</th>".
      "<th>". sprintf(__('%1$s (%2$s)'), _n('Software', 'Software', 1), __('version'))."</th></tr>\n";

$sql = "SELECT  `glpi_logs`.`date_mod` AS dat, `linked_action`, `itemtype`, `itemtype_link`,
               `old_value`, `new_value`, `glpi_computers`.`id` AS cid, `name`, `user_name`,
               `items_id`, `entities_id`
        FROM `glpi_logs`
        LEFT JOIN `glpi_computers` ON (`glpi_logs`.`items_id` = `glpi_computers`.`id`)
        WHERE `glpi_logs`.`date_mod` > DATE_SUB(Now(), INTERVAL 21 DAY)
              AND `itemtype` = 'Computer'
              AND `linked_action` = '" .Log::HISTORY_INSTALL_SOFTWARE ."'
              AND `entities_id` = '" . $_SESSION["glpiactive_entity"] ."'
        ORDER BY `glpi_logs`.`id` DESC
        LIMIT 0,200";

$result = $DB->request($sql);

$prev = "";
$class = "tab_bg_2";
while ($data = $result->next()) {
   if (empty($data["name"])) {
      $data["name"] = "(".$data["cid"].")";
   }
   if ($prev == $data["dat"].$data["name"]) {
      echo "<br />";
   } else {
      if (!empty($prev)) {
         echo "</td></tr>\n";
      }
      $prev = $data["dat"].$data["name"];
      echo "<tr class='" . $class . " top'>".
            "<td class='center'>". Html::convDateTime($data["dat"]) . "</td>" .
            "<td>". $data["user_name"] . "&nbsp;</td>".
            "<td><a href='". Toolbox::getItemTypeFormURL('Computer') . "?id=" . $data["cid"]."'>" .
                  $data["name"] . "</a></td>".
            "<td>";
      $class = ($class=="tab_bg_2" ? "tab_bg_1" : "tab_bg_2");
   }
   echo $data["new_value"];
}

if (!empty($prev)) {
   echo "</td></tr>\n";
}
echo "</table><p>". __('The list is limited to 200 items and 21 days', 'reports')."</p></div>\n";

Html::footer();
