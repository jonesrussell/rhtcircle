# Editorial content model audit

Date: 2026-08-11

## Decision

RHT Circle does not need a new CMS content type for analysis.

The existing revisioned `article` bundle already carries the fields and behaviour needed by analysis, investigation and commentary: source HTML, editorial limits, community routing, author and date metadata, social and hero images, summaries, listing cards, canonical URLs, previews and sitemap entries.

Creating a separate analysis bundle would duplicate that schema, split a single news archive and risk bypassing the publishing safeguards already attached to articles.

## The actual gap

The editorial lane has been stored as free text in three fields:

| Lane | `section` | `kicker` prefix | `action_label` |
| --- | --- | --- | --- |
| Analysis | `RHT Circle analysis` | `Analysis |` | `Read the analysis` |
| Investigation | `RHT Circle investigation` | `Investigation |` or the existing `Accountability |` | `Read the investigation` |
| Commentary | `RHT Circle commentary` | `Commentary |` | `Read the commentary` |

The `EditorialLaneValidator` now enforces those combinations at the publishing boundary. This adds a controlled vocabulary without changing the database schema or public routes.

## Analysis standard

An analysis article should:

1. state the question or interpretive choice in the lead;
2. distinguish the source record from the writer's inference;
3. identify what the record does not establish;
4. include a primary-source register;
5. use the common article layout, listing and revision workflow;
6. carry `Analysis | [community or subject]`, `RHT Circle analysis` and `Read the analysis` metadata.

The Alan Ojiig Corbiere presentation analysis is the first article added after this audit. It remains an `article` and uses the `Analysis` lane.
