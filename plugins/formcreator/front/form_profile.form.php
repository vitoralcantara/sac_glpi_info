<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @author    Thierry Bugier
 * @author    Jérémy Moreau
 * @copyright Copyright © 2011 - 2019 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

include ('../../../inc/includes.php');

Session::checkRight("entity", UPDATE);

// Check if plugin is activated...
$plugin = new Plugin();
if (!$plugin->isActivated("formcreator")) {
   Html::displayNotFoundError();
}

if (isset($_POST["profiles_id"]) && isset($_POST["form_id"])) {

   if (isset($_POST['access_rights'])) {
      $form = new PluginFormcreatorForm();
      $form->update([
         'id'            => (int) $_POST['form_id'],
         'access_rights' => (int) $_POST['access_rights']
      ]);
   }

   $form_profile = new PluginFormcreatorForm_Profile();
   $form_profile->deleteByCriteria([
         'plugin_formcreator_forms_id'    => (int) $_POST["form_id"],
   ]);

   foreach ($_POST["profiles_id"] as $profile_id) {
      if ($profile_id != 0) {
         $form_profile = new PluginFormcreatorForm_Profile();
         $form_profile->add([
               'plugin_formcreator_forms_id' => (int) $_POST["form_id"],
               'profiles_id'                 => (int) $profile_id,
         ]);
      }
   }
   Html::back();
} else {
   Html::back();
}
