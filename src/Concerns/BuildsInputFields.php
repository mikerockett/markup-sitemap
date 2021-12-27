<?php

namespace Rockett\Concerns;

use ProcessWire\Inputfield;

trait BuildsInputFields
{
  /**
   * Given a fieldtype, create, populate, and return an Inputfield
   *
   * @param string $fieldNameId
   * @param array $meta
   *
   * @return Inputfield
   */
  protected function buildInputField($fieldNameId, $meta): Inputfield
  {
    $field = $this->modules->{"Inputfield{$fieldNameId}"};
    foreach ($meta as $metaNames => $metaInfo) {
      $metaNames = explode('+', $metaNames);
      foreach ($metaNames as $metaName) {
        $field->$metaName = $metaInfo;
      }
    }

    return $field;
  }
}
