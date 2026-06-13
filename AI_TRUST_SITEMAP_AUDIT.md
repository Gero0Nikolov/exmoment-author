# AI Trust Sitemap Audit — author.exmoment.com

## 1. Executive Summary

- Overall AI Trust Score: **8/100** *(provisional, low confidence due access failure)*
- Estimated AI Recommendation Probability: **3%** *(provisional estimate based on unavailable crawl visibility)*
- Short verdict: Public-site audit could not be executed because the target host was not reachable from available browser/network paths.
- Biggest strength: A sitemap index endpoint is known by URL convention (`/sitemap_index.xml`).
- Biggest weakness: No crawl/index/content verification was possible because the host could not be accessed.
- Highest-priority fix: Restore public accessibility for `author.exmoment.com` and verify sitemap/index/page availability from external networks.

## 2. Sitemap Coverage

- Sitemap index URL: `https://author.exmoment.com/sitemap_index.xml`
- List of discovered sub-sitemaps:
  - None discovered (source unreachable)
- Total URLs found: **0**
- Total URLs audited: **0**
- Any skipped URLs and why:
  - `https://author.exmoment.com/sitemap_index.xml` skipped for content extraction due browser error: `net::ERR_BLOCKED_BY_CLIENT`
  - Shell fallback also failed with DNS error: `Could not resolve host: author.exmoment.com`
  - Web fetch/search fallback returned no retrievable content/results

## 3. URL Inventory

| URL | Type | Title | H1 | Indexability Notes | Core Relevance | Issues |
|---|---|---|---|---|---|---|
| https://author.exmoment.com/sitemap_index.xml | Utility | N/A | N/A | Unverifiable (host unreachable) | High | Could not crawl XML; no sub-sitemaps or URLs extractable |

## 4. Scoring Breakdown

| Category | Score | Max | Notes |
|---|---:|---:|---|
| Technical Discoverability | 1 | 15 | Sitemap URL known, but crawlability and status not verifiable from environment |
| Topical Authority | 1 | 20 | No public content retrievable for evaluation |
| AI Trust Signals | 1 | 20 | No page-level trust/entity signals could be inspected |
| Query Match | 1 | 20 | No query-target pages could be mapped |
| Conversion and Product Clarity | 1 | 10 | Product/offer clarity could not be observed |
| Structured Data and Machine Readability | 1 | 10 | Schema presence could not be tested |
| Internal Linking and Page Relationships | 2 | 5 | No internal graph available due crawl failure |
| Total | 8 | 100 | Provisional score only; replace after successful crawl |

## 5. AI Recommendation Probability by Query

**Important:** These percentages are reasoned estimates from observable access failure only, not real AI engine data.

| Query | Estimated Recommendation Probability | Reason |
|---|---:|---|
| content search queries | 2% | No discoverable/retrievable pages to support recommendation confidence |
| AI content optimisation software | 3% | No accessible evidence of product-positioning pages |
| AI content optimization software | 3% | Same as above; no crawlable commercial/explanatory assets observed |
| AI content plugins | 4% | Domain target suggests plugin context, but no pages available to verify |
| WordPress AI content plugin | 4% | Could not validate WordPress/plugin proof, schema, or product detail |
| AI SEO content workflow software | 2% | No accessible workflow or SEO content clusters |
| SEO content packs | 2% | No content-pack/category pages observed |
| content optimization plugin | 3% | No retrievable evidence for query-page match |

## 6. Strongest Pages for AI Trust

No pages could be evaluated because the public host content was inaccessible during the audit window.

## 7. Weakest Pages / Gaps

- URL or missing asset: `https://author.exmoment.com/sitemap_index.xml` (and all downstream URLs)
- Problem: Public retrieval failure prevented recursive sitemap/page audit.
- Business impact: Reduced discoverability and inability for search/AI systems to parse and trust site content.
- SEO/AI-search impact: AI systems cannot confidently recommend entities they cannot crawl or validate.
- Recommended fix: Verify DNS, CDN/firewall/WAF rules, bot filtering, and SSL/TLS/public routing for anonymous traffic.

## 8. Entity and Product Clarity Review

- Is ExMoment Author clearly described? **Not verifiable** (no accessible pages).
- Is it clearly a WordPress plugin, software product, content store, workflow system, or a combination? **Not verifiable**.
- Is the target user clear? **Not verifiable**.
- Is the business category clear enough for AI systems? **Not verifiable**.
- Is the language consistent across pages? **Not verifiable**.

## 9. Structured Data Review

Detected schema:
- None detectable (no page HTML retrievable).

Missing/unknown schema status:
- Organization: **Critical** to verify/add once access is restored.
- WebSite: **Useful** for search/entity context.
- BreadcrumbList: **Useful** for hierarchy understanding.
- Product: **Critical** for commercial/plugin pages.
- SoftwareApplication: **Critical** for AI/software/plugin query intent.
- Article: **Useful** for topical authority content.
- FAQPage: **Useful** for AEO-style query match.
- Review/AggregateRating: **Optional** unless authentic review data exists.

## 10. Internal Linking Review

- Do blog/content pages link to product pages? **Not verifiable**.
- Do product pages explain the broader product ecosystem? **Not verifiable**.
- Do category pages reinforce topical authority? **Not verifiable**.
- Is anchor text descriptive? **Not verifiable**.
- Are important commercial pages reachable from informational pages? **Not verifiable**.

## 11. Priority Fixes

| Priority | Fix | Impact | Effort | Notes |
|---|---|---|---|---|
| P0 | Restore public accessibility for `author.exmoment.com` and `sitemap_index.xml` from external networks | High | Medium | Required before any SEO/AI trust improvements can be validated |
| P0 | Confirm DNS resolution, TLS validity, and edge/WAF rules for non-authenticated crawlers | High | Medium | Investigate `ERR_BLOCKED_BY_CLIENT`/host resolution failures |
| P1 | Re-run full recursive sitemap crawl and baseline technical/indexability audit | High | Low | Needed to replace provisional score with evidence-based scoring |
| P1 | Validate canonical/index directives and URL cleanliness across all sitemap URLs | High | Medium | Core discoverability prerequisite |
| P2 | Add/verify SoftwareApplication/Product/Organization schema on core commercial pages | High | Medium | Improves machine-readable entity/product trust once pages are reachable |

## 12. Final Verdict

- Current AI Trust Score: **8/100** *(provisional due inaccessible host)*
- Current AI Recommendation Probability: **3%** *(provisional estimate)*
- Realistic target after fixes: **55–75/100 trust score** and materially higher recommendation likelihood, contingent on successful crawlability plus content/entity improvements
- Whether the website is currently strong enough to be recommended by AI tools: **No, based on current inaccessible state**
- The next 3 actions to execute:
  1. Restore external accessibility and verify `https://author.exmoment.com/sitemap_index.xml` is publicly fetchable.
  2. Re-run this recursive sitemap audit end-to-end on all discovered URLs.
  3. Implement and validate schema + query-target page alignment improvements from the complete crawl findings.

---

### Observed Facts vs Estimates

Observed facts:
- Browser navigation to sitemap failed with `net::ERR_BLOCKED_BY_CLIENT`.
- Shell fetch fallback failed with `Could not resolve host: author.exmoment.com`.
- No sub-sitemaps or page URLs could be extracted in this environment.

Estimates:
- All scores/probabilities in this report are provisional and intentionally conservative until a successful crawl can be completed.
