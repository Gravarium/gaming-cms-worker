<?php

declare(strict_types=1);
namespace App\Module;
final class CmsModuleCatalog
{
    /** @return array<string, array{key:string,name:string,version:string,required:bool,dependencies:list<string>,routePrefixes:list<string>}> */
    public function all(): array
    {
        $definitions = [
            $this->module('core', 'CMS-Kern', true, [], []),
            $this->module('content', 'Inhalte und Navigation', false, ['core'], ['app_admin_content', 'app_admin_category', 'app_admin_menu', 'app_content', 'app_page', 'app_news', 'app_content_search']),
            $this->module('media', 'Medien und Speicher', false, ['core'], ['app_admin_storage']),
            $this->module('gaming', 'Gaming, Gilden und Clans', false, ['core', 'content'], ['app_admin_gaming', 'app_admin_guild', 'app_gaming', 'app_guild']),
            $this->module('video', 'Videokatalog', false, ['core', 'media'], ['app_admin_video', 'app_video']),
            $this->module('integrations', 'Externe Ziele und Backups', false, ['core'], ['app_admin_connector', 'app_admin_backup']),
            $this->module('users', 'Benutzer und Rechte', false, ['core'], ['app_admin_user', 'app_admin_access_role']),
            $this->module('notifications', 'Benachrichtigungen', false, ['core'], ['app_admin_notification']),
            $this->module('operations', 'Systembetrieb und Warteschlangen', false, ['core'], ['app_admin_queue']),
        ];
        $indexed = []; foreach ($definitions as $definition) { $indexed[$definition['key']] = $definition; } return $indexed;
    }
    /**
     * @param list<string> $dependencies
     * @param list<string> $routePrefixes
     * @return array{key:string,name:string,version:string,required:bool,dependencies:list<string>,routePrefixes:list<string>}
     */
    private function module(string $key, string $name, bool $required, array $dependencies, array $routePrefixes): array { return compact('key', 'name', 'required', 'dependencies', 'routePrefixes') + ['version' => '1.0.0']; }
}
