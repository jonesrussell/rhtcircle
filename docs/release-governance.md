# Release governance

GitHub `jonesrussell/rhtcircle` main is the canonical application source. GitHub `jonesrussell/waaseyaa-infra` main owns the production pin and deployment tooling. The local checkout and live container are consumers, not alternative authorities.

## Integration

Fetch main before starting work and before landing. Work on a named candidate branch. Never replace main with an old working directory, force-push main, copy a whole checkout over production, or use the production checkout as a place to author commits. Merge newer main changes into the candidate, preserve their behavior, and recheck affected boundaries. A clean status does not mean a checkout is current.

The only permitted main update is a normal fast-forward push of the reviewed, checked candidate. Record its full commit SHA, dependency lock identity, verification results and outstanding diagnostic limitations. If remote main changes during qualification, reconcile and qualify the changed boundaries before landing.

## Verification without Actions

GitHub Actions are optional feedback only. They are not required to establish release evidence or execute deployment, and they must not automatically write generated commits or deploy on a main push.

Run `composer check`, focused integration checks, production field-access preflight, schema checks and modified-route browser checks. Run full integration on the supported Linux runtime, against the exact committed source and locked dependencies. Windows SQLite cleanup warnings are host limitations, not substitutes for Linux verification. Dry-run corpus ingestion must succeed. Strict Bimaaji graph export is a separate diagnostic: legacy framework and application route contributors must be recorded accurately, not hidden or mistaken for a failing runtime route.

The release must preserve separate petition consent instruments, disabled collection schedules and public-chat gating. Media publishing must initialize after authorization policies exist. Public media URLs require a published catalogue row and must stop serving after retraction. Operational pipeline records must remain inaccessible to anonymous accounts. Framework-owned field classifications must follow the framework contract; app classifications cover app-added fields.

## Direct manual promotion

Land the exact checked app SHA on GitHub main, then commit its pin to infrastructure main. Fetch infrastructure main on the Pi, refuse dirty or divergent state, verify the expected exact SHA, acquire the existing shared deployment lock, and use the canonical target promotion script for `rhtcircle` only. Do not create a second deployment engine or rely on Actions dispatch.

Before replacing the serving container, qualify the built candidate against a fresh, isolated production database snapshot, use supported schema/configuration/authority upgrade commands, and check field readiness with production settings. Never upload the local development database or local secrets. Preserve the bounded prior image and the standard database recovery snapshot required by infrastructure operations.

After promotion, verify the public homepage, news filters, all Nation desks, Sagamok collections, publishing boundaries and modified content. Record the app SHA, infrastructure SHA and image identity actually serving. A source push is not proof of deployment.
