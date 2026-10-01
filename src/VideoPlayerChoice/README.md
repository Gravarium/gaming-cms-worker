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
The exact `mp_embed_youtube` name lacks verified current primary documentation and stays identified
as pending rather than guessed. Import/gallery capabilities of Video Hub/Easy Youtube Videos/TubePress
are not implemented by an iframe. No additional provider catalogue is part of this package.

Video.js vendor assets are copied unchanged from the npm `video.js@8.24.1` distribution:
`dist/video.min.js`, `dist/video-js.min.css`, `LICENSE`. Apache-2.0 license and bundled source notices
are retained. No CDN request is needed for this player. Release/API reference:
https://github.com/videojs/video.js/tree/v8.24.1
YouTube parameter reference: https://developers.google.com/youtube/player_parameters
Research date: 2026-10-01. Remote actual-media playback cannot be established by fixture tests.

Validation includes compatibility/options unit tests, source consent and privacy functional tests,
native/Video.js lifecycle/fallback/storage JavaScript tests and complete exact-head Worker CI.
Worker-only proposal; no Trusted merge/deployment.
