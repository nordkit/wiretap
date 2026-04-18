# Releasing

This document describes how to cut a new release of `nordkit/wiretap`.

> **Never create or push a tag manually.** Tags are created by the [Release workflow](.github/workflows/release.yml) only after all CI checks pass across every supported PHP and Laravel version. A manually pushed tag bypasses those checks and immediately becomes installable via Composer — broken code and all.

---

## Prerequisites

- Write access to the `main` branch on GitHub
- All changes merged into `main`
- `CHANGELOG.md` updated (see below)

---

## 1. Update the Changelog

Edit `CHANGELOG.md` before triggering the release:

1. Move everything under `## [Unreleased]` into a new versioned section:

```markdown
## [Unreleased]

## [1.3.0] - 2026-05-01

### Added
- ...

### Fixed
- ...
```

2. Add the comparison link at the bottom of the file:

```markdown
[Unreleased]: https://github.com/nordkit/wiretap/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/nordkit/wiretap/compare/v1.2.0...v1.3.0
```

3. Commit directly to `main` (or via a PR):

```bash
git add CHANGELOG.md
git commit -m "chore: prepare release v1.3.0"
git push origin main
```

---

## 2. Trigger the Release Workflow

1. Go to **GitHub → Actions → Release**
2. Click **Run workflow**
3. Enter the version number — digits only, no `v` prefix (e.g. `1.3.0`)
4. Click **Run workflow**

The workflow will:

| Step | What happens |
|---|---|
| CI matrix | Runs Pint style check + Pest tests across PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12 |
| Tag | Creates and pushes `v1.3.0` only if every CI job passes |
| GitHub Release | Auto-generates release notes from commit messages |

If any CI job fails the tag is **never created** and the workflow stops.

---

## 3. Verify

- The new tag appears under **GitHub → Code → Tags**
- The GitHub Release is visible under **Releases**
- Packagist picks up the new version within a few minutes (check [packagist.org/packages/nordkit/wiretap](https://packagist.org/packages/nordkit/wiretap))

---

## Versioning

This project follows [Semantic Versioning](https://semver.org):

| Change | Version bump |
|---|---|
| Bug fixes, internal refactors | `PATCH` — `1.2.0 → 1.2.1` |
| New backwards-compatible features | `MINOR` — `1.2.0 → 1.3.0` |
| Breaking changes | `MAJOR` — `1.2.0 → 2.0.0` |

