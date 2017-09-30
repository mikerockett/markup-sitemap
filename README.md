## Sitemap for ProcessWire

![Shield: Tagged Release](https://img.shields.io/github/release/rockettpw/markup-sitemap.svg?maxAge=7200&style=flat-square) ![Shield: Status Beta](https://img.shields.io/badge/status-beta-orange.svg?style=flat-square) ![Shield: Requires ProcessWire Versions](https://img.shields.io/badge/requires-ProcessWire--2.8.16/3.0.16+-green.svg?style=flat-square) ![Shield: License = MIT](https://img.shields.io/github/license/rockettpw/markup-sitemap.svg?style=flat-square)

An upgrade to MarkupSitemapXML by Pete, MarkupSitemap adds multi-language support using the built-in LanguageSupportPageNames. Where multi-language pages are available, they are added to the sitemap by means of an alternate link in that page’s `<url>`. Support for listing images in the sitemap on a page-by-page basis and using a sitemap stylesheet are also added.

---

### Getting Started

In ProcessWire, install MarkupSitemap via the module installer. Enter `MarkupSitemap` into Modules > Install > New > Add Module from Directory. After installation, the sitemap will immediately be made available at `/sitemap.xml`.

If you’re looking for a basic sitemap, there’s nothing more you need to do. 🎇

---

### Configuration

If you’d like to fine-tune things a little, the module provides support for page-by-page configuration. If you’d like to make use of this, head to the module’s configuration page to get started.

#### Templates with sitemap options

With this option, you can select which templates (and, therefore, all pages assigned to those templates) can have individual sitemap options. These options allow you to —

- set which pages and, optionally, their children should be excluded from the sitemap (these options are independent of one another, so have the ability to hide a parent, but keep it’s children);
- define which page’s images should not be included in the sitemap (provided that image fields have been configured); and
- set an optional priority for each page.

When you add a template to the list and save, sitemap options will be added to the selected templates, and will be made available in the Settings tab of each page those templates use.

**Please use with caution:** If you remove any templates from the list, any sitemap options saved for pages using those templates will be discarded when you save the configuration as the fields are completely removed from the assigned templates.

Also note that the home page cannot be excluded from the sitemap. As such, the applicable options will not be available for the home page.

#### Image fields

If you’d like to include images in your sitemap (for somewhat enhanced Google Images support), you can specify the image fields you’d like MarkupSitemap to traverse and include. The sitemap will include images for every page that uses the field(s) you select, except for pages that are set to not have their images included (Settings tab).

#### Stylesheet

In the module’s configuration, you can also enable the default stylesheet. If you’d like to use your own, you’ll need to specify an absolute URL to it (also be sure to use one that has mult-language and sub-element features).

#### ISO code for default language

If you’ve set your home page to not include a language ISO (default language name) **and** your home page’s default language name is empty, then you can set an ISO code here for the default language. This will prevent the sitemap from containing `hreflang="home"` for all default-language URLs.

#### Page priority

On each page that has sitemap options, you can set a priority between 0.0 and 1.0. You may not need to use this any many cases, but you may wish to give emphasis to certain child pages over their parents. Search engines tend to use other factors in determining priority, and so this option is not guaranteed to make a difference to your rankings.

---

### Discussion & Support

Visit [processwire.com/talk/topic/17068-markupsitemap/](https://processwire.com/talk/topic/17068-markupsitemap/) to discuss the module and obtain support.

---

### Credits

I’d like to thank [Mathew Davies](https://github.com/ThePixelDeveloper) for his [sitemap package](https://github.com/ThePixelDeveloper/Sitemap). It’s really great, sans a few bugs (which is why a local fork is maintained within this module).


---

Module is released under the [MIT License](LICENSE.md).