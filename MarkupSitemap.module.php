<?php

/**
 * Sitemap for ProcessWire
 * Module class
 *
 * @author Mike Rockett <mike@rockett.pw>
 * @copyright 2017-19
 * @license ISC
 */

// Require the classloaders
wire('classLoader')->addNamespace('Thepixeldeveloper\Sitemap', __DIR__ . '/src/Sitemap');
wire('classLoader')->addNamespace('Rockett\Concerns', __DIR__ . '/src/Concerns');
wire('classLoader')->addNamespace('Rockett\Support', __DIR__ . '/src/Support');

use Thepixeldeveloper\Sitemap\Url;
use Thepixeldeveloper\Sitemap\Urlset;
use Thepixeldeveloper\Sitemap\Extensions\Link;
use Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;

use Rockett\Concerns;
use Rockett\Support\ParseFloat;
use Rockett\Support\ParseTimestamp;

use ProcessWire\WireException;
use ProcessWire\Page;
use ProcessWire\Language;

class MarkupSitemap extends WireData implements Module
{
  use Concerns\DebugsThings;
  use Concerns\BuildsInputFields;
  use Concerns\ConfiguresTabs;
  use Concerns\ProcessesTabs;
  use Concerns\HandlesEvents;
  use Concerns\SupportsImages;

  /**
   * Image fields: each field is mapped to the relavent
   * function for the Image sub-element
   */
  private static $imageFields = [
    'Caption' => 'description',
    'License' => 'license',
    'Title' => 'title',
    'GeoLocation' => 'geo|location|geolocation',
  ];

  /**
   * Sitemap URI
   */
  const sitemapUri = '/sitemap.xml';

  /**
   * Current request URI
   *
   * @var string
   */
  protected $requestUri = '';

  /**
   * Current UrlSet
   *
   * @var Urlset
   */
  protected $urlSet;

  /**
   * Module installer
   * Requires ProcessWire 3.0.16+
   *
   * @throws WireException
   */
  public function ___install()
  {
    if (version_compare($this->config->version, '3.0.16') < 0) {
      throw new WireException("Requires ProcessWire 3.0.16+ to run.");
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
   * Return a POSTed value or its default if not available
   *
   * @var string $valueKey
   * @var mixed $default
   * @return mixed
   */
  public function getPostedValue($valueKey, $default = false)
  {
    return $this->input->post->$valueKey ?: $default;
  }

  /**
   * Initialize the module
   *
   * @return void
   */
  public function init(): void
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

    // Add hook to render Sitemap fields on the Settings tab of each page
    if ($this->user->hasPermission('page-edit')) {
      $this->addHookAfter('ProcessPageEdit::buildFormSettings', $this, 'setupSettingsTab');
      $this->addHookAfter('ProcessPageEdit::processInput', $this, 'processSettingsTab');
    }

    // If the user can delete pages, then we need to hook into delete
    // events to remove sitemap options for deleted pages
    if ($this->user->hasPermission('page-delete')) {
      $this->addHookAfter('Pages::deleted', $this, 'deletePageSitemapOptions');
    }
  }

  /**
   * Initialize the sitemap render by getting the root URI (giving
   * consideration to multi-site setups) and passing it to the
   * first/parent recursive render-method (addPages).
   *
   * Depending on config settings entire sitemap is cached using MarkupCache or
   * WireCache, and the cache is destroyed when settings are saved and, if set
   * up, a page is saved.
   *
   * @param HookEvent $event
   * @return void
   */
  public function render(HookEvent $event): void
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
      // Get cached sitemap
      $event->return = $this->getCached($rootPage);
      header('Content-Type: application/xml', true, 200);

      // Prevent further hooks. This stops
      // SystemNotifications from displaying a 404 event
      // when /sitemap.xml is requested. Additionally,
      // it prevents further modification to the sitemap.
      $event->replace = true;
      $event->cancelHooks = true;
    }
  }

  /**
   * Get cached sitemap markup
   *
   * @param string $rootPage
   * @return string
   */
  protected function getCached(string $rootPage): string
  {
    // Bail out early if debug mode is enabled
    if ($this->config->debug) {
      header('X-Cached-Sitemap: no');
      return $this->buildNewSitemap($rootPage);
    }

    // Cache settings
    $cacheTtl = $this->cache_ttl ?: 3600;
    $cacheKey = 'MarkupSitemap';
    $cacheMethod = $this->cache_method ?: 'MarkupCache';

    // Attempt to fetch sitemap from cache
    $cache = $cacheMethod == 'WireCache' ? $this->cache : $this->modules->MarkupCache;
    $output = $cache->get($cacheKey, $cacheTtl);

    // If output is empty, generate and cache new sitemap
    if (empty($output)) {
      header('X-Cached-Sitemap: no');
      $output = $this->buildNewSitemap($rootPage);
      if ($cacheMethod == 'WireCache') {
        $cache->save($cacheKey, $output, $cacheTtl);
      } else {
        $cache->save($output);
      }
      return $output;
    }

    header('X-Cached-Sitemap: yes');
    return $output;
  }

  /**
   * Get the root page URI
   *
   * @return string
   */
  protected function getRootPageUri(): string
  {
    return (string) str_ireplace(
      trim($this->config->urls->root, '/'),
      '',
      $this->sanitizer->path(dirname($this->requestUri))
    );
  }

  /**
   * Determine if the request is valud
   *
   * @return boolean
   */
  protected function isValidRequest(): bool
  {
    $valid = (bool) (
      $this->requestUri !== null &&
      strlen($this->requestUri) - strlen(self::sitemapUri) === strrpos($this->requestUri, self::sitemapUri)
    );

    return $valid;
  }

  /**
   * Check if the language is not default and that the
   * page is not available/statused in the default language.
   *
   * @param Language $language
   * @param Page $page
   * @return bool
   */
  protected function pageLanguageInvalid(Language $language, Page $page): bool
  {
    return (!$language->isDefault() && !$page->{"status{$language->id}"});
  }

  /**
   * Determine if the site uses the LanguageSupportPageNames module.
   *
   * @return bool
   */
  protected function siteUsesLanguageSupportPageNames(): bool
  {
    return $this->modules->isInstalled('LanguageSupportPageNames');
  }

  /**
   * Add languages to the location entry.
   *
   * @param Page $page
   * @param Url $url
   * @return void
   */
  protected function addLanguages(Page $page, Url $url): void
  {
    foreach ($this->languages as $altLanguage) {
      if ($this->pageLanguageInvalid($altLanguage, $page)) continue;
      $languageIsoName = $this->getLanguageIsoName($altLanguage);
      $url->addExtension(new Link($languageIsoName, $page->localHttpUrl($altLanguage)));
    }
  }

  /**
   * Get a language's ISO name
   *
   * @param Language $laguage
   * @return string
   */
  protected function getLanguageIsoName(Language $language): string
  {
    $usesDefaultIso = $language->isDefault()
      && $this->pages->get(1)->name === 'home'
      && !$this->modules->LanguageSupportPageNames->useHomeSegment
      && !empty($this->sitemap_default_iso);

    return $usesDefaultIso
      ? $this->sitemap_default_iso
      : $this->pages->get(1)->localName($language);
  }

  /**
   * Determine if a page can be included in the sitemap
   *
   * @param Page $page
   * @param array $options
   * @return bool
   */
  public function canBeIncluded(Page $page, ?array $options): bool
  {
    // If it's the home page, it's always includible.
    if ($page->id === 1) {
      return true;
    }

    // If the page's template is excluded from accessing Sitemap,
    // then it's not includible.
    if (in_array($page->template->name, $this->sitemap_exclude_templates)) {
      return false;
    }

    // Otherwise, check to see if the page itself has been excluded
    // via Sitemap options.
    return !$options['excludes']['page'];
  }

  /**
   * Recursively add pages in each language with
   * alternate language and image sub-elements.
   *
   * @param Page $page
   * @return void
   */
  protected function addPagesFromRoot(Page $page): void
  {
    // Get the saved options for this page
    $pageSitemapOptions = $this->modules->getConfig($this, "o$page->id");

    // If the template that this page belongs to is not using sitemap options
    // (per the module's current configuration), then we need to revert the keys
    // in $pageSitemapOptions to their defaults so as to prevent their
    // saved options from being used in this cycle.
    if ($this->sitemap_include_templates !== null
      && !in_array($page->template->name, $this->sitemap_include_templates)
      && is_array($pageSitemapOptions)) {
      array_walk_recursive($pageSitemapOptions, function (&$value) {
        $value = false;
      });
    }

    // If the page is viewable and not excluded or we’re working with the root page,
    // begin generating the sitemap by adding pages recursively. (Root is always added.)
    if ($page->viewable() && $this->canBeIncluded($page, $pageSitemapOptions)) {
      // If language support is enabled, then we need to loop through each language
      // to generate <loc> for each language with all alternates, including the
      // current language. Then add image references with multi-language support.
      if ($this->siteUsesLanguageSupportPageNames()) {
        foreach ($this->languages as $language) {
          if ($this->pageLanguageInvalid($language, $page) || !$page->viewable($language)) {
            continue;
          }

          $url = new Url($page->localHttpUrl($language));
          $url->setLastMod(ParseTimestamp::fromInt($page->modified));
          $this->addLanguages($page, $url);

          if ($pageSitemapOptions['priority']) {
            $url->setPriority(ParseFloat::asString($pageSitemapOptions['priority']));
          }

          if (!$pageSitemapOptions['excludes']['images']) {
            $this->addImages($page, $url, $language);
          }

          $this->urlSet->add($url);
        }
      } else {
        // If multi-language support is not enabled, then we only need to
        // add the current URL to a new <loc>, along with images.
        $url = new Url($page->httpUrl);
        $url->setLastMod(ParseTimestamp::fromInt($page->modified));

        if ($pageSitemapOptions['priority']) {
          $url->setPriority(ParseFloat::asString($pageSitemapOptions['priority']));
        }

        if (!$pageSitemapOptions['excludes']['images']) {
          $this->addImages($page, $url);
        }

        $this->urlSet->add($url);
      }
    }

    // Check for children
    if (!$pageSitemapOptions['excludes']['children']) {

      // Build up the child selector.
      $selector = "id!={$this->config->http404PageID}";
      if ($this->sitemap_include_hidden) {
        $selector = implode(',', [
          'include=hidden',
          'template!=admin',
          $selector,
        ]);
      }

      // Check for children and include where possible.
      if ($page->hasChildren($selector)) {
        foreach ($page->children($selector) as $child) {
          $this->addPagesFromRoot($child);
        }
      }
    }
  }

  /**
   * Build a new sitemap (called when cache doesn't have one or we're debugging)
   *
   * @param string $rootPage
   * @return string
   */
  protected function buildNewSitemap(string $rootPage): string
  {
    $this->urlSet = new Urlset();
    $this->addPagesFromRoot($this->pages->get($rootPage));
    $writer = new XmlWriterDriver();

    $timestamp = date('c');
    $writer->addComment("Last generated: $timestamp");

    if ($this->sitemap_stylesheet) {
      $writer->addProcessingInstructions(
        'xml-stylesheet',
        'type="text/xsl" href="' . $this->getStylesheetUrl() . '"'
      );
    }

    $this->urlSet->accept($writer);

    return $writer->output();
  }

  /**
   * If using a stylesheet, return its absolute URL.
   *
   * @return string
   */
  protected function getStylesheetUrl(): string
  {
    if ($this->sitemap_stylesheet_custom
      && filter_var($this->sitemap_stylesheet_custom, FILTER_VALIDATE_URL)) {
      return $this->sitemap_stylesheet_custom;
    }

    return $this->urls->httpSiteModules . 'MarkupSitemap/assets/sitemap-stylesheet.xsl';
  }
}
