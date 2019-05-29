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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginFormcreatorTargetChange extends PluginFormcreatorTargetBase
{
   public static function getTypeName($nb = 1) {
      return _n('Target change', 'Target changes', $nb, 'formcreator');
   }

   static function getEnumUrgencyRule() {
      return [
         'none'      => __('Medium', 'formcreator'),
         'specific'  => __('Specific urgency', 'formcreator'),
         'answer'    => __('Equals to the answer to the question', 'formcreator'),
      ];
   }

   static function getEnumCategoryRule() {
      return [
         'none'      => __('None', 'formcreator'),
         'specific'  => __('Specific category', 'formcreator'),
         'answer'    => __('Equals to the answer to the question', 'formcreator'),
      ];
   }

   protected function getItem_User() {
      return new Change_User();
   }

   protected function getItem_Group() {
      return new Change_Group();
   }

   protected function getItem_Supplier() {
      return new Change_Supplier();
   }

   protected function getItem_Item() {
      return new Change_Item();
   }

   protected function getTargetItemtypeName() {
      return Change::class;
   }

   public function getItem_Actor() {
      return new PluginFormcreatorTargetChange_Actor();
   }

   protected function getCategoryFilter() {
      // TODO remove if and the next raw query when 9.3/bf compat will no be needed anymore
      if (version_compare(GLPI_VERSION, "9.4", '>=')) {
         return ['is_change' => 1];
      }
      return "`is_change` = '1'";
   }

   /**
    * Export in an array all the data of the current instanciated target ticket
    * @return array the array with all data (with sub tables)
    */
   public function export($remove_uuid = false) {
      global $DB;

      if (!$this->getID()) {
         return false;
      }

      $target_data = $this->fields;

      // convert questions ID into uuid for change description
      $found_section = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => PluginFormcreatorSection::getTable(),
         'WHERE'  => [
            'plugin_formcreator_forms_id' => $this->getForm()->getID()
         ],
         'ORDER'  => 'order ASC'
      ]);
      $tab_section = [];
      foreach ($found_section as $section_item) {
         $tab_section[] = $section_item['id'];
      }

      if (!empty($tab_section)) {
         $rows = $DB->request([
            'SELECT' => ['id', 'uuid'],
            'FROM'   => PluginFormcreatorQuestion::getTable(),
            'WHERE'  => [
               'plugin_formcreator_sections_id' => $tab_section
            ],
            'ORDER'  => 'order ASC'
         ]);
         foreach ($rows as $question_line) {
            $id    = $question_line['id'];
            $uuid  = $question_line['uuid'];

            $content = $target_data['name'];
            $content = str_replace("##question_$id##", "##question_$uuid##", $content);
            $content = str_replace("##answer_$id##", "##answer_$uuid##", $content);
            $target_data['name'] = $content;

            $content = $target_data['content'];
            $content = str_replace("##question_$id##", "##question_$uuid##", $content);
            $content = str_replace("##answer_$id##", "##answer_$uuid##", $content);
            $target_data['content'] = $content;
         }
      }

      // remove key and fk
      unset($target_data['id']);

      return $target_data;
   }

   /**
    * Import a form's target change into the db
    * @see PluginFormcreatorTarget::import
    *
    * @param  integer $targetitems_id  current id
    * @param  array   $target_data the targetchange data (match the targetticket table)
    * @return integer the targetchange's id
    */
   public static function import($targetitems_id = 0, $target_data = []) {
      global $DB;

      $item = new self;

      $target_data['_skip_checks'] = true;
      $target_data['id'] = $targetitems_id;

      // convert question uuid into id
      $targetChange = new PluginFormcreatorTargetChange();
      $targetChange->getFromDB($targetitems_id);
      $section = new PluginFormcreatorSection();
      $foundSections = $section->getSectionsFromForm($targetChange->getForm()->getID());
      $tab_section = [];
      foreach ($foundSections as $section) {
         $tab_section[] = $section->getID();
      }

      if (!empty($tab_section)) {
         $sectionFk = PluginFormcreatorSection::getForeignKeyField();
         $rows = $DB->request([
            'SELECT' => ['id', 'uuid'],
            'FROM'   => PluginFormcreatorQuestion::getTable(),
            'WHERE'  => [
               $sectionFk => $tab_section
            ],
            'ORDER'  => 'order ASC'
         ]);
         foreach ($rows as $question_line) {
            $id      = $question_line['id'];
            $uuid    = $question_line['uuid'];

            $content = $target_data['name'];
            $content = str_replace("##question_$uuid##", "##question_$id##", $content);
            $content = str_replace("##answer_$uuid##", "##answer_$id##", $content);
            $target_data['name'] = $content;

            $content = $target_data['content'];
            $content = str_replace("##question_$uuid##", "##question_$id##", $content);
            $content = str_replace("##answer_$uuid##", "##answer_$id##", $content);
            $target_data['content'] = $content;
         }
      }

      // escape text fields
      foreach (['title', 'content'] as $key) {
         $target_data[$key] = $DB->escape($target_data[$key]);
      }

      // update target change
      $item->update($target_data);

      if ($targetitems_id
            && isset($target_data['_actors'])) {
         foreach ($target_data['_actors'] as $actor) {
            PluginFormcreatorTargetChange_Actor::import($targetitems_id, $actor);
         }
      }

      return $targetitems_id;
   }

   /**
    * Show the Form for the adminsitrator to edit in the config page
    *
    * @param  Array  $options Optional options
    *
    * @return NULL         Nothing, just display the form
    */
   public function showForm($options = []) {
      global $CFG_GLPI, $DB;

      $rand = mt_rand();

      $target = $DB->request([
         'FROM'    => PluginFormcreatorTarget::getTable(),
         'WHERE'   => [
            'itemtype' => __CLASS__,
            'items_id' => (int) $this->getID()
         ]
      ])->next();

      $form = new PluginFormcreatorForm();
      $form->getFromDB($target['plugin_formcreator_forms_id']);

      echo '<div class="center" style="width: 950px; margin: 0 auto;">';
      echo '<form name="form_target" method="post" action="' . $CFG_GLPI['root_doc'] . '/plugins/formcreator/front/targetchange.form.php">';

      // General information: name
      echo '<table class="tab_cadre_fixe">';

      echo '<tr><th colspan="2">' . __('Edit a destination', 'formcreator') . '</th></tr>';

      echo '<tr class="line1">';
      echo '<td width="15%"><strong>' . __('Name') . ' <span style="color:red;">*</span></strong></td>';
      echo '<td width="85%"><input type="text" name="name" style="width:704px;" value="' . $target['name'] . '"/></td>';
      echo '</tr>';

      echo '</table>';

      // change information: title, template...
      echo '<table class="tab_cadre_fixe">';

      echo '<tr><th colspan="4">' . _n('Target change', 'Target changes', 1, 'formcreator') . '</th></tr>';

      echo '<tr class="line1">';
      echo '<td><strong>' . __('Change title', 'formcreator') . ' <span style="color:red;">*</span></strong></td>';
      echo '<td colspan="3"><input type="text" name="title" style="width:704px;" value="' . $this->fields['name'] . '"></textarea></td>';
      echo '</tr>';

      echo '<tr class="line0">';
      echo '<td><strong>' . __('Description') . ' <span style="color:red;">*</span></strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="content" style="width:700px;" rows="15">' . $this->fields['content'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="line1">';
      echo '<td><strong>' . __('Impacts') . ' </strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="impactcontent" style="width:700px;" rows="15">' . $this->fields['impactcontent'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="line0">';
      echo '<td><strong>' . __('Control list') . ' </strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="controlistcontent" style="width:700px;" rows="15">' . $this->fields['controlistcontent'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="line1">';
      echo '<td><strong>' . __('Deployment plan') . ' </strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="rolloutplancontent" style="width:700px;" rows="15">' . $this->fields['rolloutplancontent'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="line0">';
      echo '<td><strong>' . __('Backup plan') . ' </strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="backoutplancontent" style="width:700px;" rows="15">' . $this->fields['backoutplancontent'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      echo '<tr class="line1">';
      echo '<td><strong>' . __('Checklist') . ' </strong></td>';
      echo '<td colspan="3">';
      echo '<textarea name="checklistcontent" style="width:700px;" rows="15">' . $this->fields['checklistcontent'] . '</textarea>';
      echo '</td>';
      echo '</tr>';

      $rand = mt_rand();
      $this->showDestinationEntitySetings($rand);

      echo '<tr class="line1">';
      $this->showDueDateSettings($rand);
      echo '<td colspan="2"></td>';
      echo '</tr>';

      // -------------------------------------------------------------------------------------------
      //  category of the target
      // -------------------------------------------------------------------------------------------
      $this->showCategorySettings($rand);

      // -------------------------------------------------------------------------------------------
      // Urgency selection
      // -------------------------------------------------------------------------------------------
      $this->showUrgencySettings($rand);

      // -------------------------------------------------------------------------------------------
      //  Tags
      // -------------------------------------------------------------------------------------------
      $this->showPluginTagsSettings($rand);

      echo '</table>';

      // Buttons
      echo '<table class="tab_cadre_fixe">';

      echo '<tr class="line1">';
      echo '<td colspan="5" class="center">';
      echo '<input type="reset" name="reset" class="submit_button" value="' . __('Cancel', 'formcreator') . '"
               onclick="document.location = \'form.form.php?id=' . $target['plugin_formcreator_forms_id'] . '\'" /> &nbsp; ';
      echo '<input type="hidden" name="id" value="' . $this->getID() . '" />';
      echo '<input type="submit" name="update" class="submit_button" value="' . __('Save') . '" />';
      echo '</td>';
      echo '</tr>';

      echo '</table>';

      Html::closeForm();

      // Get available questions for actors lists
      $questions_user_list     = [Dropdown::EMPTY_VALUE];
      $questions_group_list    = [Dropdown::EMPTY_VALUE];
      $questions_supplier_list = [Dropdown::EMPTY_VALUE];
      $questions_actors_list   = [Dropdown::EMPTY_VALUE];
      $query = "SELECT s.id, s.name
                FROM glpi_plugin_formcreator_targets t
                INNER JOIN glpi_plugin_formcreator_sections s
                  ON s.plugin_formcreator_forms_id = t.plugin_formcreator_forms_id
                WHERE t.items_id = ".$this->getID()."
                ORDER BY s.order";
      $result = $DB->query($query);
      while ($section = $DB->fetch_array($result)) {
         // select all user, group or supplier questions (GLPI Object)
         $query2 = "SELECT q.id, q.name, q.fieldtype, q.values
                    FROM glpi_plugin_formcreator_questions q
                    INNER JOIN glpi_plugin_formcreator_sections s
                      ON s.id = q.plugin_formcreator_sections_id
                    WHERE s.id = {$section['id']}
                    AND ((q.fieldtype = 'glpiselect'
                      AND q.values IN ('User', 'Group', 'Supplier'))
                      OR (q.fieldtype = 'actor'))";
         $result2 = $DB->query($query2);
         $section_questions_user     = [];
         $section_questions_group    = [];
         $section_questions_supplier = [];
         $section_questions_actors   = [];
         while ($question = $DB->fetch_array($result2)) {
            if ($question['fieldtype'] == 'glpiselect') {
               switch ($question['values']) {
                  case 'User' :
                     $section_questions_user[$question['id']] = $question['name'];
                     break;
                  case 'Group' :
                     $section_questions_group[$question['id']] = $question['name'];
                     break;
                  case 'Supplier' :
                     $section_questions_supplier[$question['id']] = $question['name'];
                     break;
               }
            } else if ($question['fieldtype'] == 'actor') {
               $section_questions_actors[$question['id']] = $question['name'];
            }
         }
         $questions_user_list[$section['name']]     = $section_questions_user;
         $questions_group_list[$section['name']]    = $section_questions_group;
         $questions_supplier_list[$section['name']] = $section_questions_supplier;
         $questions_actors_list[$section['name']]   = $section_questions_actors;
      }

      // Get available questions for actors lists
      $actors = ['requester' => [], 'observer' => [], 'assigned' => []];
      $query = "SELECT id, actor_role, actor_type, actor_value, use_notification
                FROM glpi_plugin_formcreator_targetchanges_actors
                WHERE plugin_formcreator_targetchanges_id = " . $this->getID();
      $result = $DB->query($query);
      while ($actor = $DB->fetch_array($result)) {
         $actors[$actor['actor_role']][$actor['id']] = [
            'actor_type'       => $actor['actor_type'],
            'actor_value'      => $actor['actor_value'],
            'use_notification' => $actor['use_notification'],
         ];
      }

      $img_user     = '<img src="../../../pics/users.png" alt="' . __('User') . '" title="' . __('User') . '" width="20" />';
      $img_group    = '<img src="../../../pics/groupes.png" alt="' . __('Group') . '" title="' . __('Group') . '" width="20" />';
      $img_supplier = '<img src="../../../pics/supplier.png" alt="' . __('Supplier') . '" title="' . __('Supplier') . '" width="20" />';
      $img_mail     = '<img src="../pics/email.png" alt="' . __('Yes') . '" title="' . __('Email followup') . ' ' . __('Yes') . '" />';
      $img_nomail   = '<img src="../pics/email-no.png" alt="' . __('No') . '" title="' . __('Email followup') . ' ' . __('No') . '" />';

      echo '<table class="tab_cadre_fixe">';

      echo '<tr><th colspan="3">' . __('Change actors', 'formcreator') . '</th></tr>';

      echo '<tr>';

      echo '<th width="33%">';
      echo _n('Requester', 'Requesters', 1) . ' &nbsp;';
      echo '<img title="Ajouter" alt="Ajouter" onclick="displayRequesterForm()" class="pointer"
               id="btn_add_requester" src="../../../pics/add_dropdown.png">';
      echo '<img title="Annuler" alt="Annuler" onclick="hideRequesterForm()" class="pointer"
               id="btn_cancel_requester" src="../../../pics/delete.png" style="display:none">';
      echo '</th>';

      echo '<th width="34%">';
      echo _n('Watcher', 'Watchers', 1) . ' &nbsp;';
      echo '<img title="Ajouter" alt="Ajouter" onclick="displayWatcherForm()" class="pointer"
               id="btn_add_watcher" src="../../../pics/add_dropdown.png">';
      echo '<img title="Annuler" alt="Annuler" onclick="hideWatcherForm()" class="pointer"
               id="btn_cancel_watcher" src="../../../pics/delete.png" style="display:none">';
      echo '</th>';

      echo '<th width="33%">';
      echo __('Assigned to') . ' &nbsp;';
      echo '<img title="Ajouter" alt="Ajouter" onclick="displayAssignedForm()" class="pointer"
               id="btn_add_assigned" src="../../../pics/add_dropdown.png">';
      echo '<img title="Annuler" alt="Annuler" onclick="hideAssignedForm()" class="pointer"
               id="btn_cancel_assigned" src="../../../pics/delete.png" style="display:none">';
      echo '</th>';

      echo '</tr>';

      echo '<tr>';

      // Requester
      echo '<td valign="top">';

      // => Add requester form
      echo '<form name="form_target" id="form_add_requester" method="post" style="display:none" action="'
           . $CFG_GLPI['root_doc'] . '/plugins/formcreator/front/targetchange.form.php">';

      $dropdownItems = ['' => Dropdown::EMPTY_VALUE] + PluginFormcreatorTargetTicket_Actor::getEnumActorType();
      unset($dropdownItems['supplier']);
      unset($dropdownItems['question_supplier']);
      Dropdown::showFromArray(
         'actor_type',
         $dropdownItems, [
            'on_change'         => 'formcreatorChangeActorRequester(this.value)'
         ]
      );

      echo '<div id="block_requester_user" style="display:none">';
      User::dropdown([
         'name' => 'actor_value_person',
         'right' => 'all',
         'all'   => 0,
      ]);
      echo '</div>';

      echo '<div id="block_requester_group" style="display:none">';
      Group::dropdown([
         'name' => 'actor_value_group',
      ]);
      echo '</div>';

      echo '<div id="block_requester_question_user" style="display:none">';
      Dropdown::showFromArray('actor_value_question_person', $questions_user_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_requester_question_group" style="display:none">';
      Dropdown::showFromArray('actor_value_question_group', $questions_group_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_requester_question_actors" style="display:none">';
      Dropdown::showFromArray('actor_value_question_actors', $questions_actors_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div>';
      echo __('Email followup');
      Dropdown::showYesNo('use_notification', 1);
      echo '</div>';

      echo '<p align="center">';
      echo '<input type="hidden" name="id" value="' . $this->getID() . '" />';
      echo '<input type="hidden" name="actor_role" value="requester" />';
      echo '<input type="submit" value="' . __('Add') . '" class="submit_button" />';
      echo '</p>';

      echo "<hr>";

      Html::closeForm();

      // => List of saved requesters
      foreach ($actors['requester'] as $id => $values) {
         echo '<div>';
         switch ($values['actor_type']) {
            case 'creator' :
               echo $img_user . ' <b>' . __('Form requester', 'formcreator') . '</b>';
               break;
            case 'validator' :
               echo $img_user . ' <b>' . __('Form validator', 'formcreator') . '</b>';
               break;
            case 'person' :
               $user = new User();
               $user->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('User') . ' </b> "' . $user->getName() . '"';
               break;
            case 'question_person' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Person from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'group' :
               $group = new Group();
               $group->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Group') . ' </b> "' . $group->getName() . '"';
               break;
            case 'question_group' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_group . ' <b>' . __('Group from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'question_actors':
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Actors from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
         }
         echo $values['use_notification'] ? ' ' . $img_mail . ' ' : ' ' . $img_nomail . ' ';
         echo self::getDeleteImage($id);
         echo '</div>';
      }

      echo '</td>';

      // Observer
      echo '<td valign="top">';

      // => Add observer form
      echo '<form name="form_target" id="form_add_watcher" method="post" style="display:none" action="'
           . $CFG_GLPI['root_doc'] . '/plugins/formcreator/front/targetchange.form.php">';

      $dropdownItems = [''  => Dropdown::EMPTY_VALUE] + PluginFormcreatorTargetTicket_Actor::getEnumActorType();
      unset($dropdownItems['supplier']);
      unset($dropdownItems['question_supplier']);
      Dropdown::showFromArray('actor_type',
         $dropdownItems, ['on_change' => 'formcreatorChangeActorWatcher(this.value)']
      );

      echo '<div id="block_watcher_user" style="display:none">';
      User::dropdown([
         'name' => 'actor_value_person',
         'right' => 'all',
         'all'   => 0,
      ]);
      echo '</div>';

      echo '<div id="block_watcher_group" style="display:none">';
      Group::dropdown([
         'name' => 'actor_value_group',
      ]);
      echo '</div>';

      echo '<div id="block_watcher_question_user" style="display:none">';
      Dropdown::showFromArray('actor_value_question_person', $questions_user_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_watcher_question_group" style="display:none">';
      Dropdown::showFromArray('actor_value_question_group', $questions_group_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_watcher_question_actors" style="display:none">';
      Dropdown::showFromArray('actor_value_question_actors', $questions_actors_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div>';
      echo __('Email followup');
      Dropdown::showYesNo('use_notification', 1);
      echo '</div>';

      echo '<p align="center">';
      echo '<input type="hidden" name="id" value="' . $this->getID() . '" />';
      echo '<input type="hidden" name="actor_role" value="observer" />';
      echo '<input type="submit" value="' . __('Add') . '" class="submit_button" />';
      echo '</p>';

      echo "<hr>";

      Html::closeForm();

      // => List of saved observers
      foreach ($actors['observer'] as $id => $values) {
         echo '<div>';
         switch ($values['actor_type']) {
            case 'creator' :
               echo $img_user . ' <b>' . __('Form requester', 'formcreator') . '</b>';
               break;
            case 'validator' :
               echo $img_user . ' <b>' . __('Form validator', 'formcreator') . '</b>';
               break;
            case 'person' :
               $user = new User();
               $user->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('User') . ' </b> "' . $user->getName() . '"';
               break;
            case 'question_person' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Person from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'group' :
               $group = new Group();
               $group->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Group') . ' </b> "' . $group->getName() . '"';
               break;
            case 'question_group' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_group . ' <b>' . __('Group from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'question_actors' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Actors from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
         }
         echo $values['use_notification'] ? ' ' . $img_mail . ' ' : ' ' . $img_nomail . ' ';
         echo self::getDeleteImage($id);
         echo '</div>';
      }

      echo '</td>';

      // Assigned to
      echo '<td valign="top">';

      // => Add assigned to form
      echo '<form name="form_target" id="form_add_assigned" method="post" style="display:none" action="'
            . $CFG_GLPI['root_doc'] . '/plugins/formcreator/front/targetchange.form.php">';

      $dropdownItems = [''  => Dropdown::EMPTY_VALUE] + PluginFormcreatorTargetTicket_Actor::getEnumActorType();
      Dropdown::showFromArray(
         'actor_type',
         $dropdownItems, [
           'on_change'         => 'formcreatorChangeActorAssigned(this.value)'
         ]
      );

      echo '<div id="block_assigned_user" style="display:none">';
      User::dropdown([
            'name' => 'actor_value_person',
            'right' => 'all',
            'all'   => 0,
      ]);
      echo '</div>';

      echo '<div id="block_assigned_group" style="display:none">';
      Group::dropdown([
         'name' => 'actor_value_group',
      ]);
      echo '</div>';

      echo '<div id="block_assigned_supplier" style="display:none">';
      Supplier::dropdown([
         'name' => 'actor_value_supplier',
      ]);
      echo '</div>';

      echo '<div id="block_assigned_question_user" style="display:none">';
      Dropdown::showFromArray('actor_value_question_person', $questions_user_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_assigned_question_group" style="display:none">';
      Dropdown::showFromArray('actor_value_question_group', $questions_group_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_assigned_question_actors" style="display:none">';
      Dropdown::showFromArray('actor_value_question_actors', $questions_actors_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div id="block_assigned_question_supplier" style="display:none">';
      Dropdown::showFromArray('actor_value_question_supplier', $questions_supplier_list, [
         'value' => $this->fields['due_date_question'],
      ]);
      echo '</div>';

      echo '<div>';
      echo __('Email followup');
      Dropdown::showYesNo('use_notification', 1);
      echo '</div>';

      echo '<p align="center">';
      echo '<input type="hidden" name="id" value="' . $this->getID() . '" />';
      echo '<input type="hidden" name="actor_role" value="assigned" />';
      echo '<input type="submit" value="' . __('Add') . '" class="submit_button" />';
      echo '</p>';

      echo "<hr>";

      Html::closeForm();

      // => List of saved assigned to
      foreach ($actors['assigned'] as $id => $values) {
         echo '<div>';
         switch ($values['actor_type']) {
            case 'creator' :
               echo $img_user . ' <b>' . __('Form requester', 'formcreator') . '</b>';
               break;
            case 'validator' :
               echo $img_user . ' <b>' . __('Form validator', 'formcreator') . '</b>';
               break;
            case 'person' :
               $user = new User();
               $user->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('User') . ' </b> "' . $user->getName() . '"';
               break;
            case 'question_person' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Person from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'group' :
               $group = new Group();
               $group->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Group') . ' </b> "' . $group->getName() . '"';
               break;
            case 'question_group' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_group . ' <b>' . __('Group from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'question_actors' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_user . ' <b>' . __('Actors from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
            case 'supplier' :
               $supplier = new Supplier();
               $supplier->getFromDB($values['actor_value']);
               echo $img_supplier . ' <b>' . __('Supplier') . ' </b> "' . $supplier->getName() . '"';
               break;
            case 'question_supplier' :
               $question = new PluginFormcreatorQuestion();
               $question->getFromDB($values['actor_value']);
               echo $img_supplier . ' <b>' . __('Supplier from the question', 'formcreator')
               . '</b> "' . $question->getName() . '"';
               break;
         }
         echo $values['use_notification'] ? ' ' . $img_mail . ' ' : ' ' . $img_nomail . ' ';
         echo self::getDeleteImage($id);
         echo '</div>';
      }

      echo '</td>';

      echo '</tr>';

      echo '</table>';

      // List of available tags
      echo '<table class="tab_cadre_fixe">';

      echo '<tr><th colspan="5">' . __('List of available tags') . '</th></tr>';
      echo '<tr>';
      echo '<th width="40%" colspan="2">' . _n('Question', 'Questions', 1, 'formcreator') . '</th>';
      echo '<th width="20%">' . __('Title') . '</th>';
      echo '<th width="20%">' . _n('Answer', 'Answers', 1, 'formcreator') . '</th>';
      echo '<th width="20%">' . _n('Section', 'Sections', 1, 'formcreator') . '</th>';
      echo '</tr>';

      echo '<tr class="line0">';
      echo '<td colspan="2"><strong>' . __('Full form', 'formcreator') . '</strong></td>';
      echo '<td align="center"><code>-</code></td>';
      echo '<td align="center"><code><strong>##FULLFORM##</strong></code></td>';
      echo '<td align="center">-</td>';
      echo '</tr>';

      $table_questions = getTableForItemType('PluginFormcreatorQuestion');
      $table_sections  = getTableForItemType('PluginFormcreatorSection');
      $query = "SELECT q.`id`, q.`name` AS question, s.`name` AS section
                FROM $table_questions q
                LEFT JOIN $table_sections s
                  ON q.`plugin_formcreator_sections_id` = s.`id`
                WHERE s.`plugin_formcreator_forms_id` = " . $target['plugin_formcreator_forms_id'] . "
                ORDER BY s.`order`, q.`order`";
      $result = $DB->query($query);

      $i = 0;
      while ($question = $DB->fetch_array($result)) {
         $i++;
         echo '<tr class="line' . ($i % 2) . '">';
         echo '<td colspan="2">' . $question['question'] . '</td>';
         echo '<td align="center"><code>##question_' . $question['id'] . '##</code></td>';
         echo '<td align="center"><code>##answer_' . $question['id'] . '##</code></td>';
         echo '<td align="center">' . $question['section'] . '</td>';
         echo '</tr>';
      }

      echo '</table>';
      echo '</div>';
   }

   /**
    * Prepare input data for updating the target ticket
    *
    * @param array $input data used to add the item
    *
    * @return array the modified $input array
    */
   public function prepareInputForUpdate($input) {
      global $CFG_GLPI;

      // Control fields values :
      if (!isset($input['_skip_checks'])
            || !$input['_skip_checks']) {
         // - name is required
         if (empty($input['title'])) {
            Session::addMessageAfterRedirect(__('The title cannot be empty!', 'formcreator'), false, ERROR);
            return [];
         }

         // - content is required
         if (empty($input['content'])) {
            Session::addMessageAfterRedirect(__('The description cannot be empty!', 'formcreator'), false, ERROR);
            return [];
         }

         if (version_compare(PluginFormcreatorCommon::getGlpiVersion(), 9.4) >= 0 || $CFG_GLPI['use_rich_text']) {
            $input['content'] = Html::entity_decode_deep($input['content']);
         }

         switch ($input['destination_entity']) {
            case 'specific' :
               $input['destination_entity_value'] = $input['_destination_entity_value_specific'];
               break;
            case 'user' :
               $input['destination_entity_value'] = $input['_destination_entity_value_user'];
               break;
            case 'entity' :
               $input['destination_entity_value'] = $input['_destination_entity_value_entity'];
               break;
            default :
               $input['destination_entity_value'] = 'NULL';
               break;
         }

         switch ($input['urgency_rule']) {
            case 'answer':
               $input['urgency_question'] = $input['_urgency_question'];
               break;
            case 'specific':
               $input['urgency_question'] = $input['_urgency_specific'];
               break;
            default:
               $input['urgency_question'] = '0';
         }

         switch ($input['category_rule']) {
            case 'answer':
               $input['category_question'] = $input['_category_question'];
               break;
            case 'specific':
               $input['category_question'] = $input['_category_specific'];
               break;
            default:
               $input['category_question'] = '0';
         }

         $plugin = new Plugin();
         if ($plugin->isInstalled('tag') && $plugin->isActivated('tag')) {
            $input['tag_questions'] = (!empty($input['_tag_questions']))
                                       ? implode(',', $input['_tag_questions'])
                                       : '';
            $input['tag_specifics'] = (!empty($input['_tag_specifics']))
                                       ? implode(',', $input['_tag_specifics'])
                                       : '';
         }
      }

      return parent::prepareInputForUpdate($input);
   }

   /**
    * Save form data to the target
    *
    * @param  PluginFormcreatorFormAnswer $formanswer    Answers previously saved
    *
    * @return Change|false generated change
    */
   public function save(PluginFormcreatorFormAnswer $formanswer) {
      global $DB;

      // Prepare actors structures for creation of the ticket
      $this->requesters = [
         '_users_id_requester'         => [],
         '_users_id_requester_notif'   => [
            'use_notification'      => [],
            'alternative_email'     => [],
         ],
      ];
      $this->observers = [
         '_users_id_observer'          => [],
         '_users_id_observer_notif'    => [
            'use_notification'      => [],
            'alternative_email'     => [],
         ],
      ];
      $this->assigned = [
         '_users_id_assign'       => [],
         '_users_id_assign_notif' => [
            'use_notification'      => [],
            'alternative_email'     => [],
         ],
      ];

      $this->assignedSuppliers = [
         '_suppliers_id_assign'        => [],
         '_suppliers_id_assign_notif'  => [
            'use_notification'      => [],
            'alternative_email'     => [],
         ]
      ];

      $this->requesterGroups = [
         '_groups_id_requester'        => [],
      ];

      $this->observerGroups = [
         '_groups_id_observer'         => [],
      ];

      $this->assignedGroups = [
         '_groups_id_assign'           => [],
      ];

      $data   = [];
      $change  = new Change();
      $form    = $formanswer->getForm();

      $data['requesttypes_id'] = PluginFormcreatorCommon::getFormcreatorRequestTypeId();

      // Parse data
      $changeFields = [
         'name',
         'content',
         'impactcontent',
         'controlistcontent',
         'rolloutplancontent',
         'backoutplancontent',
         'checklistcontent'
      ];
      foreach ($changeFields as $changeField) {
         $data[$changeField] = $this->prepareTemplate(
            $this->fields[$changeField],
            $formanswer,
            true
         );

         $data[$changeField] = $this->parseTags($data[$changeField], $formanswer);
      }

      $data['_users_id_recipient']   = $_SESSION['glpiID'];

      $this->prepareActors($form, $formanswer);

      if (count($this->requesters['_users_id_requester']) == 0) {
         $this->addActor('requester', $formanswer->fields['requester_id'], true);
         $requesters_id = $formanswer->fields['requester_id'];
      } else if (count($this->requesters['_users_id_requester']) >= 1) {
         if ($this->requesters['_users_id_requester'][0] == 0) {
            $this->addActor('requester', $formanswer->fields['requester_id'], true);
            $requesters_id = $formanswer->fields['requester_id'];
         } else {
            $requesters_id = $this->requesters['_users_id_requester'][0];
         }
      }

      $data = $this->setTargetEntity($data, $formanswer, $requesters_id);
      $data = $this->setTargetDueDate($data, $formanswer);
      $data = $this->setTargetCategory($data, $formanswer);

      $data = $this->requesters + $this->observers + $this->assigned + $this->assignedSuppliers + $data;
      $data = $this->requesterGroups + $this->observerGroups + $this->assignedGroups + $data;

      // Create the target change
      if (!$changeID = $change->add($data)) {
         return false;
      }

      // Add tag if presents
      $plugin = new Plugin();
      if ($plugin->isActivated('tag')) {
         $tagObj = new PluginTagTagItem();
         $tags   = [];

         // Add question tags
         if (($this->fields['tag_type'] == 'questions'
               || $this->fields['tag_type'] == 'questions_and_specific'
               || $this->fields['tag_type'] == 'questions_or_specific')
               && (!empty($this->fields['tag_questions']))) {

                  $query = "SELECT answer
                      FROM `glpi_plugin_formcreator_answers`
                      WHERE `plugin_formcreator_formanswers_id` = " . (int) $formanswer->fields['id'] . "
                      AND `plugin_formcreator_questions_id` IN (" . $this->fields['tag_questions'] . ")";
                  $result = $DB->query($query);
            while ($line = $DB->fetch_array($result)) {
               $tab = json_decode($line['answer']);
               if (is_array($tab)) {
                  $tags = array_merge($tags, $tab);
               }
            }
         }

         // Add specific tags
         if ($this->fields['tag_type'] == 'specifics'
                     || $this->fields['tag_type'] == 'questions_and_specific'
                     || ($this->fields['tag_type'] == 'questions_or_specific' && empty($tags))
                     && (!empty($this->fields['tag_specifics']))) {

            $tags = array_merge($tags, explode(',', $this->fields['tag_specifics']));
         }

         $tags = array_unique($tags);

         // Save tags in DB
         foreach ($tags as $tag) {
            $tagObj->add([
               'plugin_tag_tags_id' => $tag,
               'items_id'           => $changeID,
               'itemtype'           => Change::class,
            ]);
         }
      }

      // Add link between Change and FormAnswer
      $itemlink = $this->getItem_Item();
      $itemlink->add([
         'itemtype'     => PluginFormcreatorFormAnswer::class,
         'items_id'     => $formanswer->fields['id'],
         'changes_id'  => $changeID,
      ]);

      $this->attachDocument($formanswer->getID(), Change::class, $changeID);

      return $change;
   }

   private static function getDeleteImage($id) {
      global $CFG_GLPI;

      $link  = ' &nbsp;<a href="' . $CFG_GLPI['root_doc'] . '/plugins/formcreator/front/targetchange.form.php?delete_actor=' . $id . '">';
      $link .= '<img src="../../../pics/delete.png" alt="' . __('Delete') . '" title="' . __('Delete') . '" />';
      $link .= '</a>';
      return $link;
   }
}
