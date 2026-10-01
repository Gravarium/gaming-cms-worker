<?php

declare(strict_types=1);

namespace App\VideoPlayerChoice;

final class IntegrationCatalogue
{
    /** @return list<array{name:string,platform:string,status:string,detail:string,url:string}> */
    public function entries(): array
    {
        $rows = [
            ['MagicPlayer', 'WordPress', 'CMS-Erweiterung · Lizenz erforderlich', 'Original benötigt WordPress und die Plugin-Dateien. YouTube, Vimeo und direkte Dateien sind bei uns über eigene Player nutzbar.', 'https://www.codester.com/items/66005/magicplayer-wordpress-custom-youtube-video-player'],
            ['Widget Responsive for Youtube', 'WordPress', 'CMS-Erweiterung', 'Responsive YouTube-Wiedergabe wird über unseren YouTube-Player abgedeckt; das WordPress-Plugin ist nicht installiert.', 'https://wordpress.org/plugins/youtube-widget-responsive/'],
            ['SmartVideo', 'WordPress / eigener Dienst', 'Anbieterzugang erforderlich', 'Swarmify benötigt einen eigenen CDN-Key und die vom Anbieter bereitgestellte Einrichtung. Ohne diesen Zugang nicht aktivierbar.', 'https://support.swarmify.com/article/73-install-smartvideo-on-wordpress'],
            ['Settings for YouTube block', 'WordPress', 'CMS-Erweiterung', 'Startzeit, Sprache, Untertitel, Schleife und Stummschaltung sind über YouTube mit Einstellungen nutzbar. Gutenberg-Blöcke gehören zu WordPress.', 'https://wordpress.org/plugins/settings-for-youtube-block/'],
            ['Video Hub', 'WordPress', 'Import-Erweiterung', 'Importiert YouTube-Inhalte nach WordPress. Ein Kanal-/Playlist-Import ist kein eigener Player und hier noch nicht implementiert.', 'https://wpythub.com/'],
            ['TubePress', 'WordPress / eigenständig', 'Lizenz / Einrichtung erforderlich', 'Galerie-Lösung; das eigenständige Pro-Produkt braucht die passende Lizenz und Produktdateien. Der Originaldienst ist nicht eingerichtet.', 'https://tubepress.com/license/'],
            ['TF Youtube', 'Joomla', 'CMS-Erweiterung', 'Joomla-Feld für YouTube-IDs. Unsere Videoquellen liefern die entsprechende YouTube-Einbettung.', 'https://extensions.joomla.org/extension/tf-youtube/'],
            ['Easy Youtube Videos', 'Joomla', 'CMS-Erweiterung', 'Joomla-Modul für Videos, Kanäle und Playlists. Einzelvideos sind abgedeckt; Kanal-Import ist nicht implementiert.', 'https://www.joomboost.com/support/documentation/47-joomla-modules/107-guide-of-easy-youtube-videos.html'],
            ['OSYouTube Pro', 'Joomla', 'CMS-Erweiterung · Lizenz erforderlich', 'Original benötigt Joomla. Vergleichbare aktuelle YouTube-Einstellungen sind über unsere Einstellungsoption nutzbar.', 'https://www.joomlashack.com/docs/osyoutube/'],
            ['Youtube (Joomill)', 'Joomla', 'CMS-Erweiterung', 'Joomla-Feldplugin; die Video-Einbettung ist über den YouTube-Player möglich. Pro-Funktionen des Originals sind nicht installiert.', 'https://www.joomill-extensions.com/documentation/custom-fields-plugins/youtube-configuration'],
            ['Simple YouTube Player', 'Joomla', 'CMS-Erweiterung', 'Joomla-Modul laut Anbieterbeschreibung. Einzelvideo, Startzeit und Schleife werden bei uns über YouTube-Einstellungen abgedeckt.', 'https://www.7tage.info/simple-youtube-player-youtube-videos-ganz-einfach-in-joomla-einbinden'],
            ['Video Embed Field', 'Drupal', 'CMS-Erweiterung', 'Drupal-Feld und Provider-Erweiterung. Unsere Quellenverwaltung unterstützt YouTube und Vimeo ohne eine Drupal-Installation.', 'https://www.drupal.org/project/video_embed_field'],
            ['ng_fastyoutube', 'TYPO3', 'CMS-Erweiterung', 'Lädt den YouTube-Player erst auf Anfrage. Bei uns erfolgt die Einbettung erst nach Quelle auswählen und Laden.', 'https://extensions.typo3.org/extension/ng_fastyoutube'],
            ['Clean YouTube Player (Framer)', 'Framer', 'Plattform-Komponente · Lizenz erforderlich', 'Framer-Komponente. Das Original ist nicht installiert; unsere YouTube-Einbettung nutzt den offiziellen Player.', 'https://www.framer.com/marketplace/components/clean-youtube-player/'],
            ['Flowplay (Webflow)', 'Webflow', 'Plattform-Einrichtung erforderlich', 'Player-Bibliothek für Webflow; benötigt die dokumentierte Einrichtung und ggf. Flowplay+. Nicht mit Flowplayer verwechseln.', 'https://videsigns.uk/flowplay'],
            ['Video.js (Yii2)', 'Eigenständig / Yii2-Wrapper', 'Direkt nutzbar', 'Video.js 8.24.1 wird lokal ausgeliefert und ist für freigegebene MP4-, WebM- und HLS-Quellen auswählbar. Der Yii2-Wrapper wird nicht benötigt.', 'https://github.com/videojs/video.js/tree/v8.24.1'],
            ['mp_embed_youtube (CONTENIDO)', 'CONTENIDO', 'Identifikation offen', 'Für diesen exakten Modulnamen konnte keine verlässliche aktuelle Primärdokumentation gefunden werden. Keine Originalintegration behauptet; YouTube-Einbettung ist verfügbar.', 'https://www.contenido.org/'],
        ];
        return array_values(array_map(static fn (array $row): array => ['name' => $row[0], 'platform' => $row[1], 'status' => $row[2], 'detail' => $row[3], 'url' => $row[4]], $rows));
    }
}
