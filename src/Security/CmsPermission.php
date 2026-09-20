<?php

declare(strict_types=1);

namespace App\Security;

final class CmsPermission
{
    public const ACCESS = 'CMS_ACCESS';
    public const CONTENT = 'CMS_CONTENT_MANAGE';
    public const GAMING = 'CMS_GAMING_MANAGE';
    public const VIDEO = 'CMS_VIDEO_MANAGE';
    public const STORAGE = 'CMS_STORAGE_MANAGE';
    public const CONNECTORS = 'CMS_CONNECTORS_MANAGE';
    public const USERS = 'CMS_USERS_MANAGE';
    public const SETTINGS = 'CMS_SETTINGS_MANAGE';
    public const AUDIT = 'CMS_AUDIT_VIEW';

    public const ALL = [
        self::ACCESS,
        self::CONTENT,
        self::GAMING,
        self::VIDEO,
        self::STORAGE,
        self::CONNECTORS,
        self::USERS,
        self::SETTINGS,
        self::AUDIT,
    ];

    public const ASSIGNABLE = [
        'Inhalte, News, Kategorien und Menü' => self::CONTENT,
        'Gaming, Gilden und Bewerbungen' => self::GAMING,
        'Videos und Playlists' => self::VIDEO,
        'Dateispeicher und Medien' => self::STORAGE,
        'Externe Ziele und Schnittstellen' => self::CONNECTORS,
        'Benutzerkonten und Rechte' => self::USERS,
        'Website und Systemeinstellungen' => self::SETTINGS,
        'Sicherheits- und Auditprotokoll ansehen' => self::AUDIT,
    ];

    private function __construct() {}
}
