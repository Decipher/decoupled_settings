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

The exposure list is stored in `decoupled_settings.settings`. On install it
exposes `system.site` and the active theme's settings, and nothing else.

Manage the exposure list at **Configuration > Services > Decoupled Settings**
(`/admin/config/services/decoupled-settings`). The page shows what a frontend
would read right now, so a key missing for lack of schema is visible rather
than silent.

Reading the exposed settings requires the **Read decoupled settings**
permission. It is not granted to anonymous users. Grant it deliberately if a
frontend reads settings without authenticating.

Per-consumer overrides are edited on the consumer itself, at
**Configuration > Services > Consumers > Settings**
(`/admin/config/services/consumer/{consumer}/decoupled-settings`). Tick a
setting to override it. Anything left unticked is inherited, and follows the
site value when it changes.

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

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
