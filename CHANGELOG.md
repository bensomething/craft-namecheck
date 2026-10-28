# Release Notes for Namecheck

## 1.0.0-beta.1 - 2026-10-28

### Added

- Initial beta release.
- `namecheck` Twig filter, linking mentions of entries in the **Link To** sections as HTML is rendered. Options for first mention only, a limit, extra link attributes, and entries to exclude.
- Namecheck field, giving an entry other names and a choice of linking it wherever it's mentioned, only where it's related, or never.
- Mentions index for the **Track Mentions In** sections, with `craft.namecheck.mentionedIn()` and `craft.namecheck.mentionedBy()`, kept up to date on save and by a queue job when a linked entry's names change.
- `namecheck/mentions/rebuild` console command.
- **Namecheck names and links** cache-clear option in Utilities and via `clear-caches`.
