# Changelog

All notable changes to Decoupled Settings are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0-beta2 (2026-08-26)

### Security
- The internal page cache could serve one consumer's settings to every other
  consumer. The request policy compared the raw request path to the endpoint,
  and the router accepts spellings that comparison never saw: a language
  prefix, a trailing slash, upper case, percent-encoding. Each one let a
  header-identified response into a cache that looks responses up by URL
  alone. The policy now denies on the header alone, see Changed. Covered by
  unit and functional tests over every spelling.
- `system.site:mail_notification` was exposed by default, next to the
  `system.site:mail` it already excluded. It is excluded now, and an update
  hook appends it for existing installs that have not edited the list.

### Changed
- A request that carries `X-Consumer-ID` no longer uses the internal page
  cache on any path, not only the settings endpoint. Request policies run
  before routing, so a path cannot be named safely there. The dynamic page
  cache still serves such requests, varied on the header. Sites that relied
  on the internal page cache for other pages fetched with that header will
  see those pages come from the dynamic page cache instead.
- The consumer overrides form keeps an override whose setting is no longer
  exposed, instead of deleting it on the next save. Such overrides are named
  in a warning on the form. A theme switch renames every theme setting key,
  which used to lose every consumer's theme overrides silently.

### Added
- A functional test that pins the Simple OAuth interop: a client credentials
  token alone identifies the consumer, no header or query parameter needed.
  `drupal/simple_oauth` is a development dependency for it.
- A kernel test that pins the language behaviour: exposed values follow
  interface language negotiation, and a consumer override applies in every
  language.
- README notes for integrators: assert on `data.attributes.consumer` in the
  build pipeline, how translations and overrides interact, and why the
  default payload is wider than the branding keys.

### Known limitations
- A consumer override applies in every language. A setting can be
  translated, or overridden per consumer, not both at once. Per-language
  overrides are planned for 1.1.0.
- Per-consumer include and exclude lists are designed and not built.

### Errata for 1.0.0-beta1
- The beta1 notes said Drupal 10 was unverified. The release CI ran the full
  suite green on Drupal 10 and 11, so it was verified at the tag.
- The beta1 notes said language negotiation was not handled. Exposed values
  did follow it. What is missing is per-language overrides, above.

## 1.0.0-beta1 (2026-08-25)

### Added

- A read-only JSON:API resource at `/jsonapi/decoupled/settings`, built on
  JSON:API Resources. The response carries the resolved settings and the
  negotiated consumer, with the config cache tags of every contributing
  object and cache contexts for both negotiation mechanisms. A page-cache
  request policy keeps header-identified requests out of the internal page
  cache, which looks responses up by URL alone and would otherwise serve one
  consumer's settings to the next. Proven over HTTP with both page caches on.
- The exposure form now adds config objects from a grouped list of everything
  the site holds, instead of a typed textarea, and the review table reflects
  unsaved selections so the effect of a change is visible before saving.
  "Never exposed keys" is renamed to "Excluded settings".
- The overrides form is reachable from tabs on the consumer pages and from
  the consumer list's operations dropbutton, has a filter box, full-width
  value fields with per-type placeholders, and validates each submitted value
  against the type of the setting it overrides. Values keep their type: an
  integer setting is stored and served as an integer.
- `hook_decoupled_settings_global_alter()` runs before the merge, so a
  contributed setting is overridable per consumer like any config-derived
  one.

- Settings resolution that reads global values from the config objects that
  already hold them, rather than storing a second copy. An editor changing
  site information is what a frontend reads, with no sync step.
- Sparse per-consumer overrides, stored on the consumer entity. A consumer
  holds only the settings it changes, and everything else resolves to the
  global value.
- Per-key merging, so overriding one key of a config object leaves the other
  keys of that object inherited.
- A distinction between "not overridden" and "overridden to an empty value".
  An empty string, zero, `NULL` or `FALSE` stored as an override is served as
  that value, and clearing an override restores inheritance.
- Two-level exposure bounding. An administrator lists which config objects are
  exposed, and typed config schema decides which keys within them appear and
  with what primitive type.
- Theme settings read through core's own resolution, so `logo` and `favicon`
  are resolved URLs for the theme in question, with core's fallbacks, rather
  than raw config paths.
- A version shim for theme settings, using `ThemeSettingsProvider` where it
  exists and `theme_get_setting()` below Drupal 11.3.
- Cacheability collection, so a response carries the config cache tags of every
  object that contributed to it.
- Pruning of stored overrides whose setting is no longer exposed.
- An exposure list form at `/admin/config/services/decoupled-settings`, with a
  preview of what a frontend reads right now. Objects that do not exist or
  have no schema are rejected on save, and keys that lack schema are visible
  by their absence in the preview rather than silent.
- A per-consumer overrides form on the consumer, listing every exposed setting
  with the value it inherits. Tick a setting to override it. Unticking removes
  the override, so the setting inherits again. A submitted value is cast back
  to the type of the value it overrides.
- An overrides count column in the consumer list, so differing consumers are
  visible at a glance.
- `hook_decoupled_settings_global_alter()`, letting a module contribute
  computed settings that are not config objects. A contributed setting is
  overridable per consumer, exactly like one read from config.
- Help text on both administrative pages.
- Unit coverage of the merge and the theme settings reader, kernel coverage
  of resolution, theme reading and the forms against real config, and
  functional coverage of both administrative pages.

### Known limitations
- Only Drupal 11 has been exercised. The version shim exists for Drupal 10 and
  is the one branch Drupal 11 never runs, so it is unverified.
- Translation and language negotiation of exposed values is not handled.
