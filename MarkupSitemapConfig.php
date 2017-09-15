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
            'sitemap_priority',
            'sitemap_ignore_images',
            'sitemap_ignore_page',
            'sitemap_ignore_children',
            'sitemap_fieldset_END',
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
            $templates[] = $template;
        }

        // If saving ...
        if ($this->input->post->submit_save_module) {
            // Remove sitemap cache
            if ($this->removeSitemapCache()) {
                $this->message($this->_('Removed sitemap cache'));
            }
            // Add/remove sitemap fields from templates
            $includedTemplates = (array) $this->input->post->sitemap_include_templates;
            foreach ($templates as $template) {
                if (in_array($template->name, $includedTemplates)) {
                    if ($template->hasField('sitemap_fieldset')) {
                        continue;
                    } else {
                        $sitemapFields = self::getDefaultFields();
                        unset($sitemapFields[count($sitemapFields) - 1]);
                        foreach ($this->fields as $sitemapField) {
                            if (preg_match('%^sitemap_.*%Uis', $sitemapField->name)
                                && !in_array($sitemapField->name, self::getDefaultFields())) {
                                array_push($sitemapFields, $sitemapField->name);
                            }
                        }
                        array_push($sitemapFields, 'sitemap_fieldset_END');
                        foreach ($sitemapFields as $templateField) {
                            if ($template->id === $this->pages->get(1)->template->id
                                && in_array($templateField, ['sitemap_ignore_page',
                                    'sitemap_ignore_children'])) {
                                continue;
                            }
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
        $includeTemplatesField = $this->buildInputField('AsmSelect', [
            'name+id' => 'sitemap_include_templates',
            'label' => 'Templates with sitemap options',
            'description' => $this->_('Select which templates (and, therefore, all pages assigned to those templates) can have individual sitemap options. These options allow you to set which pages and, optionally, their children should be excluded from the sitemap when it is rendered; define which page’s images should not be included in the sitemap (provided that image fields have been added below); and set an optional priority for each page.'),
            'notes' => '**Please use with caution:** If you remove any templates from this list, any sitemap options saved for pages using those templates will be discarded when you save this configuration as the fields are completely removed from the assigned templates. Also note that the home page cannot be excluded from the sitemap. As such, the applicable options will not be available for the home page.',
            'icon' => 'cubes',
            'collapsed' => Inputfield::collapsedBlank,
        ]);
        foreach ($templates as $template) {
            $includeTemplatesField->addOption($template->name, $template->get('label|name'));
        }
        $inputfields->add($includeTemplatesField);

        // Add the image-field-selector field
        $imageFieldsField = $this->buildInputField('AsmSelect', [
            'name+id' => 'sitemap_image_fields',
            'label' => $this->_('Image fields'),
            'description' => $this->_('If you’d like to include images in your sitemap (for somewhat enhanced Google Images support), specify the image fields you’d like MarkupSitemap to traverse and include. The sitemap will include images for every page that uses the field(s) you select below, except for pages that are set to not have their images included.'),
            'icon' => 'image',
            'collapsed' => Inputfield::collapsedBlank,
        ]);
        foreach ($this->fields as $field) {
            $fieldType = $field->get('type')->className;
            if ($fieldType === 'FieldtypeImage') {
                $imageFieldsField->addOption($field->name, "{$field->get('label|name')} (used in {$field->numFieldgroups()} templates)");
            }
        }
        $inputfields->add($imageFieldsField);

        // Add the stylesheet checkbox
        $inputfields->add($this->buildInputField('Checkbox', [
            'name+id' => 'sitemap_stylesheet',
            'label' => $this->_('Sitemap Stylesheet'),
            'label2' => $this->_('Add a stylesheet to the sitemap'),
            'icon' => 'css3',
        ]));

        // Add the custom stylesheet text field
        $inputfields->add($this->buildInputField('Text', [
            'name+id' => 'sitemap_stylesheet_custom',
            'label' => $this->_('Custom Stylesheet'),
            'description' => $this->_('If you would like to use your own stylesheet, enter the absolute URL to its file here.'),
            'placeholder' => $this->_('Example: https://example.tld/assets/sitemap-stylesheet.xsl'),
            'showIf' => 'sitemap_stylesheet=1',
            'notes' => $this->_('The default stylesheet is located at **assets/sitemap-stylesheet.xsl** in the module’s directory. If you leave this field blank or your input is not a valid URL, the default will be used.'),
            'icon' => 'file-o',
            'collapsed' => Inputfield::collapsedBlank,
        ]));

        // Add the default-language iso text field
        if ($this->siteUsesLanguageSupportPageNames()) {
            $inputfields->add($this->buildInputField('Text', [
                'name+id' => 'sitemap_default_iso',
                'label' => $this->_('ISO code for default language'),
                'description' => $this->_('If you’ve set your home page to not include a language ISO (default language name) **and** your home page’s default language name is empty, then you can set an ISO code here for the default language. This will prevent the sitemap from containing `hreflang="home"` for all default-language URLs.'),
                'notes' => $this->_('Note that if your home page has a name for the default language, then this option will not take any effect.'),
                'placeholder' => $this->_('en'),
                'icon' => 'language',
                'collapsed' => Inputfield::collapsedBlank,
            ]));
        }

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
     * Remove the sitemap cache
     * @return bool
     */
    protected function removeSitemapCache()
    {
        $cachePath = $this->config->paths->cache . 'MarkupCache/MarkupSitemap';

        try {
            $removed = (bool) CacheFile::removeAll($cachePath, true);
        } catch (\Exception $e) {
            $removed = false;
        }

        return $removed;
    }

    /**
     * Determine if the site uses the LanguageSupportPageNames module.
     * @return bool
     */
    protected function siteUsesLanguageSupportPageNames()
    {
        return $this->modules->isInstalled('LanguageSupportPageNames');
    }
}
