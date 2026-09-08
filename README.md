# Decoupled Settings

[![Pipeline](https://git.drupalcode.org/project/decoupled_settings/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/decoupled_settings/-/pipelines)
[![Test](https://github.com/Decipher/decoupled_settings/actions/workflows/test.yml/badge.svg?branch=1.0.x)](https://github.com/Decipher/decoupled_settings/actions/workflows/test.yml?query=branch%3A1.0.x)
[![Coverage](https://codecov.io/gh/Decipher/decoupled_settings/branch/1.0.x/graph/badge.svg)](https://codecov.io/gh/Decipher/decoupled_settings/branch/1.0.x)

Exposes allowlisted simple config over JSON:API, with global values and
per-consumer overrides.

A decoupled site can read the site name, slogan, front page, theme logo and
favicon over JSON:API. Simple config is the configuration that is not an
entity, such as `system.site`, which core's JSON:API cannot serve.

A consumer is a record of one frontend application, created in Drupal by the
[Consumers](https://www.drupal.org/project/consumers) module. Each one can be
given its own value for any exposed setting.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/decoupled_settings).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/decoupled_settings).

## Table of contents

- Requirements
- Installation
- The endpoint
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

## The endpoint

One route, read-only:

```
GET /jsonapi/decoupled/settings
```

A consumer names itself with the `X-Consumer-ID` header or the `consumerId`
query parameter. The value is the consumer's **Client ID**, shown on the
consumer edit form, not its label or its numeric ID.

```bash
curl -H 'X-Consumer-ID: partner_frontend' \
  https://example.com/jsonapi/decoupled/settings
```

A response, trimmed to one key per group:

```json
{
  "data": {
    "type": "decoupled_settings--settings",
    "id": "decoupled-settings",
    "attributes": {
      "consumer": "partner_frontend",
      "settings": {
        "system.site": {
          "name": "Partner Portal",
          "slogan": "Same code, different consumer",
          "page": { "front": "/node", "403": "", "404": "" }
        },
        "olivero.settings": {
          "logo": { "url": "/sites/default/files/logo.svg", "use_default": false },
          "favicon": { "url": "/core/themes/olivero/favicon.ico", "use_default": true }
        }
      },
      "theme": {
        "default": "olivero",
        "admin": "claro",
        "settings_object": "olivero.settings",
        "regions": { "header": "Header", "content": "Content" },
        "regions_hidden": ["page_top", "page_bottom"],
        "breakpoints": {
          "olivero.md": {
            "label": "Medium",
            "mediaQuery": "all and (min-width: 700px)",
            "weight": 1,
            "multipliers": ["1x"]
          }
        }
      }
    }
  }
}
```

Every exposed key is present with its resolved value, whether it was
overridden or inherited, so a missing key means it was never exposed. An
override set to an empty string arrives as `""`, not as `null` and not
absent.

`settings` is keyed by config object name, so those keys contain dots and
have to be read as `attributes.settings["system.site"]`. `theme` is `null`
unless the theme structure is exposed.

`logo.url` and `favicon.url` are `null` when the site has no image to point
at, which happens when the default is switched off and no file is set.
Handle that, rather than assuming a string.

## Configuration

Two screens, both under **Configuration > Services**.

| Screen | Path |
| --- | --- |
| Decoupled Settings | `/admin/config/services/decoupled-settings` |
| A consumer's overrides | `/admin/config/services/consumer/{consumer}/decoupled-settings` |

The Decoupled Settings screen decides what leaves the site. It previews what
a frontend would read right now, so a key the schema does not declare shows
up as missing instead of dropping silently.

| Setting | Default | Adds to the response |
| --- | --- | --- |
| Exposed config objects | `system.site` | Each listed object, schema-declared keys only |
| Expose the active theme settings | On | The default theme's settings, with logo and favicon resolved |
| Expose the active theme structure | Off | The `theme` attribute |

The consumer screen holds one consumer's overrides, and is reached from the
consumer itself rather than from the Services menu. Tick a setting to
override it. Anything left unticked is inherited, and follows the site value
when it changes.

Reading any of this requires the **Read decoupled settings** permission,
which is not granted to anonymous users. Granting it to the anonymous role
at `/admin/people/permissions` makes every exposed key public.

## Features

- Global values are read from the config objects that already hold them.
  There is no second copy of the site name to drift or sync.
- An override set to an empty value stays empty. Clearing the override is a
  separate action that restores inheritance.
- Only listed config objects are exposed, and within them only the keys
  their schema declares. Internal keys are always dropped, and an exclusion
  list removes the rest. The site email address and the notification
  address are excluded by default.
- Logo and favicon come from core's theme settings resolution, as usable
  URLs with core's fallbacks.
- The active theme's structure is available as a `theme` attribute, so a
  frontend that lays out blocks by region reads that map instead of
  hardcoding it.
- Responses carry the config cache tags of every object read, and a cache
  context for the `X-Consumer-ID` header and the `consumerId` query
  parameter.

## FAQ

**Q: How does a consumer identify itself?**

**A:** With the `X-Consumer-ID` request header, or the `consumerId` query
parameter. A request that does not name a consumer, or names one that does
not exist, reads the global values.

The fallback is deliberate, but it hides typos. A misspelt consumer ID in a
build variable serves the global branding, and the build still passes.
Assert on `data.attributes.consumer`, which names the consumer that was
resolved, or `null` when none was.

**Q: Does this work with Simple OAuth?**

**A:** Yes, and with no extra setup. Simple OAuth reads the token's consumer
and sets `X-Consumer-ID` on the request, so an authenticated app is
identified by its token alone. The OAuth client and the settings consumer
are the same entity. A functional test pins this against simple_oauth 6.x.

Simple OAuth is not a hard dependency. If that header ever stops being set,
a token-authenticated request reads the global values and does not error.
Assert on `data.attributes.consumer` to catch that.

**Q: How do translations interact with overrides?**

**A:** Exposed values follow interface language negotiation, so a request on
a language prefix reads that language's config translations. Per-consumer
overrides do not: an override replaces its setting in every language. Do
both and the override wins, in every language.

**Q: Can a frontend write settings back?**

**A:** No. The exposed surface is read-only.

**Q: Why is a setting missing, or one I did not expect present?**

**A:** Everything the schema declares for an exposed object is included
unless it is excluded, and the active theme holds more settings than the
obvious branding ones. A key is absent for one of three reasons.

| Cause | Fix |
| --- | --- |
| Its config object is not on the exposure list | Add the object |
| The object's typed config schema does not declare the key | Nothing here. Undeclared keys are dropped rather than guessed at |
| The key is on the exclusion list | Remove it from the list |

The live preview on the settings form shows the current answer for every
key, so check there before changing anything.

**Q: Can a module expose settings that are not config?**

**A:** Yes. Use `hook_decoupled_settings_global_alter()` to contribute a
group of computed values. A contributed setting is overridable per consumer,
exactly like one read from config. See `decoupled_settings.api.php`.

**Q: What is in the theme structure?**

**A:** Six keys, when **Expose the active theme structure** is on.

| Key | Holds |
| --- | --- |
| `default` | The active theme's machine name |
| `admin` | The admin theme's machine name, or `null` |
| `settings_object` | The config object the theme's settings are read from |
| `regions` | Machine name to label, in the theme's declared order |
| `regions_hidden` | The regions the theme hides. See below, this is not a subset of `regions` |
| `breakpoints` | Each declared breakpoint's label, media query, weight and multipliers |

`settings_object` is the one to reach for first. It names which of the
exposed settings groups belongs to the theme, so a client reads that group
instead of guessing.

**Q: What does exposing the theme structure disclose?**

**A:** For a core or contributed theme, everything in the table above is
already public in its source. The admin theme's name is the one item a
decoupled site does not otherwise publish, so a site running a custom admin
theme discloses that name. That is fingerprinting, not access, and it is why
the attribute is opt-in.

The structure names the admin theme but does not expose its settings. To
serve those, add `claro.settings`, or whichever theme it is, to the exposure
list. That entry is a literal object name. It will not follow a later change
of admin theme, the way **Expose the active theme settings** follows a
change of default theme.

**Q: Why does `regions_hidden` name regions that are not in `regions`?**

**A:** Core's `system_info_alter()` adds `page_top` and `page_bottom` to
every theme's hidden list. It does that whether or not the theme declares
those regions.

Olivero declares 13 regions, and neither `page_top` nor `page_bottom` is one
of them. Both are on its hidden list. Nothing appears in both lists, so
filtering one by the other removes nothing:

```js
// On Olivero this removes nothing, and gives no sign of it.
const { regions, regions_hidden } = data.attributes.theme
const visible = Object.keys(regions).filter((r) => !regions_hidden.includes(r))
```

Filter if you need to, and check the result. A filter that removes nothing
looks the same as one that works.

**Q: Can a consumer have its own regions, or the nesting order?**

**A:** Neither, today. The structure describes the theme, so it is the same
for every consumer and is not merged with the overrides. Nesting and render
order are not included at all: they exist only in the theme's
`page.html.twig`, and reporting them would mean parsing templates. What is
reported is the flat map the theme declares, in its declared order.

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
