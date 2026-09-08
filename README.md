# Decoupled Settings

[![Pipeline](https://git.drupalcode.org/project/decoupled_settings/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/decoupled_settings/-/pipelines)
[![Test](https://github.com/Decipher/decoupled_settings/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/decoupled_settings/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/decoupled_settings/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/decoupled_settings/branch/1.0.x)

Exposes allowlisted simple config over JSON:API, with global values and
per-consumer overrides.

A decoupled site can read the site name, slogan, front page, theme logo and
favicon over JSON:API, and each frontend can override any of them without
holding a copy of the settings it did not change.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/decoupled_settings).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/decoupled_settings).

## Table of contents

- Requirements
- Installation
- Configuration
- Features
- FAQ
- Maintainers

## Requirements

- PHP 8.3 or later
- Drupal 10.3 or 11
- JSON:API (Drupal core)
- [Consumers](https://www.drupal.org/project/consumers)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Installation

1. Download and install via Composer:

   ```bash
   composer require drupal/decoupled_settings
   ```

2. Enable the module:

   ```bash
   drush en decoupled_settings
   ```

## Configuration

Two screens, both under **Configuration > Services**.

| Screen | Path |
| --- | --- |
| Decoupled Settings | `/admin/config/services/decoupled-settings` |
| A consumer's overrides | `/admin/config/services/consumer/{consumer}/decoupled-settings` |

The first decides what leaves the site. It previews what a frontend would
read right now, so a key missing for lack of schema is visible rather than
silent.

| Setting | Default | Adds to the response |
| --- | --- | --- |
| Exposed config objects | `system.site` | Each listed object, schema-declared keys only |
| Expose the active theme settings | On | The default theme's settings, with logo and favicon resolved |
| Expose the active theme structure | Off | Regions, hidden regions, breakpoints, and the theme names |

The second is per consumer. Tick a setting to override it. Anything left
unticked is inherited, and follows the site value when it changes.

Reading any of this requires the **Read decoupled settings** permission,
which is not granted to anonymous users. Grant it deliberately if a frontend
reads settings without authenticating.

## Features

- Global values are read from the config objects that already hold them.
  There is no second copy of the site name to drift or sync.
- A consumer stores only the settings it overrides. Everything else is
  inherited and follows the site value.
- An override set to an empty value stays empty. Clearing the override is a
  separate action, and restores inheritance.
- Only listed config objects are exposed, and within them only the keys
  their schema declares. Internal keys are always dropped, and an exclusion
  list removes the rest. The site email address and the notification
  address are excluded by default.
- Logo and favicon come from core's theme settings resolution, as usable
  URLs with core's fallbacks.
- The active theme's structure is available as a `theme` attribute: its
  regions as machine name to label in declared order, the regions it hides,
  the admin theme and the breakpoints it declares. A frontend that lays out
  blocks by region reads them instead of hardcoding them. Off by default.
- Responses carry the config cache tags of every object read, and a cache
  context for each consumer negotiation mechanism.

## FAQ

**Q: How does a consumer identify itself?**

**A:** With the `X-Consumer-ID` request header, or the `consumerId` query
parameter. A request that names no consumer, or one that does not exist,
reads the global values.

That fallback is deliberate, and it means a typo in a build variable ships
the global branding with a green build. Assert on `data.attributes.consumer`
in your build pipeline: it echoes the consumer that was actually resolved,
and it is `null` when none was.

**Q: Does this work with Simple OAuth?**

**A:** Yes, and with no extra setup. Simple OAuth sets `X-Consumer-ID` on the
request from the token's consumer, so an authenticated app is identified by
its token alone. The OAuth client and the settings consumer are the same
entity. A functional test pins this against simple_oauth 6.x. There is no
hard dependency, so if that interop ever changes, a token-authenticated
request falls back to the global values rather than erroring: one more
reason to assert on `data.attributes.consumer`.

**Q: How do translations interact with overrides?**

**A:** Exposed values follow interface language negotiation: a request on a
language prefix reads that language's config translations. Per-consumer
overrides do not: an override replaces its setting in every language. A
setting can be translated, or overridden per consumer, not both at once.

**Q: Can a frontend write settings back?**

**A:** No. The exposed surface is read-only.

**Q: Why is the output wider than I expected?**

**A:** Every key the schema declares for an exposed object is included
unless it is excluded, and the active theme carries more settings than the
obvious branding ones. Review the live preview on the settings form and
extend the exclusion list to taste.

**Q: Why is a setting missing from the output?**

**A:** Either its config object is not on the exposure list, or the key is not
declared in that object's typed config schema, or it is on the exclusion list.
Undeclared keys are dropped rather than guessed at.

**Q: Can a module expose settings that are not config?**

**A:** Yes. Implement `hook_decoupled_settings_global_alter()` to contribute a
group of computed values. A contributed setting is overridable per consumer,
exactly like one read from config. See `decoupled_settings.api.php`.

**Q: How does a frontend know which settings group belongs to the theme?**

**A:** The structure's `settings_object` names it, so a client reads that
group rather than guessing which of the exposed groups is the theme's.

**Q: What does exposing the theme structure disclose?**

**A:** Region machine names and labels, the hidden regions, the declared
breakpoints, and the names of the default and admin themes. For a core or
contributed theme all of that is already public in its source. The admin
theme's name is the one item a decoupled site does not otherwise publish, so
a site running a custom admin theme discloses that name. Fingerprinting
rather than access, which is why the attribute is opt-in.

The structure names the admin theme but does not read it. To serve its
settings, add `claro.settings`, or whichever theme it is, to the exposure
list. That entry is literal, so unlike **Expose the active theme settings**
it will not follow a later change of admin theme.

**Q: Why does `regions_hidden` name regions that are not in `regions`?**

**A:** Because core appends `page_top` and `page_bottom` to every theme in
`system_info_alter()`, whether or not the theme declares them. On a stock
Olivero the two lists do not intersect at all: 13 regions, none of them
`page_top`, and both reserves hidden. So this, the obvious implementation,
is a no-op that looks like it works:

```js
// Wrong. On Olivero it removes nothing, and does so silently.
const visible = Object.keys(regions).filter((r) => !regions_hidden.includes(r))
```

Filter if a hidden region should not be rendered, but do not assume the
filter removed anything.

**Q: Can a consumer be given different regions, or the nesting order?**

**A:** Neither, today. The structure describes the theme, so it is the same
for every consumer and is not merged with the overrides. Nesting and render
order are not included at all: they exist only in the theme's
`page.html.twig`, and reporting them would mean parsing templates. What is
reported is the flat map the theme declares, in its declared order.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
