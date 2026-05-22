<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Menus;

use MidexCMS\Core\Database;

final class MenuRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allMenus(): array
    {
        return $this->database->select(
            'SELECT id, key, name, created_at, updated_at FROM menus ORDER BY name ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMenu(int $id): ?array
    {
        return $this->database->selectOne(
            'SELECT id, key, name FROM menus WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function createMenu(string $key, string $name): int
    {
        $this->database->statement(
            'INSERT INTO menus (key, name) VALUES (:key, :name)',
            ['key' => $key, 'name' => $name]
        );

        $row = $this->database->selectOne('SELECT currval(pg_get_serial_sequence(\'menus\', \'id\')) AS id');

        return (int) ($row['id'] ?? 0);
    }

    public function updateMenu(int $id, string $name): void
    {
        $this->database->statement(
            'UPDATE menus SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id, 'name' => $name]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function itemsForMenu(int $menuId): array
    {
        return $this->database->select(
            'SELECT menu_items.id, menu_items.menu_id, menu_items.parent_id, menu_items.page_id, menu_items.label, menu_items.url,
                    COALESCE(menu_items.url, pages.path) AS resolved_url, menu_items.target, menu_items.sort_order
             FROM menu_items
             LEFT JOIN pages ON pages.id = menu_items.page_id
             WHERE menu_id = :menu_id
             ORDER BY menu_items.sort_order ASC, menu_items.id ASC',
            ['menu_id' => $menuId]
        );
    }

    public function replaceItems(int $menuId, array $items): void
    {
        $this->database->transaction(function (Database $database) use ($menuId, $items): void {
            $database->statement('DELETE FROM menu_items WHERE menu_id = :menu_id', ['menu_id' => $menuId]);
            $idMap = [];

            foreach ($items as $item) {
                $database->statement(
                    'INSERT INTO menu_items (menu_id, parent_id, page_id, label, url, target, sort_order)
                     VALUES (:menu_id, :parent_id, :page_id, :label, :url, :target, :sort_order)',
                    [
                        'menu_id' => $menuId,
                        'parent_id' => null,
                        'page_id' => $item['page_id'],
                        'label' => $item['label'],
                        'url' => $item['url'],
                        'target' => $item['target'],
                        'sort_order' => $item['sort_order'],
                    ]
                );

                $row = $database->selectOne('SELECT currval(pg_get_serial_sequence(\'menu_items\', \'id\')) AS id');
                $idMap[$item['client_id']] = (int) ($row['id'] ?? 0);
            }

            foreach ($items as $item) {
                if ($item['parent_ref'] === null) {
                    continue;
                }

                $database->statement(
                    'UPDATE menu_items SET parent_id = :parent_id WHERE id = :id',
                    [
                        'id' => $idMap[$item['client_id']] ?? 0,
                        'parent_id' => $idMap[$item['parent_ref']] ?? null,
                    ]
                );
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pageOptions(): array
    {
        return $this->database->select(
            'SELECT id, title, path FROM pages WHERE deleted_at IS NULL AND status = :status ORDER BY path ASC',
            ['status' => 'published']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function menuByKey(string $key): ?array
    {
        return $this->database->selectOne(
            'SELECT id, key, name FROM menus WHERE key = :key LIMIT 1',
            ['key' => $key]
        );
    }
}
