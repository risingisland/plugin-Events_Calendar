# Events Calendar

A plugin for [GetSimple CMS](https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE) that lets you create and manage events in the admin area and show them on your site as a full calendar, a compact calendar, a list, a grid, or a sidebar mini-list.

## Features

- Five views: **calendar**, **compact**, **mini**, **list** and **grid**
- Multi-day events, all-day events, start and end times, 12-hour or 24-hour time
- **Repeating events**: weekly (on chosen weekdays, every N weeks) or monthly (on the Nth or last weekday, every N months), with an end date, an occurrence count or no end, and "skip these dates" for holidays and cancellations
- **Categories** with their own colours, usable as filters in any view
- Per-event colour, image, location, link and a rich-text description
- Built-in **image library** in the admin: browse `data/uploads/`, create folders, and upload JPG, PNG, GIF or WebP files (up to 10 MB each, drag and drop supported)
- Clicking any event opens its details in a pop-up
- Responsive: on narrow areas the calendar switches to a list, compact calendar or grid
- Without JavaScript, calendars fall back to a plain list of upcoming events
- Draft or published status per event
- No jQuery dependency; all assets are bundled locally, nothing is loaded from a CDN

## Requirements

- GetSimple CMS (written for GetSimpleCMS-CE)
- PHP 7.4 or newer
- Write access to `data/other/` (events are stored there) and to `data/uploads/` (if you want to upload images from the plugin)
- The PHP DOM extension is recommended. It is used to sanitise event descriptions; without it, a stricter fallback that strips all attributes is used.

## Installation

1. Download the repository and unzip it.
2. Copy `events_calendar.php` and the `events_calendar/` folder into your site's `plugins/` directory:

   ```
   plugins/
   ├── events_calendar.php
   └── events_calendar/
       ├── admin.php
       ├── media.php
       ├── recurrence.php
       ├── render.php
       ├── storage.php
       ├── assets/
       └── lang/
   ```

3. In the GetSimple admin, open **Plugins** and enable **Events Calendar**.
4. A new **Events** tab appears in the admin navigation.

Event data is created automatically on first save in `data/other/events/` (`events.json`, `categories.json`, `settings.json`). Back up that folder to back up your events.

## Quick start

1. Go to **Events → Add event**, fill in a title and a start date, and save.
2. Edit a page and put this in its content:

   ```
   [events view="calendar"]
   ```

3. View the page. Click an event to open its details.

## Showing events

You can show events with a shortcode in page content, or with template code in your theme. Both take the same options.

### Shortcode

```
[events view="list" limit="6"]
```

Put the shortcode on its own line. Use straight double or single quotes around values. You can use several shortcodes on one page, including the same view more than once with different categories.

### Template code

```php
<?php get_events(array('view' => 'grid', 'limit' => 6)); ?>
```

`get_events()` prints the result. Pass `false` as the second argument to get the HTML back instead:

```php
<?php $html = get_events(array('view' => 'mini'), false); ?>
```

## Views

| View       | Description                                                                                          |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| `calendar` | Full month calendar with bars across multi-day events. Hover shows the title, click opens the details. |
| `compact`  | Small month calendar with colour dots, made for sidebars and narrow columns.                         |
| `mini`     | Short list of upcoming events: colour dot, title and date. Made for sidebars.                        |
| `list`     | Event cards in a list, one per row. This is the default.                                             |
| `grid`     | Event cards in a responsive grid.                                                                    |

## Options

| Option       | Default       | Applies to              | Description                                                                                                  |
| ------------ | ------------- | ----------------------- | ------------------------------------------------------------------------------------------------------------ |
| `view`       | `list`        | all                     | `calendar`, `compact`, `mini`, `list` or `grid`.                                                             |
| `category`   | all           | all                     | Only events from these category slugs, comma separated, e.g. `category="music,workshops"`.                   |
| `limit`      | all (`mini`: 5) | list, grid, mini      | Maximum number of events.                                                                                    |
| `columns`    | `3`           | grid                    | Number of columns, 1 to 4.                                                                                   |
| `past`       | `0`           | list, grid, mini        | Set to `1` to include events that have already finished.                                                     |
| `from`, `to` | none          | list, grid, mini        | Only events inside this date range, written `YYYY-MM-DD`.                                                    |
| `order`      | `asc`         | list, grid, mini        | `asc` shows the soonest first, `desc` the latest first.                                                      |
| `excerpt`    | `110`         | list, grid              | Number of characters of the description shown on cards. `0` for none.                                        |
| `collapse`   | `0`           | list, grid, mini        | Set to `1` to show only the next occurrence of each repeating event.                                         |
| `filter`     | `0`           | calendar                | Set to `1` to show category buttons above the calendar.                                                      |
| `mobile`     | from Settings | calendar                | What the calendar becomes in a narrow area: `list`, `compact` or `grid`.                                     |
| `breakpoint` | from Settings | calendar                | Width in pixels below which the calendar switches. Measured on the area the calendar sits in, not the screen. |

### Examples

```
[events view="calendar" filter="1"]

[events view="calendar" mobile="compact" breakpoint="480"]

[events view="grid" columns="4" limit="8" excerpt="80"]

[events view="list" category="workshops" order="asc" limit="10"]

[events view="list" from="2026-01-01" to="2026-12-31" past="1" order="desc"]

[events view="mini" limit="3" collapse="1"]
```

## Admin guide

The **Events** tab has these pages in its sidebar:

- **All events**: the list of events with date, location, category and status. Past events are marked.
- **Add event**: the event form (see below).
- **Categories**: create, edit and delete categories. Each has a name and a colour, and a slug that you use in the `category` option. Deleting a category leaves its events uncategorised.
- **Settings**: global options (see below).
- **Help**: a built-in quick reference of views and options.

### Event fields

| Field        | Notes                                                                                                                  |
| ------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Title        | Required.                                                                                                              |
| Start date   | Required.                                                                                                              |
| End date     | Optional, for multi-day events. Cannot be before the start date.                                                       |
| All-day      | Hides times and shows "All day".                                                                                       |
| Start / end time | Optional. An end time needs a start time. On a single-day event the end cannot be before the start.               |
| Location     | Free text.                                                                                                             |
| Description  | Basic formatting only: titles, bold, italic, underline, lists, indent, alignment. Pasted text is inserted as plain text and anything else is removed on save. |
| Colour       | Per-event colour, or use the default colour from Settings.                                                             |
| Image        | Chosen from the image library, which reads from `data/uploads/`.                                                       |
| Link         | Must be a full `http://` or `https://` address. Shown as a "More information" link in the pop-up.                      |
| Category     | Optional.                                                                                                              |
| Status       | **Published** or **Draft**. Drafts are hidden on the site.                                                             |
| Repeat       | See below.                                                                                                             |

### Repeating events

An event is stored once, and its dates are worked out when needed.

- **Weekly**: every N weeks (1 to 12) on one or more weekdays.
- **Monthly**: every N months (1 to 12) on the 1st, 2nd, 3rd, 4th or last weekday of the month.
- **Ends**: never, on a date, or after a number of times.
- **Skip these dates**: one `YYYY-MM-DD` date per line, for holidays or cancelled dates.

Notes:

- Repeating events are shown for about 6 months back and 18 months ahead of today.
- Editing a repeating event changes the whole series. Use "Skip these dates" to cancel single dates.
- Each repeating event is capped at 500 occurrences within the window.

### Settings

| Setting                        | Default | Description                                                                                      |
| ------------------------------ | ------- | ------------------------------------------------------------------------------------------------ |
| Week starts on                 | Monday  | Monday or Sunday.                                                                                |
| Website language               | Site language | Language used on the front end. The admin screens always use your GetSimple language.      |
| Time format                    | 12-hour | 12-hour (`09:00 am`) or 24-hour (`09:00`).                                                       |
| Default event colour           | `#6c6ce5` | Used when an event has no colour of its own.                                                   |
| In a narrow area the calendar shows | A month list | `list`, `compact` or `grid`. Can be overridden per calendar with `mobile=""`.            |
| Switch below this width (px)   | 560     | Range 280 to 1400. Can be overridden per calendar with `breakpoint=""`.                          |

## Translations

The plugin ships with English (`en_US`) and Spanish (`es_ES`). To add a language:

1. Copy `events_calendar/lang/en_US.php` to a new file named after the locale, e.g. `fr_FR.php`.
2. Translate the values and keep the `_name` entry, which is the name shown in Settings.
3. The new language appears in **Settings → Website language**, and is used in the admin automatically when it matches your GetSimple language.

Missing keys fall back to English. The file also controls month and day names and the date format tokens (`{weekday}`, `{d}`, `{month}`, `{y}`).

## Theming

Front-end styles live in `events_calendar/assets/events.css`. All classes are prefixed `evc-` (for example `evc-card`, `evc-grid`, `evc-mini`) and event colours are passed through the `--evc-color` CSS variable, so you can restyle the output from your theme's stylesheet without editing the plugin.

## Security

- All admin actions are protected with GetSimple nonces.
- Event descriptions are sanitised on save: only a small set of formatting tags is kept, and links are limited to `http`, `https`, `mailto` and `tel`.
- Image paths are resolved inside `data/uploads/` only, and uploads are checked to be real JPG, PNG, GIF or WebP images.
- Event data is written with file locking.

## File structure

```
events_calendar.php            Plugin registration, language loading, admin nav, shortcode filter
events_calendar/
├── admin.php                  Admin pages: list, edit, categories, settings, help
├── media.php                  Image library (AJAX): browse, create folders, upload
├── recurrence.php             Repeating-event rules and expansion
├── render.php                 Front-end views, get_events(), shortcode handling
├── storage.php                Events, categories and settings storage; validation and sanitising
├── lang/                      Translations (en_US, es_ES)
└── assets/
    ├── admin.css / admin.js   Admin interface
    ├── events.css / events.js Front-end styles and behaviour
    └── vendor/                Bundled third-party libraries
```

## Credits

- Month calendar view powered by [Event Calendar](https://github.com/vkurko/calendar) (v5.17.0) by vkurko, bundled in `assets/vendor/`.
- Admin description editor: a bundled tiny editor (`tiny-editor.bundle.js`).

