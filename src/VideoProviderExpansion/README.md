# Named portal sources (WCP-643)

This tagged adapter extends the existing `/admin/video-workspace` provider selector and consent-gated video/live source flow. The owner's fifteen-name list contains two existing providers, PeerTube and Dailymotion (WCP-636), plus thirteen new source identifiers here. All descriptions below concern only provider-supplied URLs for media the operator is authorized to publish. No HTML parsing, outbound server requests or embed-to-file extraction is performed.

| Provider | Supplied URL capability |
| --- | --- |
| ScreenPal | Official `/player/{id}` iframe; branding follows the account's player settings. |
| Kinescope | Official `/embed/{id}` white-label iframe, or explicitly supplied `/master.m3u8` through the local HLS player. |
| Internet Archive | Official item iframe; explicitly supplied direct MP4/WebM through the local player. Verify item rights separately. |
| Videco | Official `/embed/{shareSlug}` iframe; own-brand options depend on provider plan. |
| Kapwing | Supplied `/e/{id}` iframe; other recognized pages are links. Branding and video privacy follow provider settings. |
| Kaltura | Official `embedPlaykitJs` iframe with partner, player, entry and optional supplied session key. |
| YourImageShare | Official `/ib/{id}.mp4|webm` direct files through local player; other pages link. |
| DBimg, Vidzflow | Explicit MP4/WebM URL on the known host can use the local player; other pages link. Actual CDN host variation requires a documented later adapter. |
| VdoHide, GrooveVideo, Viddler, MyVideoSpot | Provider URL link only until a current verified embed URL or direct-media format is supplied. |

Previously implemented PeerTube and Dailymotion remain available. The official player for an iframe controls its own branding; only Kinescope publicly advertises its player without a vendor logo. A watermark burned into the file is not affected. Direct formats use the site's own player and require browser access (and CORS for HLS.js). The URL is stored in `video_workspace_source` as before; account API credentials must not be pasted into the source URL. Kaltura's optional `ks` is a provider-issued playback session token, which the video workspace stores with the source; prefer a public embed for persistent sources. No account integration or upload API is claimed. Fixture URLs validate mapping and permission paths, not live provider availability.
