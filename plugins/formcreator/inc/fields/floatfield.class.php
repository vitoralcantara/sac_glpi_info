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

class PluginFormcreatorFloatField extends PluginFormcreatorField
{
   public function isPrerequisites() {
      return true;
   }

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

   public function serializeValue() {
      if ($this->value === null || $this->value === '') {
         return '';
      }

      return strval((float) $this->value);
   }

   public function deserializeValue($value) {
      $this->value = ($value !== null && $value !== '')
                  ? $value
                  : '';
   }

   public function getValueForDesign() {
      if ($this->value === null) {
         return '';
      }

      return $this->value;
   }

   public function getValueForTargetText($richText) {
      return Toolbox::addslashes_deep($this->value);
   }

   public function getDocumentsForTarget() {
      return [];
   }

   public function isValid() {
      if ($this->isRequired() && $this->value == '') {
         Session::addMessageAfterRedirect(
            __('A required field is empty:', 'formcreator') . ' ' . $this->getLabel(),
            false,
            ERROR);
         return false;
      }

      if (!$this->isValidValue($this->value)) {
         return false;
      }

      return true;
   }

   private function isValidValue($value) {
      if (!empty($value) && !is_numeric($value)) {
         Session::addMessageAfterRedirect(__('This is not a number:', 'formcreator') . ' ' . $this->fields['name'], false, ERROR);
         return false;
      }

      $parameters = $this->getParameters();

      // Check the field matches the format regex
      if (!$parameters['regex']->isNewItem()) {
         $regex = $parameters['regex']->fields['regex'];
         if ($regex !== null && strlen($regex) > 0) {
            if (!preg_match($regex, $value)) {
               Session::addMessageAfterRedirect(__('Specific format does not match:', 'formcreator') . ' ' . $this->fields['name'], false, ERROR);
               return false;
            }
         }
      }

      // Check the field is in the range
      if (!$parameters['range']->isNewItem()) {
         $rangeMin = $parameters['range']->fields['range_min'];
         $rangeMax = $parameters['range']->fields['range_max'];
         if ($rangeMin > 0 && $value < $rangeMin) {
            $message = sprintf(__('The following number must be greater than %d:', 'formcreator'), $rangeMin);
            Session::addMessageAfterRedirect($message . ' ' . $this->fields['name'], false, ERROR);
            return false;
         }

         if ($rangeMax > 0 && $value > $rangeMax) {
            $message = sprintf(__('The following number must be lower than %d:', 'formcreator'), $rangeMax);
            Session::addMessageAfterRedirect($message . ' ' . $this->fields['name'], false, ERROR);
            return false;
         }
      }

      return true;
   }

   public static function getName() {
      return __('Float', 'formcreator');
   }

   public function prepareQuestionInputForSave($input) {
      $success = true;
      $fieldType = $this->getFieldTypeName();
      // Add leading and trailing regex marker automaticaly
      if (isset($input['_parameters'][$fieldType]['regex']['regex']) && !empty($input['_parameters'][$fieldType]['regex']['regex'])) {
         // Avoid php notice when validating the regular expression
         set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) {});
         $isValid = !(preg_match($input['_parameters'][$fieldType]['regex']['regex'], null) === false);
         restore_error_handler();

         if (!$isValid) {
            Session::addMessageAfterRedirect(__('The regular expression is invalid', 'formcreator'), false, ERROR);
            $success = false;
         }
      }
      if (!$success) {
         return false;
      }

      if (isset($input['default_values'])) {
         if ($input['default_values'] != '') {
            $this->value = (float) str_replace(',', '.', $input['default_values']);
         } else {
            $this->value = '';
         }
      }
      $input['values'] = '';

      return $input;
   }

   public function parseAnswerValues($input, $nonDestructive = false) {
      $key = 'formcreator_field_' . $this->fields['id'];
      if (!is_string($input[$key])) {
         return false;
      }
      if ($input[$key] != (float) str_replace(',', '.', $input[$key])) {
         return false;
      }

       $this->value = $input[$key];
       return true;
   }

   public static function getPrefs() {
      return [
         'required'       => 1,
         'default_values' => 1,
         'values'         => 0,
         'range'          => 1,
         'show_empty'     => 0,
         'regex'          => 1,
         'show_type'      => 1,
         'dropdown_value' => 0,
         'glpi_objects'   => 0,
         'ldap_values'    => 0,
      ];
   }

   public static function getJSFields() {
      $prefs = self::getPrefs();
      return "tab_fields_fields['float'] = 'showFields(" . implode(', ', $prefs) . ");';";
   }

   public function getEmptyParameters() {
      $regexDoc = '<small>';
      $regexDoc.= '<a href="http://php.net/manual/reference.pcre.pattern.syntax.php" target="_blank">';
      $regexDoc.= '('.__('Regular expression', 'formcreator').')';
      $regexDoc.= '</small>';
      return [
         'regex' => new PluginFormcreatorQuestionRegex(
            $this,
            [
               'fieldName' => 'regex',
               'label'     => __('Additional validation', 'formcreator') . $regexDoc,
               'fieldType' => ['text'],
            ]
         ),
         'range' => new PluginFormcreatorQuestionRange(
            $this,
            [
               'fieldName' => 'range',
               'label'     => __('Range', 'formcreator'),
               'fieldType' => ['text'],
            ]
         ),
      ];
   }


   public function equals($value) {
      return ((float) $this->value) === ((float) $value);
   }

   public function notEquals($value) {
      return !$this->equals($value);
   }

   public function greaterThan($value) {
      return ((float) $this->value) > ((float) $value);
   }

   public function lessThan($value) {
      return !$this->greaterThan($value) && !$this->equals($value);
   }

   public function isAnonymousFormCompatible() {
      return true;
   }
}
