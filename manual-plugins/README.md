# manual-plugins

Zips of paid plugins that have no Composer repository, installed by Composer from this folder. Empty by default.

## Convention

- File name: `<slug>-<version>.zip`, for example `example-premium-zip-1.0.0.zip`.
- The plugin folder is the single top-level directory inside the zip.
- Each zip is declared in `composer.json` as a `package` repository and required by name. The `version` and `dist.url` must match the file.
- Keep the site repository private: many licenses forbid redistributing the zip.

```json
"repositories": {
  "example-premium-zip": {
    "type": "package",
    "package": {
      "name": "your-org/example-premium-zip",
      "type": "wordpress-plugin",
      "version": "1.0.0",
      "dist": { "type": "zip", "url": "./manual-plugins/example-premium-zip-1.0.0.zip" },
      "require": { "composer/installers": "^2" }
    }
  }
}
```

then `composer require your-org/example-premium-zip:1.0.0`.

## Updating one

Add the new zip under its new name, update `version`, `dist.url` and the `require` line, delete the old zip, then `composer update your-org/example-premium-zip`.

Full explanation: [docs/01-project-layout.md](../docs/01-project-layout.md#paid-plugins-that-only-come-as-a-zip).
