# Translating Logbook

Every word Logbook shows comes from a message catalogue in `translations/`.
English (`messages+intl-icu.en.php`) is complete and is the fallback for
anything a catalogue does not have. German (`messages+intl-icu.de.php`) ships
complete as the second language.

## Adding a language

1. Copy the English catalogue, naming the copy after the language's code:

   ```bash
   cp translations/messages+intl-icu.en.php translations/messages+intl-icu.fr.php
   ```

   Use a two- or three-letter language code (`fr`, `nl`, `pt`), or a
   language with a region (`pt_BR`) when the wording differs by country.

2. Translate the **values**, never the keys. Keep every `{placeholder}`
   exactly as it is; you may move it within the sentence.

3. That's it. The language appears in **Profile → Language and region**
   (with each country variant ICU knows, e.g. *français (Belgique)*, which
   also sets date, number and currency formats), and visitors whose browser
   asks for it get it on the sign-in page.

You can translate part of the file and delete the rest: missing keys fall back
to English. But the test suite expects shipped catalogues to be complete, so
remove keys rather than leaving English text in place, or add your catalogue
to the exceptions in `tests/Unit/Support/I18n/CatalogueTest.php`.

## Message format

Messages use ICU MessageFormat, so plurals and numbers are handled per
language:

```php
'vehicle_count' => '{count, plural, =0 {No vehicles yet} one {# vehicle} other {# vehicles}}',
```

- `#` is the number, formatted for the reader's locale.
- Languages with more plural forms use the ICU categories they need
  (`zero`, `one`, `two`, `few`, `many`, `other`); `other` is always required.
- `{share, number, percent}` and similar formats stay as they are.
- Quotation marks and dashes should be the ones your language uses
  (German uses „…“).

Units (`units.symbol.*`, `units.distance.*`, …) are translatable too: German
writes litres as `l`. The CSV export's column headers (`export.column.*`) are
used for CSV import as well, in the owner's language and in English.

## Checking your work

```bash
composer test -- --filter CatalogueTest     # keys, placeholders and ICU syntax
composer test -- --filter AccessibilityTest # every page rendered in each language
```

`CatalogueTest` fails if a catalogue has a key English does not, if a message's
placeholders differ from the English one, or if a message is not valid ICU. It
also scans the templates for text that bypasses the catalogue.

Then switch your account to the new language and click through: long words
(German compounds, for example) are the usual layout surprise.

## For developers

- Templates use `{{ 'section.key'|trans }}` or `trans('section.key', {name: …})`;
  PHP uses translation keys (flash messages, validation errors) and the
  translator. There is no literal UI text in templates or code.
- Add new strings to the English catalogue **and** every shipped catalogue in
  the same change.
- Keys are nested arrays in the PHP files and flattened with dots
  (`'fuel' => ['title' => …]` is `fuel.title`).
- In production, catalogues are compiled into `var/cache/translations`; clear
  that directory after editing a catalogue on a live install.
