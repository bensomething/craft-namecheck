# Namecheck for Craft CMS

Link the names of your entries wherever your content mentions them, and keep track of who mentioned whom.

Write "Pulp headline the Saturday" in a news story and Namecheck links "Pulp" to your Pulp page as it's rendered. Your content is never rewritten: links are added on the way out, so old articles pick up links to pages you add later, and nothing needs tidying if a page goes away.

> [!NOTE]
> **Namecheck is in beta.** It's feature-complete and safe to try, but its API and settings may still change before 1.0.0. Please [report anything you hit](https://github.com/bensomething/craft-namecheck/issues).

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later

## Installation

```bash
composer require bensomething/craft-namecheck:^1.0.0-beta
php craft plugin/install namecheck
```

## Setup

In **Settings → Plugins → Namecheck**:

- **Link To**: the sections whose entries get linked, such as Performers.
- **Track Mentions In**: the sections whose entries are checked for mentions when they're saved, such as News. Optional: only needed for `craft.namecheck.mentionedIn()`.
- **Link First Mention Only**: link each entry once per piece of content (on by default).
- **Match Case**: match names only as they're capitalised (on by default, and recommended).

## Linking Mentions

Run any HTML through the `namecheck` filter, passing the entry it belongs to:

```twig
{{ entry.body|namecheck(entry) }}
```

The text is searched for entry titles as whole words, so "Air" is found in "Air played" but not in "Airline". Longer names win, so "Pulp Fiction" is linked rather than "Pulp" inside it. An entry is never linked to itself, and existing links, headings, captions, code and form controls are left alone.

The filter takes options as a third argument:

```twig
{{ entry.body|namecheck(entry, {
    firstOnly: false,
    limit: 5,
    attributes: { class: 'mention' },
    exclude: [entry.festival.one()],
}) }}
```

| Option | |
|---|---|
| `firstOnly` | Overrides the **Link First Mention Only** setting. |
| `limit` | The most entries to link to. |
| `attributes` | Extra attributes for each link. |
| `exclude` | Entries (or IDs) not to link. |

Content rendered in pieces counts as one piece when each call is passed the same entry. That covers a CKEditor field rendered chunk by chunk, or a body and a sidebar: an entry linked in one call isn't linked again by the next, and `limit` counts across them all.

```twig
{% for chunk in entry.body %}
    {{ chunk.type == 'markup' ? chunk|namecheck(entry) : chunk }}
{% endfor %}
```

Links can also be given attributes everywhere through the `linkAttributes` setting in `config/namecheck.php`.

Linked HTML is cached until the content or a linked entry changes, or for Craft's `cacheDuration` at most. So a pending entry is linked once it goes live, though possibly not until that cache next expires.

## Names That Are Also Words

Some names are everyday words: Air, Wednesday, Boston, Sparks. For these, add a **Namecheck** field to the entry types you link to. It gives each entry two settings:

- **Link This Entry**:
  - **Wherever it's mentioned** (the default).
  - **Only where it's related**: linked only in content that relates the entry, for example through an entries field on the article. Pass the article to the filter for this to work.
  - **Never**.
- **Other Names**: anything else the entry is known as, one per line, such as "Beatles" for "The Beatles". These are matched as well as the title.

Entries without the field are linked wherever they're mentioned. Curly and straight apostrophes match each other, as do ordinary and non-breaking spaces. A name shared by two entries is ambiguous, so it isn't linked to either.

## Mentions

With **Track Mentions In** set, each entry in those sections is checked for mentions when it's saved, by its title and the text of its fields. Relation fields don't count: picking an entry isn't mentioning it. List mentions from either side:

```twig
{# On a performer's page: news that mentions them #}
{% set news = craft.namecheck.mentionedIn(entry).section('news').orderBy('postDate desc').limit(6).all() %}

{# On an article: the entries it mentions #}
{% set mentioned = craft.namecheck.mentionedBy(entry).all() %}
```

Both return an entry query, so narrow and order them like any other.

When a linked entry is added or renamed, or its other names change, mentions are updated in the queue. Changing the plugin's settings in the control panel does the same. After changing them through project config, rebuild them yourself:

```bash
php craft namecheck/mentions/rebuild
```

Nested entries' fields (in Matrix or CKEditor) count when the nested field is searchable.

## Caches

Names and links are cached until a linked entry is saved or deleted. Clear them by hand under **Utilities → Caches → Namecheck names and links**, or with `php craft clear-caches/namecheck`.

## Configuration

Every setting can be set in `config/namecheck.php`:

```php
<?php

return [
    'targetSections' => ['performers'],
    'sourceSections' => ['news'],
    'firstOnly' => true,
    'caseSensitive' => true,
    'linkAttributes' => ['class' => 'mention'],
    // Elements whose text is never linked.
    'skipTags' => ['a', 'button', 'code', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'kbd', 'label', 'option', 'pre', 'script', 'select', 'style', 'textarea'],
];
```
