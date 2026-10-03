export function parseVideoUrl(value) {
  let url;
  try { url = new URL(value); } catch { return null; }
  if (url.protocol !== 'https:' || url.username || url.password || url.port) return null;
  const host = url.hostname.toLowerCase();
  if (host === 'vimeo.com' || host === 'www.vimeo.com' || host === 'player.vimeo.com') {
    const id = url.pathname.match(/^\/(?:video\/)?([0-9]{1,20})\/?$/)?.[1];
    return id ? {provider: 'vimeo', videoId: id} : null;
  }
  let id = null;
  if (host === 'youtu.be') id = url.pathname.match(/^\/([A-Za-z0-9_-]{6,20})\/?$/)?.[1];
  else if (host === 'youtube.com' || host === 'www.youtube.com' || host === 'www.youtube-nocookie.com') {
    id = url.pathname === '/watch' ? url.searchParams.get('v') : url.pathname.match(/^\/(?:shorts|embed)\/([A-Za-z0-9_-]{6,20})\/?$/)?.[1];
  }
  return id && /^[A-Za-z0-9_-]{6,20}$/.test(id) ? {provider: 'youtube', videoId: id} : null;
}

export function videoPreviewUrl(provider, videoId) {
  if (provider === 'youtube' && /^[A-Za-z0-9_-]{6,20}$/.test(videoId)) return 'https://www.youtube-nocookie.com/embed/' + videoId;
  if (provider === 'vimeo' && /^[0-9]{1,20}$/.test(videoId)) return 'https://player.vimeo.com/video/' + videoId;
  return null;
}
