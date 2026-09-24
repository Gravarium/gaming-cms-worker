# Gaming CMS worker workspace

This repository is a sanitized development workspace. It is intentionally not a production distribution.

## Branch and continuation model

- `main` is the sole generated sanitized baseline for new work.
- Never commit directly to `main`.
- Work on exactly one published FCP feature branch at a time.
- Existing unfinished work always comes before a new task. After any error, red CI, tool failure, usage limit or chat interruption, fetch and continue the same feature branch from its verified remote checkpoint.
- An active claim remains exclusive across interruptions. The owning continuation resumes it; every other AI must leave that FCP, feature branch and PR untouched until an explicit release or transfer.
- Push a coherent checkpoint within 30 minutes of real work and before interruption.
- Historical `worker/account-N` refs are not assignable baselines. Existing recovery feature branches keep their ancestry until complete.
- A Worker PR is a review handoff only. Do not treat the Worker repository as the production source of truth.
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
