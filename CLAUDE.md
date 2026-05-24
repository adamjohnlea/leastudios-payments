# CLAUDE.md

## Releases

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`) that auto-generates release notes from the commit log between the previous and current tag.

**To cut a release:** bump the `Version:` header in the main plugin file, commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the header, builds the zip with `composer install --no-dev`, and publishes the release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes** (use these prefixes for changes you don't want surfaced): `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

The subject text after the prefix becomes the bullet verbatim, with the first letter capitalized. To override auto-notes for a specific release, edit the body in the GitHub UI after publish.
