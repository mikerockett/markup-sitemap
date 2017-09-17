<?php

/**
 * Field helpers for ProcessWire
 * Allows field creation and rendering on the fly.
 * Add namespace and `use Fields` in your class.
 *
 * @author  Mike Rockett
 * @license MIT
 */

namespace Rockett\Utilities;

trait Fields
{
    /**
     * Given a fieldtype, create, populate, and return an Inputfield
     * @param  string       $fieldNameId
     * @param  array        $meta
     * @return Inputfield
     */
    protected function buildInputField($fieldNameId, $meta)
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

    /**
     * Given a fieldtype, name, and attributes, create and save a new Field.
     * @param  string       $fieldType
     * @param  string       $name
     * @param  array        $meta
     * @return Field|bool
     */
    protected function createField($fieldType, $name, $meta, $system = false)
    {
        if ($this->fields->get($name)) {
            return false;
        }

        // Set the initial properties
        $field = new \Field();
        $field->type = $this->modules->{"Fieldtype{$fieldType}"};
        $field->name = $name;
        if ($system === true) {
            $field->set('flags', \Field::flagSystem);
        }

        // Unset extra meta (already used)
        unset($meta['type']);
        unset($meta['name']);

        // Add meta
        foreach ($meta as $metaNames => $metaInfo) {
            $metaNames = explode('+', $metaNames);
            foreach ($metaNames as $metaName) {
                $field->$metaName = $metaInfo;
            }
        }

        $field->save();

        return $field;
    }
}
