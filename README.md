# SurvosPastPerfectBundle

Symfony bundle for harvesting [PastPerfect Online](https://www.pastperfectonline.com/) collections.

Streams listing pages and detail pages into JSONL files with built-in resume support
via [survos/jsonl-bundle](https://github.com/survos/jsonl-bundle) sidecars. Raw HTML
pages are cached to disk so re-runs are fast.

Site discovery (finding all `*.pastperfectonline.com` tenants) is delegated to
[survos/site-discovery-bundle](https://github.com/survos/site-discovery-bundle).

**Requirements:** PHP 8.4+, Symfony 8.0+.
Uses `Dom\HTMLDocument` (PHP 8.4 native HTML5 API) — no external DOM library.

---

## Installation

```bash
composer require survos/past-perfect-bundle survos/site-discovery-bundle
```

Register if not using Flex:

```php
// config/bundles.php
return [
    Survos\SiteDiscoveryBundle\SurvosSiteDiscoveryBundle::class => ['all' => true],
    Survos\PastPerfectBundle\SurvosPastPerfectBundle::class      => ['all' => true],
];
```

Optional — install `survos/import-bundle` for field profiling and CSV export:

```bash
composer require survos/import-bundle
```

---

## Configuration

```yaml
# config/packages/survos_past_perfect.yaml
survos_past_perfect:
    throttle:   1.0                          # seconds between uncached HTTP requests
    cache_dir:  var/pastperfect              # raw HTML cache location
    user_agent: "SurvosPastPerfectBundle Harvester"
```

---

## Typical workflow

```bash
# 1. Find all PPO tenant sites (via Internet Archive CDX)
bin/console pastperfect:discover-registry

# 2. Validate each site is live (dispatches Messenger messages)
bin/console pastperfect:probe-registry

# 3. Harvest the listing index for one site
bin/console pastperfect:harvest-listing https://fauquierhistory.pastperfectonline.com

# 4. Fetch and parse all detail pages (HTML cached locally)
bin/console pastperfect:harvest-details \
    var/pastperfect/fauquierhistory/fauquierhistory-listing.jsonl

# 5. Review the field landscape (requires survos/import-bundle)
bin/console import:profile:report \
    var/pastperfect/fauquierhistory/fauquierhistory-details.jsonl.profile.json
```

---

## Commands

### `pastperfect:discover-registry`

Queries the Internet Archive CDX API for all `*.pastperfectonline.com` tenant hostnames
and writes a registry listing JSONL. Delegates to `CdxDiscoveryService` from
`survos/site-discovery-bundle`.

```bash
bin/console pastperfect:discover-registry [options]
```

| Option     | Default                                    | Description |
|------------|--------------------------------------------|-------------|
| `--output` | `var/pastperfect/registry-listing.jsonl`   | Output path |
| `--force`  | false                                      | Re-discover even if file is already complete |
| `--limit`  | 0                                          | Stop after N sites (0 = unlimited). **Use during development.** |

```bash
# Test with 5 sites
bin/console pastperfect:discover-registry --limit=5

# Full discovery (slow — CDX pages take 10–30 s each)
bin/console pastperfect:discover-registry
```

Each record:
```json
{
  "slug":          "fauquierhistory",
  "host":          "fauquierhistory.pastperfectonline.com",
  "base_url":      "https://fauquierhistory.pastperfectonline.com",
  "discovered_via": "internet_archive_cdx",
  "validated":     false,
  "validated_at":  null
}
```

---

### `pastperfect:probe-registry`

Reads the registry listing and dispatches one `ProbeRegistrySiteMessage` per site.
Each message validates the host is a live PPO site and writes the result to a probed
registry JSONL.

Configure Symfony Messenger to handle the messages asynchronously, or leave unrouted
for synchronous handling:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Survos\PastPerfectBundle\Message\ProbeRegistrySiteMessage: async
            Survos\PastPerfectBundle\Message\ProbeItemMessage:         async
```

```bash
bin/console pastperfect:probe-registry [listingFile] [options]
```

| Argument      | Default                                    | Description |
|---------------|--------------------------------------------|-------------|
| `listingFile` | `var/pastperfect/registry-listing.jsonl`   | Input listing JSONL |

| Option     | Default                    | Description |
|------------|----------------------------|-------------|
| `--output` | `{dir}/registry-probed.jsonl` | Output path |
| `--force`  | false                      | Re-probe all sites |
| `--limit`  | 0                          | Stop after N sites |

---

### `pastperfect:harvest-listing`

Fetches the AdvancedSearch listing pages, parses all record links, and writes a
listing JSONL. Resumes automatically on re-run (sidecar tracks progress).

```bash
bin/console pastperfect:harvest-listing <baseUrl> [options]
```

| Argument  | Description |
|-----------|-------------|
| `baseUrl` | e.g. `https://fauquierhistory.pastperfectonline.com` |

| Option        | Default           | Description |
|---------------|-------------------|-------------|
| `--output-dir`| `var/pastperfect` | Output directory |
| `--force`     | false             | Re-harvest even if complete |

Output: `{output-dir}/{site}/{site}-listing.jsonl`

Each record:
```json
{
  "source": "pastperfectonline",
  "site":   "fauquierhistory",
  "type":   "webobject",
  "id":     "AC429E12-B023-4E3D-BEC0-693892645021",
  "url":    "https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-..."
}
```

---

### `pastperfect:harvest-details`

Reads the listing JSONL, fetches each detail page (cached under `{cache_dir}/{site}/detail/`),
parses catalog fields, and writes a flat details JSONL. A `.profile.json` is written
automatically after every run.

```bash
bin/console pastperfect:harvest-details <listingFile> [options]
```

| Argument      | Description |
|---------------|-------------|
| `listingFile` | Path to listing JSONL from `harvest-listing` |

| Option           | Default                        | Description |
|------------------|--------------------------------|-------------|
| `--output-dir`   | same dir as listing file       | Output directory |
| `--force`        | false                          | Re-fetch all, ignoring cache |
| `--profile-only` | false                          | Only (re-)profile the existing JSONL |
| `--limit`        | 0                              | Stop after N records |

Output files:
- `{site}-details.jsonl` — one flat record per item
- `{site}-details.jsonl.profile.json` — field profile

```bash
# Full harvest (cached HTML means re-runs are fast)
bin/console pastperfect:harvest-details \
    var/pastperfect/fauquierhistory/fauquierhistory-listing.jsonl

# Test with 10 records
bin/console pastperfect:harvest-details \
    var/pastperfect/fauquierhistory/fauquierhistory-listing.jsonl --limit=10

# Re-profile without any HTTP requests
bin/console pastperfect:harvest-details \
    var/pastperfect/fauquierhistory/fauquierhistory-listing.jsonl --profile-only
```

---

## About rights and licensing

PastPerfect Online has **no dedicated rights or license field** in its catalog schema.
The only rights signal is the footer copyright notice on each page, e.g.:

> © Fauquier Historical Society 2021

This is captured as `rights_notice` in every detail record. It is a **site-level**
attribution, not item-level.

Government-operated PPO sites may publish under a permissive or CC license, but PPO
itself provides no mechanism to declare this. Rights decisions must be made at the
application level, using `rights_notice` plus out-of-band knowledge about the
institution.

---

## Analysing harvested fields

```bash
# Review field landscape (requires survos/import-bundle)
bin/console import:profile:report \
    var/pastperfect/fauquierhistory/fauquierhistory-details.jsonl.profile.json

# Sort by distinct value count
bin/console import:profile:report ... --sort=distinct

# Show fields that look like delimited lists
bin/console import:profile:report ... --only=split

# Re-generate the profile at any time
bin/console pastperfect:harvest-details fauquierhistory-listing.jsonl --profile-only
```

---

## Messenger messages

Two messages are available for async processing:

| Message | Dispatched by | Handler |
|---------|---------------|---------|
| `ProbeRegistrySiteMessage` | `pastperfect:probe-registry` | `ProbeRegistrySiteHandler` |
| `ProbeItemMessage` | (dispatch manually) | `ProbeItemHandler` |

Route to any Symfony Messenger transport — Doctrine, AMQP, Redis, etc.
Leave unrouted for synchronous in-process handling (useful for small sites or testing).

---

## License

MIT
