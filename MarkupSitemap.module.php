<?php

/**
 * Sitemap for ProcessWire
 *
 * Module class
 *
 * @author Mike Rockett
 * @copyright 2017
 * @license MIT
 */

wire('classLoader')->addNamespace('Rockett\Sitemap', __DIR__ . '/src/Sitemap');
wire('classLoader')->addNamespace('Rockett\Utilities', __DIR__ . '/src/Utilities');

use Rockett\Sitemap\Elements\Url;
use Rockett\Sitemap\Elements\Urlset;
use Rockett\Sitemap\Output;
use Rockett\Sitemap\SubElements\Image;
use Rockett\Sitemap\SubElements\Link;
use Rockett\Utilities\Fields;

class MarkupSitemap extends WireData implements Module
{
    use Fields;

    /**
     * Image fields: each field is mapped to the relavent
     * function for the Image sub-element
     */
    const IMAGE_FIELDS = [
        'Caption' => 'description',
        'License' => 'license',
        'Title' => 'title',
        'GeoLocation' => 'geo|location|geolocation',
    ];

    /**
     * Sitemap URI
     */
    const SITEMAP_URI = '/sitemap.xml';

    /**
     * Language
     *
     * @var string
     */
    protected $lang = '';

    /**
     * Current request URI
     *
     * @var string
     */
    protected $requestUri = '';

    /**
     * Page selector
     *
     * @var string
     */
    protected $selector = '';

    /* Reserved */

    /**
     * This UrlSet
     *
     * @var Urlset
     */
    protected $urlSet;

    /**
     * Install module
     * 1) Install MarkupCache
     * 2) Install new system fields
     *
     * @return void
     */
    public function ___install()
    {
        // Install MarkupCache
        $this->modules->MarkupCache;

        // Create Fieldset (open)
        $this->createField('FieldsetOpen', 'sitemap_fieldset', [
            'label' => $this->_('Sitemap'),
            'icon' => 'sitemap',
            'collapsed' => Inputfield::collapsedBlank,
        ], true);

        // Create priority field
        $this->createField('Text', 'sitemap_priority', [
            'label' => $this->_('Page Priority'),
            'description' => $this->_('Set this page’s priority on a scale of 0.0 to 1.0.'),
            'columnWidth' => '50%',
            'pattern' => "(0(\.\d+)?|1(\.0+)?)",
        ], true);

        // Create ignore-images field
        $this->createField('Checkbox', 'sitemap_ignore_images', [
            'label' => $this->_('Ignore Images'),
            'label2' => $this->_('Do not add images to the sitemap for this page’s entry'),
            'columnWidth' => '50%',
        ], true);

        // Create ignore-page field
        $this->createField('Checkbox', 'sitemap_ignore_page', [
            'label' => $this->_('Exclude Page'),
            'label2' => $this->_('Do not render include this page in sitemap.xml'),
            'columnWidth' => '50%',
        ], true);

        // Create ignore-children field
        $this->createField('Checkbox', 'sitemap_ignore_children', [
            'label' => $this->_('Exclude Children'),
            'label2' => $this->_('Do not include this page’s children (if any) in sitemap.xml'),
            'columnWidth' => '50%',
        ], true);

        // Create Fieldset (close)
        $this->createField('FieldsetClose', 'sitemap_fieldset_END', [
            'label' => $this->_('Close Sitemap'),
        ], true);
    }

    /**
     * Uninstall module and associated fields from the database
     *
     * @return void
     */
    public function ___uninstall()
    {
        $fields = $this->fields;
        foreach (MarkupSitemapConfig::getDefaultFields() as $fieldName) {
            // First remove the fields from the associated templates
            foreach ($this->templates as $template) {
                if (!$template->hasField($fieldName)) {
                    continue;
                }
                $templateFields = $template->fields;
                $templateFields->remove($fieldName);
                $templateFields->save();
            }
            // Then delete the fields
            if ($field = $fields->get($fieldName)) {
                $field->flags = Field::flagSystemOverride;
                $field->flags = 0;
                $fields->delete($field);
            }
        }
    }

    /**
     * Class constructor
     * Get and assign the current request URI
     */
    public function __construct()
    {
        $this->requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
    }

    /**
     * Initiate the module
     *
     * @return void
     */
    public function init()
    {
        // If the request is valid (/sitemap.xml)...
        if ($this->isValidRequest()) {
            // Add the relevant page hooks for multi-language support
            // as these are not bootstrapped at the 404 event (for some reason...)
            if ($this->siteUsesLanguageSupportPageNames()) {
                foreach (['localHttpUrl', 'localName'] as $pageHook) {
                    $pageHookFunction = 'hookPage' . ucfirst($pageHook);
                    $this->addHook("Page::{$pageHook}", null, function ($event) use ($pageHookFunction) {
                        $this->modules->LanguageSupportPageNames->{$pageHookFunction}($event);
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
                $settings = $form->find('name=status')->first();
                if ($settings) {
                    $form->remove($field);
                    $form->insertBefore($field, $settings);
                }
            }
        }
    }

    /**
     * Initiate the sitemap render by getting the root URI (giving
     * consideration to multi-site setups) and passing it to the
     * first/parent recursive render-method (addPages).
     *
     * MarkupCache is used to cache the entire sitemap, and the cache
     * is destroyed when settings are saved and, if set up, a page is saved.
     *
     * @param HookEvent $event
     */
    public function render(HookEvent $event)
    {
        // Get the initial root URI.
        $rootPage = $this->getRootPageUri();

        // If multi-site is present and active, prepend the subdomain prefix.
        if ($this->modules->isInstalled('MultiSite')) {
            $multiSite = $this->modules->get('MultiSite');
            if ($multiSite->subdomain) {
                $rootPage = "/{$multiSite->subdomain}{$rootPage}";
            }
        }

        // Make sure that the root page exists.
        if (!$this->pages->get($rootPage) instanceof NullPage) {
            // Check for cached sitemap or regenerate if it doesn't exist
            $rootPageName = $this->sanitizer->pageName($rootPage);
            $markupCache = $this->modules->MarkupCache;
            if ((!$output = $markupCache->get('MarkupSitemap', 3600)) || $this->config->debug) {
                $this->urlSet = new Urlset();
                $this->addPages($this->pages->get($rootPage));
                $sitemapOutput = new Output();
                if ($this->sitemap_stylesheet) {
                    if ($this->sitemap_stylesheet_custom
                        && filter_var($this->sitemap_stylesheet_custom, FILTER_VALIDATE_URL)) {
                        $stylesheetPath = $this->sitemap_stylesheet_custom;
                    } else {
                        $stylesheetPath = $this->urls->httpSiteModules . 'MarkupSitemap/assets/sitemap-stylesheet.xsl';
                    }
                    $sitemapOutput->addProcessingInstruction(
                        'xml-stylesheet',
                        'type="text/xsl" href="' . $stylesheetPath . '"'
                    );
                }
                header('X-SitemapRetrievedFromCache: no');
                $output = $sitemapOutput->setIndented(true)->getOutput($this->urlSet);
                $markupCache->save($output);
            } else {
                header('X-SitemapRetrievedFromCache: yes');
            }
            header('Content-Type: application/xml', true, 200);
            $event->return = $output;

            // Prevent further hooks. This stops
            // SystemNotifications from displaying a 404 event
            // when /sitemap.xml is requested. Additionall,
            // it prevents further modification to the sitemap.
            $event->replace = true;
            $event->cancelHooks = true;
        }
    }

    /**
     * Add alternative languges, including current.
     * @param Page $page
     * @param Url  $url
     */
    protected function addAltLanguages($page, $url)
    {
        foreach ($this->languages as $altLanguage) {
            if ($this->pageLanguageInvalid($altLanguage, $page)) {
                continue;
            }
            if ($altLanguage->isDefault()
                && $this->pages->get(1)->name === 'home'
                && !$this->modules->LanguageSupportPageNames->useHomeSegment
                && !empty($this->sitemap_default_iso)) {
                $languageIsoName = $this->sitemap_default_iso;
            } else {
                $languageIsoName = $this->pages->get(1)->localName($altLanguage);
            }
            $url->addSubElement(new Link($languageIsoName, $page->localHttpUrl($altLanguage)));
        }
    }

    /**
     * Generate an image tag for the current image in the loop
     * @param  Pageimage $image
     * @param  Language  $language
     * @return Image
     */
    protected function addImage($image, $language = null)
    {
        $locImage = new Image($image->httpUrl);
        foreach (self::IMAGE_FIELDS as $imageMetaMethod => $imageMetaValues) {
            foreach (explode('|', $imageMetaValues) as $imageMetaValue) {
                if ($language != null && !$language->isDefault() && $image->{"$imageMetaValue{$language->id}"}) {
                    $imageMetaValue .= $language->id;
                }
                if ($image->$imageMetaValue) {
                    if ($imageMetaMethod === 'License') {
                        // Skip invalid licence URLs
                        if (!filter_var($image->$imageMetaValue, FILTER_VALIDATE_URL)) {
                            continue;
                        }
                    }
                    $locImage->{"set{$imageMetaMethod}"}($image->$imageMetaValue);
                }
            }
        }

        return $locImage;
    }

    /**
     * Add images to the current Url
     * @param Url      $url
     * @param Language $language
     */
    protected function addImages($page, $url, $language = null)
    {
        // Loop through declared image fields and skip non image fields
        if ($this->sitemap_image_fields) {
            foreach ($this->sitemap_image_fields as $imageFieldName) {
                $page->of(false);
                $imageField = $page->$imageFieldName;
                if ($imageField) {
                    foreach ($imageField as $image) {
                        if ($image instanceof Pageimage) {
                            $url->addSubElement($this->addImage($image, $language));
                        }
                    }
                }
            }
        }
    }

    /**
     * Recursively add pages in each language with
     * alternate language and image sub-elements.
     * @param  $page
     * @return bool    =true
     */
    protected function addPages($page)
    {
        // If the page is viewable and not ignored or we’re working with the root page,
        // begin generating the sitemap by adding pages recursively. (Root is always added.)
        if ($page->viewable() && ($page->sitemap_ignore_page == false || $page->path === '/')) {
            // If language support is enabled, then we need to loop through each language
            // to generate <loc> for each language with all alternates, including the
            // current language. Then add image references with multi-language support.
            if ($this->siteUsesLanguageSupportPageNames()) {
                foreach ($this->languages as $language) {
                    if ($this->pageLanguageInvalid($language, $page) || !$page->viewable($language)) {
                        continue;
                    }
                    $url = new Url($page->localHttpUrl($language));
                    $url->setLastMod(date('c', $page->modified));
                    $this->addAltLanguages($page, $url);
                    if (!empty($page->sitemap_priority)) {
                        $url->setPriority($this->formatPriorityFloat($page->sitemap_priority));
                    }
                    if (!$page->sitemap_ignore_images) {
                        $this->addImages($page, $url, $language);
                    }
                    $this->urlSet->addUrl($url);
                }
            } else {
                // If multi-language support is not enabled, then we only need to
                // add the current URL to a new <loc>, along with images.
                $url = new Url($page->httpUrl);
                $url->setLastMod(date('c', $page->modified));
                if (!empty($page->sitemap_priority)) {
                    $url->setPriority($this->formatPriorityFloat($page->sitemap_priority));
                }
                if (!$page->sitemap_ignore_images) {
                    $this->addImages($page, $url);
                }
                $this->urlSet->addUrl($url);
            }
        }

        // Check for children, if allowed
        // * Recursive process
        if (!$page->sitemap_ignore_children) {
            $children = $page->children($this->selector);
            if (count($children)) {
                foreach ($children as $child) {
                    $this->addPages($child);
                }
            }
        }

        // Always return true
        return true;
    }

    /**
     * Correctly format the priority float to one decimal
     * @param  float
     * @return string
     */
    protected function formatPriorityFloat($priority)
    {
        return sprintf('%.1F', (float) $priority);
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

    /**
     * Check if the language is not default and that the
     * page is not available/statused in the default language.
     * @param  string $language
     * @param  Page   $page
     * @return bool
     */
    protected function pageLanguageInvalid($language, $page)
    {
        return (!$language->isDefault() && !$page->{"status{$language->id}"});
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
