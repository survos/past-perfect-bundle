# Discovering PastPerfect Online Sites

This bundle will benefit from a “site discovery” utility: given that PastPerfect Online sites share consistent URL patterns, we can build a lead list of institutions using **PastPerfect Online** and then harvest them.

## 1) Is there an official directory?

### PastPerfect “Client List” (partial)
PastPerfect publishes a voluntary, non-exhaustive client list (by state/region). It’s explicitly opt-in and incomplete. :contentReference[oaicite:0]{index=0}

### PastPerfect Online directory
That same client list page points to a **separate PastPerfect Online directory** at “PastPerfect-Online.com” for “only PastPerfect Online clients and their collections”. :contentReference[oaicite:1]{index=1}

**Reality check:** even if this directory is useful, it will not be exhaustive; treat it as one input signal, not the authoritative source.

---

## 2) The practical “directory”: hostname pattern

Most PastPerfect Online public collection sites follow a predictable host pattern:

- `https://{institution}.pastperfectonline.com/`

And they expose consistent entry points like:

- `/AdvancedSearch`
- `/webobject/{uuid}`
- `/photo/{uuid}`
- `/archive/{uuid}`
- `/library/{uuid}`

Because this is so consistent, “discover all PPO sites” becomes “discover all hostnames matching `*.pastperfectonline.com`”.

---

## 3) Free tools to discover sites (one-off script friendly)

### Option A — Internet Archive CDX (fast + free, great for a one-off)
Use the Internet Archive (Wayback) **CDX index** to query URLs with wildcards and extract unique hosts.

Why it works:
- CDX is effectively a huge historical URL index.
- It’s quick to query and good enough to build a candidate host list, which you then validate by probing `/AdvancedSearch`.

Implementation notes:
- Query for captures of `*.pastperfectonline.com/*`
- Extract hostnames
- Deduplicate
- Validate each candidate live with `GET https://{host}/AdvancedSearch` (or site root)

Useful reference: pywb’s CDX server API documentation describes CDX-style query semantics and filtering/pagination patterns. :contentReference[oaicite:2]{index=2}

There are also practical “how-to” guides discussing wildcard/broad queries (e.g. `matchType`) for CDX-style searching. :contentReference[oaicite:3]{index=3}

**Pros:** free, easy, no paid keys, quick results  
**Cons:** not all sites are archived; you’ll miss brand-new hosts that were never crawled

---

### Option B — Common Crawl Host Index (largest coverage, still free)
Common Crawl is a public web crawl dataset. In 2025, Common Crawl introduced a **Host Index** dataset (one row per host per crawl) that is designed for exactly this kind of use case. :contentReference[oaicite:4]{index=4}

Approach:
- Query the Host Index for hosts ending in `pastperfectonline.com`
- Export list of hosts
- Validate live via `/AdvancedSearch`

**Pros:** enormous coverage; more “web-wide” than IA  
**Cons:** heavier tooling (Athena / DuckDB / Spark) unless you already have an easy Common Crawl workflow

---

### Option C — Search-engine operators (good manually; APIs are messy)
You can manually search for:

- `site:pastperfectonline.com "Advanced Search"`
- `site:pastperfectonline.com "Online Collections"`

…but turning this into an API workflow is harder than it sounds.

Important note: Google’s Custom Search JSON API is **not available for new customers** and is scheduled for discontinuation (January 1, 2027), per Google’s own docs. :contentReference[oaicite:5]{index=5}  
So “use Google to fetch all sites via API” is not a great plan in 2026.

**Pros:** easy manual exploration  
**Cons:** reliable, free “give me everything for this domain” search APIs generally don’t exist anymore

---

## 4) Recommended discovery workflow for this bundle (pragmatic)

### Phase 1 — Build candidate host list (cheap, one-off)
1. Pull hosts from **Internet Archive CDX** (Option A)
2. Pull hosts from **Common Crawl Host Index** if you want better completeness (Option B)
3. Optionally add anything you find from the official directories (Client List + PastPerfect-Online directory) :contentReference[oaicite:6]{index=6}

### Phase 2 — Validate candidates (live probe)
For each hostname candidate:
- `GET https://{host}/AdvancedSearch`
- If HTTP 200 and page signature matches PPO → accept
- Else drop / log for review

### Phase 3 — Harvest
Once validated, pass the base URL to the harvester:
- listing pass → detail pass → normalized JSONL

---

## 5) Bundle feature suggestion: `pastperfect:discover`

Implement a console command that writes:

- `pastperfect-sites.jsonl` (candidate + validated sites)

Data shape suggestion:

```json
{
  "host": "fauquierhistory.pastperfectonline.com",
  "base_url": "https://fauquierhistory.pastperfectonline.com",
  "discovered_via": ["internet_archive_cdx", "common_crawl_host_index"],
  "validated": true,
  "validated_at": "2026-03-04T00:00:00Z"
}
```

---

## 6) Practical/legal considerations
- Respect `robots.txt` and rate-limit probes (discovery and harvesting).
- Discovery is not harvesting; validation should be lightweight (one request per host).
- Keep this as an optional, one-off utility (not something that runs in prod).

---

## Summary

Yes, there is an “official-ish” directory via PastPerfect’s client list and their referenced PPO directory. :contentReference[oaicite:7]{index=7}  
But the best discovery mechanism for scale is to build host lists from **Internet Archive CDX** and/or **Common Crawl Host Index**, then validate each host live before harvesting. :contentReference[oaicite:8]{index=8}
