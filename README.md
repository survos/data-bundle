# museado/data-bundle

A small Symfony bundle that standardizes where Museado-related data lives on disk
and provides a single, typed service for resolving dataset, pipeline, and Pixie paths.

This bundle intentionally does one thing only: path conventions and filesystem helpers
around `APP_DATA_DIR`.

It is designed to be used by:

- Museado (the main site / pipeline runner)
- Aggregator microservices (Smithsonian, Europeana, etc.)
- Pixie-only reader sites

without forcing those apps to depend on each other.


## Core idea

All domain data lives under a single directory defined by:

```
APP_DATA_DIR
```

Nothing in this bundle depends on repository-relative paths, symlinks,
or `.gitignore` tricks.


## Recommended directory layout

Example values:

Local development:
```
APP_DATA_DIR=$HOME/data/mus
```

Dokku / containers:
```
APP_DATA_DIR=/data/mus
```

Layout under that directory:

```
$APP_DATA_DIR/
  data/
    <unitCode>/
      10_extract/
        obj.jsonl(.gz)
      20_normalize/
        obj.jsonl(.gz)
      21_profile/
        obj.profile.json
      30_terms/
        *.jsonl
  pixie/
    tenants/
      <tenant>.db
    template/
    exports/
  runs/
  cache/
```

Notes:

- `<unitCode>` is typically `aaa`, `nmah`, `nmnhbirds`, etc.
- Pixie databases are not stored under `data/`
- The layout is intentionally shallow and predictable


## Installation

```bash
composer require museado/data-bundle
```

Set the environment variable:

```bash
export APP_DATA_DIR=/absolute/path/to/data/root
```

That is the only required configuration.


## Usage

Inject the `DataPaths` service anywhere you need filesystem paths.

```php
use Museado\DataBundle\Service\DataPaths;

final class SomeService
{
    public function __construct(
        private DataPaths $paths
    ) {}
}
```


### Dataset paths

```php
$paths->datasetDir('aaa');
$paths->extractDir('aaa');
$paths->extractFile('aaa');

$paths->normalizeDir('aaa');
$paths->normalizeFile('aaa');

$paths->profileDir('aaa');
$paths->profileFile('aaa');

$paths->termsDir('aaa');
```


### Pixie paths

```php
$paths->pixieTenantDb('larco');
```


### Operational directories

```php
$paths->runsDir;
$paths->cacheDir;
```


## Directory creation helpers

The bundle includes small, safe helpers so commands do not need to
manually `mkdir` paths.

Ensure global roots exist:

```php
$paths->ensureRootDirs();
```

Ensure all standard dataset stage directories exist:

```php
$paths->ensureDatasetDirs('aaa');
```


## Atomic file writes

For small metadata files (profiles, registries, workflow state):

```php
$paths->atomicWrite($path, $contents);
```

This writes to a temporary file in the same directory and renames atomically.


## Design principles

- No business logic
- No import, normalize, profile, Pixie, or Meili code
- No dependency on other Museado bundles
- Filesystem layout is centralized and versioned
- Paths are semantic, not stringly-typed

This bundle exists so every app in the ecosystem agrees on where things go,
without duplicating logic or pulling in heavy dependencies.


## When to use this bundle

Use `museado/data-bundle` if your code needs to:

- Read or write `10_extract`, `20_normalize`, profiles, or termsets
- Locate Pixie SQLite databases
- Share data directories across multiple apps
- Avoid repo-local `data/` directories

Do **not** use it for:

- Import pipelines
- Data normalization
- Profiling
- Term extraction
- Search indexing
- UI or controllers


## Status

- Stable
- PHP ≥ 8.4
- Symfony ≥ 7.4 (tested with Symfony 8)

This bundle is intended to be boring, stable, and rarely changed.

## Developer

```bash
composer config repositories.museado-data-bundle '{"type":"path","url":"/home/tac/g/museado/data-bundle","options":{"symlink":true}}'
composer require museado/data-bundle:@dev

```
