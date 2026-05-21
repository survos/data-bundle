# Survos DataBundle

Database and tooling layer for [`survos/data-contracts`](../../../lib/data-contracts).

Bridges the contract layer (ContentType constants, item DTOs) to the file-based
import pipeline with two lightweight entities and a pipeline listener:

## What's here

### `VocabMap` — keyword → ContentType classification cache

One row per `(lang, normKeyword)`. Populated by the AI classifier (via
`ai-workflow-bundle`); consumed by `VocabResolver` during the enrich stage.
Null `contentType` = evaluated and not classifiable — stored so the model
is never asked twice for the same term.

### `VocabLabel` — ContentType → display label per language

One row per `(contentType, lang)`. Generated once per language via a single
Claude call covering all ~22 ContentType slugs. Used to render facet sidebar
labels in the source language ("Photographie" not "photograph").

### `VocabTermExtractorListener`

Fires on `ImportConvertFinishedEvent` (after normalize). Scans the output
JSONL, extracts unique `(lang, term)` pairs from genre/subject fields, and
writes `30_terms/vocab.jsonl` — the per-dataset term inventory used to diff
against the shared `vocab/{lang}/dto_map.jsonl`.

### `VocabResolver`

Service that wraps `ContentType::fromRecord()` with a VocabMap DB lookup for
foreign-language keywords. Used in the enrich stage to set `content_type` on
each record.

## Pipeline flow

```
import:convert --stage=normalize
  → 20_normalize/obj.jsonl
  → VocabTermExtractorListener → 30_terms/vocab.jsonl

vocab:map  (diff + AI call for misses)
  → vocab/{lang}/dto_map.jsonl  (shared, language-level)

import:convert --stage=enrich
  → VocabResolver loads dto_map into memory
  → 60_enrich/obj.jsonl  (with content_type set)
```

## File locations (via `DataPaths` in dataset-bundle)

| Path | Purpose |
|---|---|
| `$APP_DATA_DIR/vocab/{lang}/dto_map.jsonl` | Shared keyword→ContentType map |
| `$APP_DATA_DIR/vocab/{lang}/labels.jsonl` | Shared ContentType display labels |
| `$APP_DATA_DIR/translation/{lang}/` | Stub for future translation memory |
| `{dataset}/30_terms/vocab.jsonl` | Per-dataset extracted term inventory |

## Install

```bash
composer require survos/data-bundle
```

Register in `config/bundles.php`:

```php
Survos\DataBundle\SurvosDataBundle::class => ['all' => true],
```
