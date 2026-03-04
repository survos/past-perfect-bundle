# PastPerfect Integration Plan (Agent Implementation Guide)

This document describes the implementation plan for integrating **PastPerfect Online (PPO)** with our ecosystem (ScanStationAI, Museado, Keoma, and related projects).

The agent implementing this should build a reusable **Symfony bundle** that provides:

1. Harvesting of PastPerfect Online collections
2. Normalization of records into JSONL
3. Export of ScanStationAI metadata into PastPerfect import format

The bundle should be reusable across multiple Symfony applications.

---

# 1. Target Bundle

Repository:

```
survos/pastperfect-bundle
```

Namespace:

```
Survos\PastPerfectBundle
```

Primary purpose:

```
Harvest PastPerfect Online collections
Normalize records
Export ScanStation-compatible data for PastPerfect import
```

This bundle must work in:

- Museado
- ScanStationAI
- Keoma (future system)
- internal indexing pipelines

---

# 2. Core Architecture

The bundle should contain three subsystems:

```
Harvesting
Normalization
Export
```

Each subsystem must be independently usable.

---

# 3. Harvesting System

Harvest records from any:

```
*.pastperfectonline.com
```

Example site:

```
https://fauquierhistory.pastperfectonline.com/
```

Harvesting occurs in two passes:

```
Listing Pass
Detail Pass
```

---

# 4. Listing Pass

Purpose:

```
Discover all record IDs exposed by the site
```

Starting URL example:

```
https://fauquierhistory.pastperfectonline.com/AdvancedSearch?advanceSearchActivated=False&firstTimeSearch=False&search_include_objects=true&search_include_photos=true&search_include_archives=true&search_include_library=true&search_include_creators=true&search_include_people=true&search_include_containers=true&searchcat_1=&searchcat_2=&searchcat_3=&searchcat_4=&searchcat_5=&searchcat_6=&searchcat_7=&searchcat_8=&searchcat_9=&searchcat_10=&searchcat_11=&searchcat_12=&searchcat_13=&searchcat_14=&actionType=Search
```

Agent tasks:

1. Fetch the search results page
2. Parse record links
3. Extract record identifiers
4. Follow pagination until exhaustion

Typical record link:

```
/webobject/AC429E12-B023-4E3D-BEC0-693892645021
```

Extract:

```
type = webobject
id = AC429E12-B023-4E3D-BEC0-693892645021
```

Build absolute URL:

```
https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-B023-4E3D-BEC0-693892645021
```

---

# 5. Listing Output

Write JSONL file:

```
{site}-pastperfect-listing.jsonl
```

Example:

```
fauquierhistory-pastperfect-listing.jsonl
```

Record structure:

```json
{
  "source": "pastperfectonline",
  "site": "fauquierhistory",
  "type": "webobject",
  "id": "AC429E12-B023-4E3D-BEC0-693892645021",
  "url": "https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-B023-4E3D-BEC0-693892645021"
}
```

---

# 6. Detail Pass

Purpose:

```
Fetch the detail page for each discovered record
```

Example detail page:

```
https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-B023-4E3D-BEC0-693892645021
```

Agent tasks:

1. Fetch page HTML
2. Parse fields
3. Extract metadata
4. Extract media URLs
5. Normalize structure

---

# 7. Normalized Record Schema

Output file:

```
{site}-pastperfect-normalized.jsonl
```

Example:

```
fauquierhistory-pastperfect-normalized.jsonl
```

Normalized schema:

```json
{
  "source": "pastperfectonline",
  "site": "fauquierhistory",
  "provider": {
    "name": "PastPerfect Online",
    "base_url": "https://fauquierhistory.pastperfectonline.com/"
  },
  "item": {
    "id": "AC429E12-B023-4E3D-BEC0-693892645021",
    "type": "webobject",
    "url": "https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-B023-4E3D-BEC0-693892645021",
    "title": "...",
    "description": "...",
    "identifiers": [],
    "dates": [],
    "people": [],
    "subjects": [],
    "places": [],
    "media": []
  }
}
```

---

# 8. Media Extraction

Agent must detect:

```
image thumbnails
full-resolution images
```

Add to:

```
item.media[]
```

Example:

```json
{
  "type": "image",
  "role": "primary",
  "thumb_url": "...",
  "full_url": "..."
}
```

---

# 9. Harvesting Rules

Implement:

```
rate limiting
HTML caching
resume capability
```

Requirements:

```
1 request/sec default throttle
store raw HTML
skip previously harvested records
```

Cache location:

```
var/pastperfect/
```

---

# 10. Export System (ScanStation → PastPerfect)

Goal:

```
Generate files PastPerfect can import
```

PastPerfect imports typically use:

```
CSV
TXT
DBF
```

We will implement **CSV export**.

---

# 11. Export Files

Agent must generate:

```
pastperfect-objects.csv
pastperfect-photos.csv
pastperfect-archives.csv
```

Each row = one record.

Fields configurable via mapping.

Example CSV columns:

```
OBJECTID
OBJECTNAME
TITLE
DESCRIPTION
DATE
CREATOR
NOTES
RIGHTS
```

---

# 12. Media Export

Images from ScanStation should be exported with predictable names:

```
OBJECTID_001.tif
OBJECTID_002.tif
```

Create manifest:

```
media-manifest.jsonl
```

Example:

```json
{
  "objectid": "2026.12.34",
  "filename": "2026.12.34_001.tif",
  "role": "primary"
}
```

---

# 13. Symfony Console Commands

Agent must implement commands.

All commands must follow Symfony 7.3 attribute style.

### Harvest

```
pastperfect:harvest
```

Example:

```
bin/console pastperfect:harvest https://fauquierhistory.pastperfectonline.com
```

Outputs:

```
listing.jsonl
details/
normalized.jsonl
```

---

### Fetch Details

```
pastperfect:fetch
```

Consumes listing file.

---

### Normalize

```
pastperfect:normalize
```

Consumes raw HTML.

---

### Export

```
pastperfect:export
```

Input:

```
ScanStation dataset
```

Output:

```
PastPerfect import files
```

---

# 14. Configuration

Example bundle config:

```yaml
pastperfect:
  throttle: 1.0
  cache_dir: var/pastperfect
  user_agent: "ScanStationAI Harvester"
```

---

# 15. HTTP Client

Use Symfony HttpClient:

```
Symfony\Contracts\HttpClient\HttpClientInterface
```

All requests must:

```
respect throttle
retry transient failures
```

---

# 16. Output Directory Layout

Example harvest result:

```
data/pastperfect/fauquierhistory/

listing.jsonl
normalized.jsonl

details/
AC429E12-B023-4E3D-BEC0-693892645021.html
...
```

---

# 17. Error Handling

Agent must handle:

```
missing records
HTML layout variation
broken media links
```

Failures must not stop harvest.

---

# 18. Future Enhancements

Possible later features:

```
incremental re-harvesting
cross-site harvesting
automatic Meilisearch indexing
```

---

# 19. Implementation Priority

Agent should implement in this order:

1. Bundle skeleton
2. Listing harvester
3. Detail harvester
4. Normalizer
5. JSONL writer
6. Console commands
7. Export generator

---

# 20. Expected Outcome

Once implemented we should be able to run:

```
bin/console pastperfect:harvest https://fauquierhistory.pastperfectonline.com
```

Result:

```
Complete normalized dataset ready for Meilisearch indexing
```

This dataset can then be used across:

```
ScanStationAI
Museado
Keoma
other ingest pipelines
```

---
