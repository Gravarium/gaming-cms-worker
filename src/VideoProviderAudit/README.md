# Video provider source audit

GET /admin/video-provider-audit?limit=50&after=0

The endpoint requires CMS_VIDEO_MANAGE and the Video module to be enabled. It returns a stable, ascending source_id cursor page. limit defaults to 50 and is bounded from 1 to 100; after is the last returned source ID (zero for the first page). next_cursor is null when the page is complete.

Each item contains only the source ID, registered provider key, enabled/authorized flags, a local status, and a resolved mode. Statuses are configured, disabled, unapproved, invalid_link, and unknown_provider. Mode is null when the provider is unknown or the saved link does not satisfy the local adapter rules.

Classification uses ProviderRegistry and its local URL-format checks. The endpoint makes no provider or media requests, changes no data, and never returns source URLs, labels, URL query tokens, or resolved playback URLs. A recognized mode describes the local format only; it does not indicate remote availability or successful playback.
