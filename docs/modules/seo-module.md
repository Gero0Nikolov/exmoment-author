# SEO Module

## Scope

SEO integration is handled by `modules/seo/YoastSeoIntegration.php`.

## Responsibilities

- Apply SEO metadata integration in publishing flows.
- Keep SEO behavior isolated from jobs and GPT core concerns.
- Detect Yoast SEO before writing Yoast-specific post metadata.
- Validate generated SEO titles, descriptions, and focus keyphrases before persistence.
- Preserve valid existing custom SEO metadata instead of overwriting it.

## Generated Yoast SEO title contract

The AI generates only the article-specific SEO title text, with a maximum length of 60 characters. It does not generate a separator, site name, rendered suffix, or Yoast variable syntax.

`YoastSeoIntegration::composeYoastTitleTemplate()` removes only exact `%%sep%%` and `%%sitename%%` tokens from the validated input and appends one canonical `%%sep%% %%sitename%%` suffix. It deliberately does not guess at or remove literal punctuation and site-name text because those strings may be legitimate title content.

For example:

```text
AI title: How to Build an Emergency Fund
Stored Yoast title: How to Build an Emergency Fund %%sep%% %%sitename%%
```

Yoast resolves `%%sep%%` from its configured title separator and `%%sitename%%` from the current site name when it renders the title. ExMoment Author therefore does not hardcode either value, and existing generated posts continue to reflect later changes to those settings.

The integration writes `_yoast_wpseo_title` only when Yoast is detected, the article-specific title passes existing validation, the canonical template is non-empty, and the existing SEO title is eligible for replacement. Missing or inactive Yoast returns safely without a metadata write.
