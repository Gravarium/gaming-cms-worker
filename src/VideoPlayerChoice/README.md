# Player choice (WCP-637)

Read-only dependency: exact green WCP-636 / PR #429, `638a82ffc0a75bbcbb53f1a482026327f14040db`.
No predecessor files modified. Companion `/video-players` directory, per-video and live routes reuse
publication, creator visibility, authorization and module gates. The automatically registered
`video.player-choice` widget can be placed with the existing CMS widget editor; it links to the directory.
The original workspace/navigation is unchanged because its predecessor claim remains locked.

Working choices: native MP4/WebM/HLS or locally served Video.js **8.24.1**; provider iframe;
official YouTube iframe with documented start/loop/mute/autoplay/language/subtitle parameters.
Never passes a provider page URL to a direct player, extracts a stream, masks provider UI or promises
branding removal. Source selection requires a fresh POST with CSRF; no remote source, poster or library
is fetched by the initial GET. Source labels and compatible engine names are all the GET exposes.
Direct playback speed and seeking depend on browser/stream support; autoplay may be blocked.
Only the engine name is stored in browser localStorage; no source URL, video ID or account information.

The manager-only `/video-players/integrations` inventory records all 17 requested products, with
primary product/documentation links. It does not claim CMS plugins or purchased services are installed.
SmartVideo requires the owner's Swarmify account/CDN key; MagicPlayer needs product files/license;
standalone TubePress needs its licensed distribution/setup; Flowplay requires the documented
Webflow/Flowplay setup and applicable plan; Framer components need their platform/product license.
`purc/mp-embed-youtube` is now verified at the owner's exact Packagist URL as a CONTENIDO module
depending on CONTENIDO and Mp Dev Tools; it is not installed in this Symfony CMS. The clarified
WordPress entry is **Polanger VideoHub Lite**, not the unrelated Video Hub importer at wpythub.com.
The exact Yii2 reference is `besnovatyj/yii2-cms-videojs-10-widget`, a **Video.js 10** wrapper requiring
Yii2; it is separate from our working standalone Video.js **8.24.1** integration. Neither an iframe
nor this catalogue implements the whole Polanger/Easy Youtube Videos/TubePress CMS product.
No additional provider catalogue is part of this package.

Owner URL follow-up (2026-10-01): generic Codester/Framer portals resolve to the specific product
pages already cited in the catalogue. wpfanyi.com redirects to wpcy.com and does not establish the
Widget Responsive for Youtube identity; the official WordPress plugin page remains the reference.
BRIX's Flowplay page describes ViDesigns' product, not a different engine; the primary ViDesigns
reference is retained. The Simple YouTube Player product page under ai-visions.net was retrieved
directly and confirms Mario G. Rizzo / AI VISIONS' Joomla module. The site displays the 7Tage.info
branding already found earlier; the specific owner-supplied host's product URL is now recorded.
Localized WordPress plugin pages share the same plugin slug as the canonical wordpress.org page.

Video.js vendor assets are copied unchanged from the npm `video.js@8.24.1` distribution:
`dist/video.min.js`, `dist/video-js.min.css`, `LICENSE`. Apache-2.0 license and bundled source notices
are retained. No CDN request is needed for this player. Release/API reference:
https://github.com/videojs/video.js/tree/v8.24.1
YouTube parameter reference: https://developers.google.com/youtube/player_parameters
Research date: 2026-10-01. Remote actual-media playback cannot be established by fixture tests.

Validation includes compatibility/options unit tests, source consent and privacy functional tests,
native/Video.js lifecycle/fallback/storage JavaScript tests and complete exact-head Worker CI.
Worker-only proposal; no Trusted merge/deployment.
