# Survos DataBundle

> ⚠️ **NOT** `survos/dataset-bundle` (note the extra `set`). Different package, different namespace.
>
> | Package | Namespace | Bundle class | Purpose |
> |---|---|---|---|
> | `survos/data-bundle` (this) | `Survos\DataBundle` | `SurvosDataBundle` | Normalize-time term extraction + a parked vocab/authority classifier |
> | `survos/dataset-bundle` | `Survos\DatasetBundle` | `SurvosDatasetBundle` | Dataset filesystem conventions (`APP_DATA_DIR`) |
>
> Both bundles can be installed side-by-side. Don't merge their namespaces in `composer.json` autoload — it silently masks classes from the other.

## ⚠️ Status (2026-06): mostly dormant, slated to move

This bundle is **not** the home of shared semantic contracts — those are item DTOs,
vocabularies, and `ContentType`, which live in
[`survos/data-contracts`](../../../lib/data-contracts) (being renamed **`cho-contracts`**,
`Survos\Cho`). The contracts are what consumers across the monorepo actually need.

What's left in *this* bundle is a small set of **normalize-time** helpers plus a
**parked authority/classifier feature** that was started and never finished. After
re-reading the code (2026-06), the honest accounting is below. The leading plan is to
**stop requiring this bundle in non-normalizing apps and move the surviving pieces into
the normalizer (md)** — see [Direction](#direction).

### What actually runs today

| Component | Trigger | Notes |
|---|---|---|
| `VocabTermExtractorListener` | auto-registered on `ImportConvertFinishedEvent` | Scans the normalize JSONL and writes a per-dataset term inventory to `{dataset}/30_terms/{termType}.{lang}.jsonl` (e.g. `genre.en.jsonl`, `medium.fr.jsonl`). Term types come from `ItemField` / `MuseumVocab` constants. These files feed `folio:ingest` as `TermSet` + `Term` rows. **This is the one genuinely useful, wired path.** |
| `NormalizeFallbackListener` | auto-registered on `ImportConvertRowEvent` (priority -10) | Sets `iiif_base` to the best source image URL when unset. **This is not vocab-related** — it's a generic normalize fallback that happens to live here. Candidate to move to import/media/md. |
| `vocab:export` / `vocab:import` | CLI | JSONL round-trip of the two tables below. The only way rows ever get into those tables. |

### Dormant / not wired (the parked classifier)

| Component | State |
|---|---|
| `VocabMap` (`vocab_map` table) — `(lang, normKeyword) → contentType` cache | **No consuming app migrates or populates this table.** The `confidence`/`model` columns anticipate an AI classifier that **does not exist**. |
| `VocabLabel` (`vocab_label` table) — `(contentType, lang) → label` | Same: no migration, no producer. |
| `VocabResolver` — wraps `ContentType::fromRecord()` with a `VocabMap` lookup | **Injected by nobody.** A complete consumer with no caller. |
| `TermSetExtractor` — derives `termSet.jsonl`/`term.jsonl` from `obj.profile.json` | **Injected by nobody.** Overlaps conceptually with the listener above. |

### Things the old README claimed that aren't true

- There is **no `vocab:map` command** and **no `dto_map.jsonl` / `labels.jsonl`** under
  `$APP_DATA_DIR/vocab/`. That whole "diff + AI call for misses → shared language-level
  map" pipeline was aspirational and never built.
- `VocabMap` is **not** "populated by the AI classifier via `ai-workflow-bundle`." The AI
  bundles consume `ContentType` from contracts directly; they never touch these tables.
- The extractor writes `30_terms/{termType}.{lang}.jsonl`, **not** `30_terms/vocab.jsonl`.

## How it got here

This bundle was born when **harvest (formerly `mus`)** and **md** were *both* in the
normalizing business, and it needed a shared place for term extraction and a
keyword→type classifier. That dual-normalizer situation **still holds** (2026-06):
md has 18 provider normalizers (`Singleton/*`), **harvest still has 15** (`Dataset/*`),
and ssai's `MaracRegistrantsListener` fires the same `ImportConvert*` events. So this
is a **normalize-layer** bundle shared by md + harvest + ssai — not yet an md-only
concern. The classifier ambition — using extensive Europeana / musdig vocabularies as
portable controlled authorities — was valuable but got parked before the AI loop was wired.

Authority / controlled-vocabulary lists are still worth having. Parking them does not
mean abandoning them.

## Direction

Under active discussion (not yet executed):

1. **Contracts → `cho-contracts`.** The portable, language-neutral DTO/vocabulary/`ContentType`
   layer (today `survos/data-contracts`) is what most consumers actually need. ssai, for
   example, needs the *contracts*, not this bundle.
2. **Drop it from the apps that don't normalize.** Required by md, zm, ssai, harvest,
   mediary, mus, rsun — but the live listeners only matter where `ImportConvert*` events
   fire. **md, harvest, ssai** normalize and should keep it; **zm, mus, mediary, rsun**
   have zero normalizers and inject nothing, so it's pure dead weight there (and drags in
   ORM + `import-bundle` + `dataset-bundle` + `field-bundle` + `kit-bundle`).
3. **Fold the survivors into md only after normalization consolidates there.** Today the
   term-extraction listener is shared by three normalizers, so it can't collapse into md
   until harvest's `Dataset/*` and ssai's Marac import either retire or delegate to md.
   `NormalizeFallbackListener` (iiif_base) isn't vocab and should move out independently —
   to import/media — regardless of the rest.
4. **If the authority/vocab classifier is revived,** prefer a **shared SQLite registry**
   (the same pattern now used for the dataset/provider registry) over per-application
   sync — one shared `vocab_map`/`vocab_label` source instead of migrating and importing
   into every app's database.

## Install (current)

```bash
composer require survos/data-bundle
```

```php
// config/bundles.php
Survos\DataBundle\SurvosDataBundle::class => ['all' => true],
```

File locations are resolved via `DataPaths` (now in `survos/dataset-bundle`):

| Path | Purpose |
|---|---|
| `{dataset}/30_terms/{termType}.{lang}.jsonl` | Per-dataset extracted term inventory (live) |

## Note for future schema/type work

Symfony **8.1** `TypeInfo` now supports **object shapes**. This may be useful when we need
to describe folio/archive custom payloads with a compact, typed PHPDoc contract before (or
instead of) introducing a full formal schema layer.

Reference syntax:

```php
use Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver;

$resolver = new StringTypeResolver();
$type = $resolver->resolve('object{name: string, age: int, email?: string}');
```

Equivalent programmatic form:

```php
use Symfony\Component\TypeInfo\Type;

$type = Type::objectShape([
    'name' => Type::string(),
    'age' => Type::int(),
    'email' => ['type' => Type::string(), 'optional' => true],
]);
```
