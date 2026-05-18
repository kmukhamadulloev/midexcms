<?php

declare(strict_types=1);

namespace MidexCMS\Core\Admin;

final class AdminNavigation
{
    /**
     * @return array<int, array{label: string, href: string, section: string, icon: string, meta: string}>
     */
    public static function items(): array
    {
        return [
            ['label' => 'Dashboard', 'href' => '/admin', 'section' => 'Core', 'icon' => 'DB', 'meta' => 'Control room'],
            ['label' => 'Pages', 'href' => '/admin/pages', 'section' => 'Publish', 'icon' => 'PG', 'meta' => 'Site tree'],
            ['label' => 'Media', 'href' => '/admin/media', 'section' => 'Publish', 'icon' => 'MD', 'meta' => 'Assets'],
            ['label' => 'Menus', 'href' => '/admin/menus', 'section' => 'Publish', 'icon' => 'MN', 'meta' => 'Navigation'],
            ['label' => 'Forms', 'href' => '/admin/forms', 'section' => 'Engage', 'icon' => 'FM', 'meta' => 'Inbound'],
            ['label' => 'Comments', 'href' => '/admin/comments', 'section' => 'Engage', 'icon' => 'CM', 'meta' => 'Moderation'],
            ['label' => 'Analytics', 'href' => '/admin/analytics', 'section' => 'Intelligence', 'icon' => 'AN', 'meta' => 'Traffic'],
            ['label' => 'Settings', 'href' => '/admin/settings', 'section' => 'System', 'icon' => 'ST', 'meta' => 'Config'],
            ['label' => 'Backups', 'href' => '/admin/backups', 'section' => 'System', 'icon' => 'BK', 'meta' => 'Recovery'],
        ];
    }
}
