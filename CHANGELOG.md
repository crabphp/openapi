# Changelog

Crab OpenAPI follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- Check the plugin's settings before the document builder is made, including from console commands. CI covers framework `v0.6.1` and `main` on Linux and macOS; the package requires `^0.6`.
- Keep input nullability, defaults and rules at each use of a shared class or enum. Preserve empty schema objects, response alternatives and overlapping property unions, and resolve inherited property types in their declaring class. Enums with custom JSON serialization need an explicit schema.
- Describe query lists with bracketed names and query objects with `deepObject`. Strict generation now refuses nested query collections and untyped query arrays whose serialization is not portable in OpenAPI.
- Ignore unrelated action attributes and skip hidden routes before reading their response declarations.
- Preserve the previous document and remove temporary files after failed writes. Validate output paths before generation and use the framework's option-value parser.
- Reject conflicting path templates and license fields. Keep empty OAuth scopes and server variables as JSON objects, and do not invent error bodies for middleware statuses below `400`. Informational responses cannot declare content.

## 0.1.0 — 2026-11-05

- **First release,** for `crabphp/framework` 0.6: openAPI documents generated from Crab routes and action signatures.
