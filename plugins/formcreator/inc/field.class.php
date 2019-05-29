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

require_once(realpath(dirname(__FILE__ ) . '/../../../inc/includes.php'));

abstract class PluginFormcreatorField implements PluginFormcreatorFieldInterface
{
   /** @var array $fields Fields of an instance of PluginFormcreatorQuestion */
   protected $fields = [];

   /** @var mixed $answer Value of the field */
   protected $value = null;

   /**
    *
    * @param array $fields fields of a PluginFormcreatorQuestion instance
    * @param array $data value of all fields
    */
   public function __construct($fields) {
      $this->fields  = $fields;
   }

   public function prepareQuestionInputForSave($input) {
      $this->value = $input['default_values'];
      return $input;
   }

   public function getValueForTarget() {
      return Toolbox::addslashes_deep($this->value);
   }

   /**
    * Output HTML to display the field
    * @param boolean $canEdit is the field editable ?
    */
   public function show($canEdit = true) {
      $required = ($canEdit && $this->fields['required']) ? ' required' : '';

      echo '<div class="form-group ' . $required . '" id="form-group-field-' . $this->fields['id'] . '">';
      echo '<label for="formcreator_field_' . $this->fields['id'] . '">';
      echo $this->getLabel();
      if ($canEdit && $this->fields['required']) {
         echo ' <span class="red">*</span>';
      }
      echo '</label>';
      echo '<div class="help-block">' . html_entity_decode($this->fields['description']) . '</div>';

      echo '<div class="form_field">';
      $this->displayField($canEdit);
      echo '</div>';
      echo '</div>';
   }

   /**
    * Outputs the HTML representing the field
    * @param string $canEdit
    */
   public function displayField($canEdit = true) {
      $id           = $this->fields['id'];
      $rand         = mt_rand();
      $fieldName    = 'formcreator_field_' . $id;
      $domId        = $fieldName . '_' . $rand;
      $defaultValue = Html::cleanInputText($this->value);
      if ($canEdit) {
         echo '<input type="text" class="form-control"
                  name="' . $fieldName . '"
                  id="' . $domId . '"
                  value="' . $defaultValue . '" />';
         echo Html::scriptBlock("$(function() {
            pluginFormcreatorInitializeField('$fieldName', '$rand');
         });");
      } else {
         echo $this->value;
      }
   }

   /**
    * Gets the label of the field
    *
    * @return string
    */
   public function getLabel() {
      return $this->fields['name'];
   }

   /**
    * Gets the available values for the field
    * @return array available values
    */
   public function getAvailableValues() {
      return explode("\r\n", $this->fields['values']);
   }

   public function isRequired() {
      return $this->fields['required'];
   }

   /**
    * trim values separated by \r\n
    * @param string $value a value or default value
    * @return string
    */
   protected function trimValue($value) {
      $value = explode('\r\n', $value);
      $value = array_map('trim', $value);
      return implode('\r\n', $value);
   }

   public function getFieldTypeName() {
      $classname = get_called_class();
      $matches = null;
      preg_match("#^PluginFormcreator(.+)Field$#", $classname, $matches);
      return strtolower($matches[1]);
   }

   public function getEmptyParameters() {
      return [];
   }

   public final function getParameters() {
      $parameters = $this->getEmptyParameters();
      foreach ($parameters as $fieldname => $parameter) {
         $parameter->getFromDBByCrit([
            'plugin_formcreator_questions_id'   => $this->fields['id'],
            'fieldname'                         => $fieldname,
         ]);
         if ($parameter->isNewItem()) {
            $parameter->getEmpty();
         }
      }

      return $parameters;
   }

   public final function addParameters(PluginFormcreatorQuestion $question, array $input) {
      $fieldTypeName = $this->getFieldTypeName();
      if (!isset($input['_parameters'][$fieldTypeName])) {
         return;
      }

      foreach ($this->getEmptyParameters() as $fieldName => $parameter) {
         $input['_parameters'][$fieldTypeName][$fieldName]['plugin_formcreator_questions_id'] = $question->getID();
         $parameter->add($input['_parameters'][$fieldTypeName][$fieldName]);
      }
   }

   public final function updateParameters(PluginFormcreatorQuestion $question, array $input) {
      $fieldTypeName = $this->getFieldTypeName();
      if (!isset($input['_parameters'][$fieldTypeName])) {
         return;
      }

      $parameters = $this->getParameters();
      foreach ($parameters as $fieldName => $parameter) {
         $input['_parameters'][$fieldTypeName][$fieldName]['plugin_formcreator_questions_id'] = $question->getID();
         if ($parameter->isNewItem()) {
            // In case of the parameter vanished in DB, just recreate it
            $parameter->add($input['_parameters'][$fieldTypeName][$fieldName]);
         } else {
            $input['_parameters'][$fieldTypeName][$fieldName]['id'] = $parameter->getID();
            $parameter->update($input['_parameters'][$fieldTypeName][$fieldName]);
         }
      }
   }

   public final function deleteParameters(PluginFormcreatorQuestion $question) {
      foreach ($this->getEmptyParameters() as $parameter) {
         if (!$parameter->deleteByCriteria(['plugin_formcreator_questions_id' => $question->getID()])) {
            // Don't make  this error fatal, but log it anyway
            Toolbox::logInFile('php-errors', 'failed to delete parameter for question ' . $question->getID() . PHP_EOL);
         }
      }
      return true;
   }
}
