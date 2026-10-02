# Video viewing journey (WCP-638)

Read-only dependency: WCP-637 / PR 430, exact green head `0aefb975905b67bd649cc37a027fdd5d609d1a37` and CI 36838962550. Its ancestry includes WCP-636 / PR 429. No predecessor file changes, tables, migrations or new media dependency.

Entry points:
- `/video-viewing`: paginated playlist directory.
- `/video-viewing/playlists/{slug}`: source/engine selection, stable legacy ID order, previous/next. Optional `video` query identifies an item and rechecks membership and visibility.
- `/video-viewing/videos/{slug}`: standalone playback with optional device resume.
- `/admin/video-playback-check`: manager-only paginated video checks.
- `/admin/video-playback-check/videos/{id}`: format/freedom-to-preview checks and POST-consented preview, including unpublished videos. Links to existing source creation/edit and metadata forms.
- `video.viewing-journey`: registered Page Builder widget to make these companion routes discoverable without changing locked navigation/templates.

Initial GET never emits remote media URLs or creates players. Public viewing checks publication, discoverability, creator visibility, active viewer, source enabled/authorized and playlist membership on every request. Each POST requires a scoped CSRF token. A source from another video cannot be selected. Arbitrary external provider pages are not mislabeled as direct media. Direct URLs are resolved only by the predecessor registry; uploads retain the existing playback policy.

Continuous playback is explicit playlist-wide consent to authorized direct MP4/WebM/HLS sources. The ended event submits the next item through a fresh POST, preserving the selected direct engine and rechecking every access boundary. No cross-provider extraction or stream concealment. If the next item has only provider embeds, it shows the selection screen and requires explicit consent. Provider iframes use manual next links. Browser autoplay may require pressing Play. The checkbox below the playing video cancels automatic advance. Legacy playlists lack custom item positions, so order is ascending ID; private/invisible items are skipped in bounded DB batches without a truncation cutoff.

Resume is an explicit per-browser opt-in for publicly accessible direct media, never enabled for private/member videos, private creators or manager previews. It stores only opaque SHA-256 source/version keys, integer seconds and timestamps in `cms-video-viewing-v1`, capped at 50 positions and 30 days. No URLs, titles, user IDs or account data are stored or sent to a server. Source URL edits produce a new key. Restore requires a click and checks finite duration and available seek ranges. Live streams with infinite duration are excluded. Completion removes the saved position. Turning off or deleting clears the entire store. Storage failures do not prevent playback. Existing account history opt-in is untouched.

Preflight validates the CMS configuration, never claims a remote health check. The browser preview and direct-player error hints diagnose network/format/decode failures; iframe/login/CORS/region/provider restrictions still require real authorized media to verify. No server-side URL requests, guessed APIs, account credentials or branding removal.

Public and manager responses use private/no-store plus the inherited Turbo no-cache meta. Media/Video.js handlers are released on Turbo navigation and pagehide. Manager preview requires CMS_VIDEO_MANAGE and module enabled; it does not publish or mutate a video. These are public replaceable enforcement seams for future Trusted/Fortress integration. All work remains an untrusted proposal: no Trusted merge/deployment.

Validation: JavaScript opt-in/expiry/cap/error/restore/erase/ended/lifecycle/native/Video.js adapter cases, functional playlist/source isolation and post-change visibility, unpublished manager preview/CSRF/permissions, private resume exclusion, widget and disabled module. Complete exact-head Worker CI is the final gate; no local PHP executable exists in this workspace.
