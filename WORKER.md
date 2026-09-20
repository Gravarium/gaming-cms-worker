# Gaming CMS worker workspace

This repository is a sanitized development workspace. It is intentionally not a production distribution.

## Rules

- Work only in your assigned branch, for example `worker/account-2`.
- Never add credentials, production URLs, private keys, server configuration, backups or real user data.
- Use only local/dev/test configuration.
- Do not remove or weaken the worker-only production guard.
- Keep changes focused so the trusted integration repository can review and import them safely.
- If a task requires deployment, release signing, production secrets, private runtime components or server operations, stop and hand that part back to the trusted integration repository.

The trusted repository remains the source of truth. Worker branches are disposable and may be reset from a newer sanitized snapshot.
