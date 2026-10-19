# Changelog

All notable changes to `dashed-openai` will be documented in this file.

## v4.4.0 - 2026-10-19

### Changed
- **Elke aanroep in het AI-logboek.** Chat (tekst, JSON, vision), embeddings en afbeeldingen loggen via `AiLogger` (dashed-ai) met het model en de usage uit het antwoord. Een afbeelding telt als één eenheid.
- `chatRequest()` neemt `$options['model']` over als dat is meegegeven; zonder blijft het gpt-4o.
