# Video provider integration API (WCP-649)

Manager-only, same-origin JSON endpoints for integrations that need to discover configured video providers and validate an operator-supplied source URL before adding it to a video. Depends on the WCP-636 registry and WCP-643 named adapters. It does not save videos or fetch provider pages.

| Route | Method | Meaning |
| --- | --- | --- |
| `/admin/video-provider-api` | GET | Version 1 provider identifiers, labels, documentation URLs, declared capabilities and a session-bound CSRF token. |
| `/admin/video-provider-api/resolve` | POST | Form fields `_token`, `provider`, `url`; return canonical `provider`, `mode` (`iframe`, `video`, `hls`, `link`) and `url`, or HTTP 422. |

Both routes require an active account with `CMS_VIDEO_MANAGE` and the enabled video module. Responses have `private, no-store` and `nosniff` headers; unauthorized users cannot enumerate capabilities or submit URLs. The POST token comes from the GET response in the same session. A successful resolution means only that the URL shape and host match a registered adapter. It does not prove the media is reachable, permitted for use, playable in a browser or free of provider branding. Existing consent and publication checks still apply in the actual video workspace.
