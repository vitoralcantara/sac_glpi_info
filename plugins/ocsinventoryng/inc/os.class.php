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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Class PluginOcsinventoryngOS
 */
class PluginOcsinventoryngOS extends CommonDBChild {

   // From CommonDBChild
   static public $itemtype = 'Computer';
   static public $items_id = 'computers_id';

   static $rightname = "plugin_ocsinventoryng";


   /**
    * Update lockable fields of an item
    *
    * @param $item                     CommonDBTM object
    *
    * @return void
    * @internal param int|string $withtemplate integer  withtemplate param (default '')
    */
   static function updateLockforOS($item) {

      $ocslink = new PluginOcsinventoryngOcslink();
      if ($item->fields["is_dynamic"]
          && $item->fields["itemtype"] == 'Computer'
          && $ocslink->getFromDBforComputer($item->fields["items_id"])
          && (count($item->updates) > 1)
          && (!isset($item->input["_nolock"]))) {

         $cfg_ocs = PluginOcsinventoryngOcsServer::getConfig($ocslink->fields["plugin_ocsinventoryng_ocsservers_id"]);
         if ($cfg_ocs["use_locks"]) {
            PluginOcsinventoryngOcslink::mergeOcsArray($item->fields["items_id"], $item->updates);
         }
      }
   }

   /**
    * @param int $plugin_ocsinventoryng_ocsservers_id
    *
    * @return array
    */
   static function getOSLockableFields($plugin_ocsinventoryng_ocsservers_id = 0) {

      if ($plugin_ocsinventoryng_ocsservers_id > 0) {

         $locks   = [];
         $cfg_ocs = PluginOcsinventoryngOcsServer::getConfig($plugin_ocsinventoryng_ocsservers_id);

         if (intval($cfg_ocs["import_general_os"]) > 0) {
            $locks["operatingsystems_id"]             = __('Operating system');
            $locks["operatingsystemservicepacks_id"]  = __('Service pack');
            $locks["operatingsystemversions_id"]      = __('Version of the operating system');
            $locks["operatingsystemarchitectures_id"] = __('Operating system architecture');//Enable 9.1
         }

         if (intval($cfg_ocs["import_os_serial"]) > 0) {
            $locks["license_number"] = __('Serial of the operating system');
            $locks["licenseid"]     = __('Product ID of the operating system');
         }

      } else {
         $locks = ["operatingsystems_id"             => __('Operating system'),
                   "operatingsystemservicepacks_id"  => __('Service pack'),
                   "operatingsystemversions_id"      => __('Version of the operating system'),
                   'operatingsystemarchitectures_id' => __('Operating system architecture'),//Enable 9.1
                   "license_number"                  => __('Serial of the operating system'),
                   "licenseid"                      => __('Product ID of the operating system')];
      }

      return $locks;

   }

   /**
    * @param array $options
    *
    * @return void
    * @throws \GlpitestSQLError
    */
   static function updateComputerOS($options = []) {
      global $DB;

      $is_utf8     = $options['cfg_ocs']["ocs_db_utf8"];
      $ocsServerId = $options['plugin_ocsinventoryng_ocsservers_id'];
      $force       = $options["force"];
      $cfg_ocs     = $options['cfg_ocs'];

      if (isset($options['HARDWARE'])) {


         $uninstall_history = 0;
         if ($cfg_ocs['dohistory'] == 1 && ($cfg_ocs['history_os'] == 1 || $cfg_ocs['history_os'] == 3)) {
            $uninstall_history = 1;
         }
         $install_history = 0;
         if ($cfg_ocs['dohistory'] == 1 && ($cfg_ocs['history_os'] == 1 || $cfg_ocs['history_os'] == 2)) {
            $install_history = 1;
         }

         $hardware = Toolbox::clean_cross_side_scripting_deep(Toolbox::addslashes_deep($options['HARDWARE']));

         if ($options['check_history']) {
            $sql_computer = "SELECT `glpi_operatingsystems`.`name` AS os_name,
                                    `glpi_operatingsystemservicepacks`.`name` AS os_sp
                             FROM `glpi_computers`
                           LEFT JOIN `glpi_plugin_ocsinventoryng_ocslinks`
                           ON `glpi_plugin_ocsinventoryng_ocslinks`.`computers_id` = `glpi_computers`.`id`
                           LEFT JOIN `glpi_items_operatingsystems`
                           ON (`glpi_computers`.`id` = `glpi_items_operatingsystems`.`items_id` AND `glpi_items_operatingsystems`.`itemtype` = 'Computer')
                           LEFT JOIN `glpi_operatingsystems`
                           ON (`glpi_operatingsystems`.`id` = `glpi_items_operatingsystems`.`operatingsystems_id`)
                           LEFT JOIN `glpi_operatingsystemservicepacks`
                           ON (`glpi_operatingsystemservicepacks`.`id` = `glpi_items_operatingsystems`.`operatingsystemservicepacks_id`)
                             WHERE `glpi_plugin_ocsinventoryng_ocslinks`.`ocsid`
                                          = " . $options['ocs_id'] . "
                                   AND `glpi_plugin_ocsinventoryng_ocslinks`.`plugin_ocsinventoryng_ocsservers_id`
                                          = $ocsServerId";

            $res_computer = $DB->query($sql_computer);

            if ($DB->numrows($res_computer) == 1) {
               $data_computer = $DB->fetch_array($res_computer);
               $computerOS    = $data_computer["os_name"];
               $computerOSSP  = $data_computer["os_sp"];

               //Do not log software history in case of OS or Service Pack change
               if ($computerOS != $hardware["OSNAME"]
                   || $computerOSSP != $hardware["OSCOMMENTS"]) {
                  $install_history = 0;
               }
            }
         }

         $updates        = 0;
         $license_number = null;

         $operatingsystems_id = 0;
         if (!in_array("operatingsystems_id", $options['computers_updates'])) {
            $os_data             = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware['OSNAME']);
            $operatingsystems_id = Dropdown::importExternal('OperatingSystem', $os_data);
         }

         if (intval($options['cfg_ocs']["import_os_serial"]) > 0
             && !in_array("license_number", $options['computers_updates'])) {
            if (!empty($hardware["WINPRODKEY"])) {
               $license_number = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware["WINPRODKEY"]);
               $updates++;
            }
         }
         $license_id = null;
         if (intval($options['cfg_ocs']["import_os_serial"]) > 0
             && !in_array("licenseid", $options['computers_updates'])) {
            if (!empty($hardware["WINPRODID"])) {
               $license_id = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware["WINPRODID"]);
               $updates++;
            }
         }

         if (intval($options['cfg_ocs']["import_general_os"]) > 0) {
            $operatingsystemversions_id = 0;
            if (!in_array("operatingsystemversions_id", $options['computers_updates'])) {
               $osv_data                   = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware['OSVERSION']);
               $operatingsystemversions_id = Dropdown::importExternal('OperatingSystemVersion', $osv_data);
               if ($operatingsystemversions_id > 0) {
                  $updates++;
               }
            }
            $operatingsystemservicepacks_id = 0;
            if (!in_array("operatingsystemservicepacks_id", $options['computers_updates'])) {

               $ossp_data                      = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware['OSCOMMENTS']);
               $operatingsystemservicepacks_id = Dropdown::importExternal('OperatingSystemServicePack', $ossp_data);
               if ($operatingsystemservicepacks_id > 0) {
                  $updates++;
               }
            }
            $operatingsystemarchitectures_id = 0;
            if (!in_array("operatingsystemarchitectures_id", $options['computers_updates'])
                && isset($hardware["ARCH"])) {
               $osa_data                        = PluginOcsinventoryngOcsProcess::encodeOcsDataInUtf8($is_utf8, $hardware['ARCH']);
               $operatingsystemarchitectures_id = Dropdown::importExternal('OperatingSystemArchitecture', $osa_data);
               if ($operatingsystemarchitectures_id > 0) {
                  $updates++;
               }
            }
         }

         $device = new Item_OperatingSystem();

         if ($force) {
            self::resetOS($options['computers_id'], $uninstall_history);
         }

         if ($id = $device->getFromDBByCrit(['items_id'            => $options['computers_id'],
                                             'itemtype'            => 'Computer',
                                             'entities_id'         => $options['entities_id'],
                                             'operatingsystems_id' => $operatingsystems_id,
                                             'is_dynamic'          => 1])) {
            if ($updates > 0) {
               $device->update(['id'                              => $id,
                                'operatingsystemversions_id'      => $operatingsystemversions_id,
                                'operatingsystemservicepacks_id'  => $operatingsystemservicepacks_id,
                                'operatingsystemarchitectures_id' => $operatingsystemarchitectures_id,
                                'license_number'                  => $license_number,
                                'licenseid'                      => $license_id,
                                '_nolock'                         => true,
                                'is_dynamic'                      => 1,
                                'entities_id'                     => $options['entities_id']
                               ], $install_history);
            }
         } else {

            //            if ($operatingsystems_id) {
            $device->add(['items_id'                        => $options['computers_id'],
                          'itemtype'                        => 'Computer',
                          'operatingsystems_id'             => $operatingsystems_id,
                          'operatingsystemversions_id'      => $operatingsystemversions_id,
                          'operatingsystemservicepacks_id'  => $operatingsystemservicepacks_id,
                          'operatingsystemarchitectures_id' => $operatingsystemarchitectures_id,
                          'license_number'                  => $license_number,
                          'licenseid'                      => $license_id,
                          '_nolock'                         => true,
                          'is_dynamic'                      => 1,
                          'entities_id'                     => $options['entities_id']
                         ], [], $install_history);

         }
      }
   }

   /**
    * Delete old os settings
    *
    * @param $glpi_computers_id integer : glpi computer id.
    * @param $history_hardware
    *
    * @return void .
    */
   static function resetOS($glpi_computers_id, $uninstall_history) {

      $linktype = 'Item_OperatingSystem';

      $item = new $linktype();
      $item->deleteByCriteria(['items_id'   => $glpi_computers_id,
                               'itemtype'   => 'Computer',
                               'is_dynamic' => 1
                              ], 1, $uninstall_history
      );
   }
}
