# Content withdrawal for the Sagamok Strong move

Russell requested withdrawal of the eight pages bearing his name and the
livestream page on October 28, 2026, in preparation for moving this material
to Sagamok Strong. This release does not publish anything on Sagamok Strong.

The five managed articles remain stored as unpublished records with their
revision history. Their original sources remain under
`resources/content/article-seeds/`. Both new installations and later seed
refreshes keep these slugs unpublished:

- `sagamok-membership-did-not-begin-with-the-list`
- `sagamok-trespass-bylaw-session-was-backwards`
- `sagamok-south-market-land-deal`
- `sagamok-membership-before-trespass`
- `waasmoowin-deal-public-record`

Four static routes are removed from the public route catalogue. Their Twig
sources remain preserved for migration:

- `/communities/sagamok/it-accountability`
- `/communities/sagamok/members-website-issue`
- `/communities/sagamok/where-your-data-lives`
- `/live`

The withdrawn URLs return 404. Public cards, internal links and both sitemap
implementations omit them. The normal `app:reindex` deployment step removes
their old page chunks from the searchable corpus. No redirect is introduced
until a destination page has been published and verified.

Qualification includes preservation of the draft article bodies, repeat
seeding, anonymous route denial, absence from public listings and sitemaps,
and the normal release checks. Production must also verify the five existing
articles are unpublished and their old corpus chunks are absent. Other
authors' articles, petition records and consent instruments remain outside
this withdrawal.
