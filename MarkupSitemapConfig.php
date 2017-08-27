<?php

/**
 * Sitemap for ProcessWire
 *
 * Module config class
 *
 * @author Mike Rockett
 * @copyright 2017
 * @license MIT
 */

class MarkupSitemapConfig extends ModuleConfig
{
    /**
     * Get the default system fields created by the module
     * @return array
     */
    public static function getDefaultFields()
    {
        return [
            'sitemap_fieldset',
            'sitemap_ignore_page',
            'sitemap_ignore_children',
            'sitemap_fieldset_END',
        ];
    }

    /**
     * Get default configuration, automatically passed to input fields.
     * @return array
     */
    public function getDefaults()
    {
        return [
        ];
    }

    /**
     * Render input fields on config Page.
     * @return string
     */
    public function getInputFields()
    {
        // Gather a list of templates
        $allTemplates = $this->templates;
        foreach ($allTemplates as $template) {
            // Exclude system templates
            if ($template->flags & Template::flagSystem) {
                continue;
            }
            // Also exclude the home template
            if ($template->id !== $this->pages->get(1)->template->id) {
                $templates[] = $template;
            }
        }

        // Add/remove sitemap fields from templates
        if ($this->input->post->submit_save_module) {
            $includedTemplates = (array) $this->input->post->sitemap_include_templates;
            foreach ($templates as $template) {
                if (in_array($template->name, $includedTemplates)) {
                    if ($template->hasField('sitemap_fieldset')) {
                        continue;
                    } else {
                        $sitemapFields = self::getDefaultFields();
                        unset($sitemapFields[count($sitemapFields) - 1]);
                        foreach ($this->fields as $sitemapField) {
                            if (preg_match('%^sitemap_(.*)%Uis', $sitemapField->name) && !in_array($sitemapField->name, self::getDefaultFields())) {
                                array_push($sitemapFields, $sitemapField->name);
                            }
                        }
                        array_push($sitemapFields, 'sitemap_fieldset_END');
                        foreach ($sitemapFields as $templateField) {
                            $template->fields->add($this->fields->get($templateField));
                        }
                        $template->fields->save();
                    }
                } else {
                    if ($template->hasField('sitemap_fieldset')) {
                        foreach ($template->fields as $templateField) {
                            if (in_array($templateField->name, self::getDefaultFields())) {
                                $template->fields->remove($templateField);
                            }
                        }
                        $template->fields->save();
                    } else {
                        continue;
                    }
                }
            }
        }

        // Start inputfields
        $inputfields = parent::getInputfields();

        // Add the template-selector field
        $includeTemplatesField = $this->buildInputField('InputfieldAsmSelect', [
            'name+id' => 'sitemap_include_templates',
            'label' => 'Templates with Sitemap options',
            'description' => $this->_('Select which Templates (and, therefore, all their Pages) can have individual Sitemap options. Such options are saved on a per-page basis.'),
            'notes' => 'If you remove any templates from this list, any data saved for Pages using those templates will be discarded when you save this configuration. Please use with caution.',
            'icon' => 'cubes',
        ]);
        foreach ($templates as $template) {
            $includeTemplatesField->addOption($template->name, $template->get('label|name'));
        }
        $inputfields->add($includeTemplatesField);

        // Add the image-field-selector field
        $imageFieldsField = $this->buildInputField('InputfieldAsmSelect', [
            'name+id' => 'sitemap_image_fields',
            'label' => $this->_('Image Fields'),
            'description' => $this->_('If you’d like to include images in your sitemap (for somewhat enhanced Google Images support), specify the image fields you’d like MarkupSitemap to traverse and include. The sitemap will include images for every Page that uses the field(s) you select below.'),
            'icon' => 'image',
        ]);
        foreach ($this->fields as $field) {
            $fieldType = $field->get('type')->className;
            if ($fieldType === 'FieldtypeImage') {
                $imageFieldsField->addOption($field->name, "{$field->get('label|name')} (used in {$field->numFieldgroups()} templates)");
            }
        }
        $inputfields->add($imageFieldsField);

        return $inputfields;
    }

    /**
     * Given a fieldtype, create, populate, and return an Inputfield
     * @param  string       $fieldNameId
     * @param  array        $meta
     * @return Inputfield
     */
    protected function buildInputField($fieldNameId, $meta)
    {
        $field = $this->modules->$fieldNameId;

        foreach ($meta as $metaNames => $metaInfo) {
            $metaNames = explode('+', $metaNames);
            foreach ($metaNames as $metaName) {
                $field->$metaName = $metaInfo;
            }
        }

        return $field;
    }
}
