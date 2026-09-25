# Gaming CMS Worker

This repository is a sanitized, incomplete development workspace for the Gaming CMS project. It is used for isolated worker development and automated validation. It is not the production source of truth, not a production distribution, and must not be deployed directly.

## Copyright and usage

Copyright © 2026 Gravarium. All rights reserved.

This repository is **not** released under an open-source license. No general permission is granted to copy, modify, adapt, translate, merge, redistribute, publish, sublicense, sell, deploy, or otherwise use this code or any part of it outside the limited rights that GitHub's Terms of Service and technical platform functions necessarily provide.

The complete copyright and usage notice is in [LICENSE.md](LICENSE.md). Public visibility does not transfer ownership or grant a general usage license.

Creating a GitHub fork, viewing the repository, or using GitHub features that are required to operate the public repository does not make a fork official and does not grant permission to use the code as an independent software product. The Trusted repository, private runtime components, production configuration, secrets, deployment credentials, and operational memory are intentionally not included here.

## Worker boundary

The rules in [WORKER.md](WORKER.md) remain binding for worker development:

- worker output is untrusted until it has been reviewed and transferred into a fresh Trusted feature branch;
- this repository is not the production source of truth;
- no credentials, production URLs, private keys, backups, real user data, or production-only components may be added;
- protective mechanisms, validation, authorization, recovery, rollback, cleanup, integrity, and fail-closed behavior must not be weakened.

Third-party dependencies remain subject to their own copyright and license terms. This notice does not claim ownership of third-party material.
