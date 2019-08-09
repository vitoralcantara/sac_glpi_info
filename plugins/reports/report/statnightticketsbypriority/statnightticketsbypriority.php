<?php
/**
 * @version $Id: statnightticketsbypriority.php 380 2019-05-20 10:05:39Z yllen $
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
 @authors    Nelly Mahu-Lasson, Remi Collet, Benoit Machiavello
 @copyright Copyright (c) 2009-2018 Reports plugin team
 @license   AGPL License 3.0 or (at your option) any later version
            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 @link      https://forge.glpi-project.org/projects/reports
 @link      http://www.glpi-project.org/
 @since     2009
 --------------------------------------------------------------------------
 */

$USEDBREPLICATE         = 1;
$DBCONNECTION_REQUIRED  = 0;

include ("../../../../inc/includes.php");

$dbu = new DbUtils();

//TRANS: The name of the report = Tickets opened at night, sorted by priority
$report = new PluginReportsAutoReport(__('statnightticketsbypriority_report_title', 'reports'));

//Report's search criterias
new PluginReportsDateIntervalCriteria($report, '`glpi_tickets`.`date`', __('Opening date'));

$timeInterval = new PluginReportsTimeIntervalCriteria($report, '`glpi_tickets`.`date`');

//Criterias default values
$timeInterval->setStartTime($CFG_GLPI['planning_end']);
$timeInterval->setEndtime($CFG_GLPI['planning_begin']);

//Display criterias form is needed
$report->displayCriteriasForm();

//If criterias have been validated
if ($report->criteriasValidated()) {
   $report->setSubNameAuto();

   //Names of the columns to be displayed
   $report->setColumns([new PluginReportsColumnMap('priority', __('Priority'), [],
                                                   ['sorton' => '`priority`, `date`']),
                        new PluginReportsColumnDateTime('date', __('Opening date'),
                                                        ['sorton' => '`date`']),
                        new PluginReportsColumn('id2', __('ID')),
                        new PluginReportsColumnLink('id', __('Title'), 'Ticket'),
                        new PluginReportsColumn('groupname', __('Group'),
                                                ['sorton' => '`glpi_groups_tickets`.`groups_id`, `date`'])]);

   $query = "SELECT `glpi_tickets`.`priority`, `glpi_tickets`.`date` , `glpi_tickets`.`id`,
                    `glpi_tickets`.`id` AS id2, `glpi_groups`.`name` as groupname
             FROM `glpi_tickets`
             LEFT JOIN `glpi_groups_tickets`
                  ON (`glpi_groups_tickets`.`tickets_id` = `glpi_tickets`.`id`
                      AND `glpi_groups_tickets`.`type` = '".CommonITILActor::ASSIGN."')
             LEFT JOIN `glpi_groups` ON (`glpi_groups_tickets`.`groups_id` = `glpi_groups`.`id`)
             WHERE `glpi_tickets`.`status` NOT IN ('".implode("', '",
                                                              array_merge(Ticket::getSolvedStatusArray(),
                                                                          Ticket::getClosedStatusArray()))."')
                  AND NOT `glpi_tickets`.`is_deleted` ".
                  $report->addSqlCriteriasRestriction() .
                  $dbu->getEntitiesRestrictRequest(' AND ', 'glpi_tickets').
             $report->getOrderBy('priority');

   $report->setSqlRequest($query);
   $report->execute();
} else {
   Html::footer();
}
