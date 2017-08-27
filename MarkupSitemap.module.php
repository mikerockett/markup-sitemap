<?php

require_once __DIR__ . '/vendor/autoload.php';

use Thepixeldeveloper\Sitemap\Output;
use Thepixeldeveloper\Sitemap\Subelements\Link;
use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Urlset;

class MarkupSitemap extends WireData implements Module
{
    /**
     * LanguageSupport module name
     */
    const LANGUAGE_SUPPORT_PAGE_NAMES_MODULE = 'LanguageSupportPageNames';

    /**
     * MarkupCache module name
     */
    const MARKUP_CACHE_MODULE = 'MarkupCache';

    /**
     * MultiSite module name
     */
    const MULTI_SITE_MODULE = 'MultiSite';

    /**
     * Sitemap URI
     */
    const SITEMAP_URI = '/sitemap.xml';

    /**
     * Language
     * @var string
     */
    protected $lang = '';

    /**
     * Current request URI
     * @var string
     */
    protected $requestUri = '';

    /**
     * Page selector
     * @var string
     */
    protected $selector = '';

    /**
     * Subdomain (multi-site support)
     * @var string
     */
    protected $subdomain = '';

    /**
     * This UrlSet
     * @var Urlset
     */
    protected $urlSet;

    /**
     * Install module
     * @return void
     */
    public function ___install()
    {
        $this->createField('FieldsetOpen', 'sitemap_fieldset', [
            'label' => $this->_('XML Sitemap'),
            'description' => 'These options are specific to MarkupSitemap, and allow you to select whether or not this Page (and, optionally, its children) should be rendered in the sitemap.',
            'icon' => 'sitemap',
            'collapsed' => Inputfield::collapsedBlank,
        ], true);

        $this->createField('FieldsetClose', 'sitemap_fieldset_END', [
            'label' => $this->_('Close XML Sitemap'),
        ], true);

        $this->createField('Checkbox', 'sitemap_ignore_page', [
            'label' => $this->_('Exclude Page'),
            'label2' => $this->_('Exclude this Page from being rendered in the XML sitemap'),
        ], true);

        $this->createField('Checkbox', 'sitemap_ignore_children', [
            'label' => $this->_('Exclude Children'),
            'label2' => $this->_('Exclude this Page’s children from being rendered in the XML sitemap'),
            'notes' => $this->_('This option is independent of the option above which, if not checked, means that only this page’s children will be excluded when this option is checked.'),
        ], true);
    }

    /**
     * Uninstall module
     * @return void
     */
    public function ___uninstall()
    {
        $fields = $this->fields;
        foreach (MarkupSitemapConfig::getDefaultFields() as $fieldName) {
            foreach ($this->templates as $template) {
                if (!$template->hasField($fieldName)) {
                    continue;
                }
                $templateFields = $template->fields;
                $templateFields->remove($fieldName);
                $templateFields->save();
            }
            $field = $fields->get($fieldName);
            $field->flags = Field::flagSystemOverride;
            $field->flags = 0;
            $fields->delete($field);
        }
    }

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
    }

    /**
     * Initiate the module
     * @return void
     */
    public function init()
    {
        // If the request is valid (/sitemap.xml)...
        if ($this->isValidRequest()) {
            // Add the relevant page hooks for multi-language support
            // as these are not bootstrapped at the 404 event (for some reason...)
            if ($this->modules->isInstalled(self::LANGUAGE_SUPPORT_PAGE_NAMES_MODULE)) {
                foreach (['localHttpUrl', 'localName'] as $pageHook) {
                    $pageHookFunction = 'hookPage' . ucfirst($pageHook);
                    $this->addHook("Page::{$pageHook}", null, function ($event) use ($pageHookFunction) {
                        $this->modules->{self::LANGUAGE_SUPPORT_PAGE_NAMES_MODULE}->{$pageHookFunction}($event);
                    });
                }
            }
            // Add the hook to process and render the sitemap.
            $this->addHookBefore('ProcessPageView::pageNotFound', $this, 'render');
        }

        // Add a hook that moves the XML Sitemap fields to the Settings tab
        $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'moveSitemapFields');
    }

    /**
     * Move sitemap fields
     * @param HookEvent $event
     */
    public function moveSitemapFields(HookEvent $event)
    {
        // Get the form
        $form = $event->return;

        // Loop through the sitemap fields and move them to just before
        // the status field.
        foreach (MarkupSitemapConfig::getDefaultFields() as $fieldName) {
            $field = $form->find("name={$fieldName}")->first();
            if ($field) {
                $settings = $form->find("name=status")->first();
                if ($settings) {
                    $form->remove($field);
                    $form->insertBefore($field, $settings);
                }
            }
        }
    }

    /**
     * Render the sitemap
     * @param HookEvent $event
     */
    public function render(HookEvent $event)
    {
        // Get the initial root URI.
        $rootPage = $this->getRootPageUri();

        // If multi-site is present and active, prepend the subdomain prefix.
        if ($this->modules->isInstalled(self::MULTI_SITE_MODULE)) {
            $multiSite = $this->modules->get(self::MULTI_SITE_MODULE);
            if ($multiSite->subdomain) {
                $rootPage = "/{$multiSite->subdomain}{$rootPage}";
            }
        }

        // Make sure that the root page exists.
        if ($this->pages->get($rootPage) instanceof NullPage) {
            return;
        }

        // Check for cached sitemap or regenerate if it doesn't exist
        $rootPageName = $this->sanitizer->pageName($rootPage);
        $markupCache = $this->modules->{self::MARKUP_CACHE_MODULE};
        if (!$output = $markupCache->get('MarkupSitemap', 3600)) {
            $this->urlSet = new Urlset();
            $this->addUrls($this->pages->get($rootPage));
            $sitemapOutput = new Output();
            $output = $sitemapOutput->setIndented(true)->getOutput($this->urlSet);
            $markupCache->save($output);
        }
        header('Content-Type: text/xml', true, 200);
        print $output;
        exit;
    }

    /**
     * @param $page
     */
    protected function addUrls($page)
    {
        // Add this page
        if ($page->viewable() && ($page->sitemap_ignore_page == false || $page->path === '/')) {
            $url = new Url($page->httpUrl);
            $url->setLastMod(date('c', $page->modified));
            // Add multi-language alternates (if available)
            if ($this->modules->isInstalled(self::LANGUAGE_SUPPORT_PAGE_NAMES_MODULE)) {
                foreach ($this->languages as $language) {
                    if (!$language->isDefault() && !$page->{"status{$language->id}"}) {
                        continue;
                    }
                    $languageIsoName = $this->pages->get(1)->localName($language);
                    $alternateLink = new Link($languageIsoName, $page->localHttpUrl($language));
                    $url->addSubElement($alternateLink);
                }
            }
            $this->urlSet->addUrl($url);
        }

        // Check for children if allowed
        if ($page->sitemap_ignore_children != true) {
            $children = $page->children($this->selector);
            if (count($children)) {
                foreach ($children as $child) {
                    $this->addUrls($child);
                }
            }
        }

        // Always return true
        return true;
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
        $field = new Field();
        $fieldType = "Fieldtype{$fieldType}";
        $field->type = $this->modules->$fieldType;
        $field->name = $name;
        if ($system === true) {
            $field->set('flags', Field::flagSystem);
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

    /**
     * Get the root page URI
     * @return string
     */
    protected function getRootPageUri()
    {
        return (string) str_ireplace(trim($this->config->urls->root, '/'), '', $this->sanitizer->path(dirname($this->requestUri)));
    }

    /**
     * Determine if the request is valud
     * @return boolean
     */
    protected function isValidRequest()
    {
        $valid = (bool) (
            $this->requestUri !== null &&
            strlen($this->requestUri) - strlen(self::SITEMAP_URI) === strrpos($this->requestUri, self::SITEMAP_URI)
        );

        return $valid;
    }
}
