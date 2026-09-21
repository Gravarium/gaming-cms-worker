# Gaming CMS worker workspace

This repository is a sanitized development workspace. It is intentionally not a production distribution.

## Branch model

- `main` is the generated sanitized baseline from the trusted repository.
- `worker/account-N` branches are also generated baselines and may be force-refreshed automatically after trusted `main` changes.
- Never commit directly to `main` or `worker/account-N`.
- Create a feature branch from your assigned `worker/account-N` branch and work only there.
- A worker PR back to `worker/account-N` is a review/integration handoff. Do not treat the worker repository as the production source of truth.
- Automatic baseline refreshes never reset worker-owned feature branches. Reconcile conflicts in the feature branch instead of weakening the baseline policy.

## Security and private boundary

- Never add credentials, production URLs, private keys, server configuration, backups or real user data.
- Use only local/dev/test configuration.
- Do not remove or weaken the worker-only production guard, snapshot manifest, sanitization or private-runtime boundary.
- `private/`, `PrivateCore`, production connector parsers/resolvers, deployment/release internals, production secrets and internal operational memory are intentionally absent. Do not reconstruct, imitate or copy them into worker-visible source.
- Missing trusted-only functionality must be handled through existing public contracts, development fakes, mocks or stubs.
- Existing security, validation, authorization, recovery, rollback, cleanup, integrity, fail-closed and other protective mechanisms are invariants. Never remove, bypass, weaken or replace them with a weaker equivalent merely to satisfy PHPStan, tests, CI, linters or other analysis tools.
- When an analyzer or test reports a problem in protective code, diagnose the real cause. Prefer stronger typing, correct control flow, explicit state modelling, annotations or a structural fix that preserves or improves the protection.
- Never suppress a diagnostic, lower an analysis rule or level, weaken/delete a security regression test, or change fail-closed behavior to fail-open just to obtain a green build.
- A simplification is acceptable only when its protection semantics are demonstrably at least as strong as before. If that cannot be established from the worker-visible code, keep the stronger behavior and flag the point for trusted integration review.
- If a task requires deployment, release signing, production secrets, private runtime components or server operations, stop and hand that part back to the trusted integration repository.

## Integration rule

The trusted repository remains the sole source of truth. Worker output is untrusted input until it is reviewed, transferred into a fresh trusted feature branch and passes the full trusted CI. No worker branch is deployed directly.
