# Video workspace (WCP-636)

Entry points:
- `/admin/videos` → **Quellen, Creator & Live**; each video links to discovery metadata and source creation.
- `/video-workspace` → published videos, independent live sources, and the owner collection workspace.
- `/account/video-workspace` → owner-only watchlist, comment and clip correction/removal. Existing favorites, history opt-in and creation flows remain linked.

Each video may have several sources; the visitor chooses a source before any provider iframe or media source is rendered. Legacy video playback remains available as the original source. Direct `.mp4`, `.webm` and `.m3u8` URLs use browser media controls. HLS.js 1.6.13 is pinned in Importmap and served locally by AssetMapper, loaded only when native HLS is unavailable. Source servers must permit browser delivery and, for HLS.js, CORS. The CMS does not proxy media or extract source URLs from third-party players.

## Providers and extension

`ProviderAdapter` is autoconfigured with `app.video_workspace.provider`. Implement `catalogue()` and `resolve()` in a new autowired adapter to add the owner's authorized service without changing core controllers. Provider identifiers must be unique. Registry input and adapter output are revalidated; credentials, private/reserved literal addresses and non-HTTPS external URLs are rejected. There are no outbound server requests, provider API credentials or unsigned HTML embed imports.

Initial catalogue: YouTube, Vimeo, Twitch, VOE, Doodstream, Filemoon, Vidmoly, Dailymotion, Cloudflare Stream, Bunny Stream, Wistia, Streamable, PeerTube; direct MP4, WebM and HLS. This is a playback adapter layer, not an upload/metadata-import client. Official provider embeds retain provider branding and availability rules. Vidmoly and older Filemoon domains use links until current authorized embedding is established. New/rotating domains are not silently trusted.

Provider formats were checked against the official documentation linked by each catalogue entry on 2026-10-01. Filemoon.org support is limited to its documented supplied embed URL; historical Filemoon.sx URLs remain link-only. No claim that these domains are interchangeable accounts or that live remote media has been tested is made.

## Persistence and permissions

One additive `video_workspace_source` table is owned by this package. The separately registered `VideoWorkspaceMigrations` namespace avoids modifying reserved historical migration files. The new table references legacy videos and creator profiles, preserving their data. Existing creator/profile/collection changes use the original tables without recreating their entities. Source edits use optimistic version checking; legacy collection, creator, metadata and livestream forms carry a snapshot check and transaction boundary. Creator deletion is refused while referenced, preserving inherited private visibility. Populated source-table downgrade requires an export; empty-table downgrade removes only the new table.

Manager operations require `CMS_VIDEO_MANAGE`, the Video module, POST and CSRF. Owner operations recheck active account, ownership, snapshot and item/list scope. Playback rechecks publication, discoverability, creator and member/private visibility on GET and POST. These remain replaceable public enforcement boundaries for future Trusted/Fortress integration; no private runtime code is reconstructed. All Worker changes remain untrusted until independent Trusted review and exact-head CI. No merge or deployment.
