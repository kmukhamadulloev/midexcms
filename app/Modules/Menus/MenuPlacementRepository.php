<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Menus;

use MidexCMS\Core\Database;

final class MenuPlacementRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function placementsForTheme(string $themeKey): array
    {
        return $this->database->select(
            'SELECT placements.id, placements.theme_key, placements.slot_key, placements.component_type,
                    placements.component_ref_id, placements.position, menus.name AS menu_name, menus.key AS menu_key
             FROM template_module_placements placements
             LEFT JOIN menus ON menus.id = placements.component_ref_id AND placements.component_type = :component_type
             WHERE placements.theme_key = :theme_key
             ORDER BY placements.position ASC, placements.id ASC',
            [
                'component_type' => 'menu',
                'theme_key' => $themeKey,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function placementForSlot(string $themeKey, string $slotKey): ?array
    {
        return $this->database->selectOne(
            'SELECT id, theme_key, slot_key, component_type, component_ref_id, position
             FROM template_module_placements
             WHERE theme_key = :theme_key AND slot_key = :slot_key
             LIMIT 1',
            [
                'theme_key' => $themeKey,
                'slot_key' => $slotKey,
            ]
        );
    }

    public function savePlacement(string $themeKey, string $slotKey, string $componentType, int $componentRefId, int $position = 1): void
    {
        $this->database->statement(
            'INSERT INTO template_module_placements (theme_key, slot_key, component_type, component_ref_id, position)
             VALUES (:theme_key, :slot_key, :component_type, :component_ref_id, :position)
             ON CONFLICT (theme_key, slot_key)
             DO UPDATE SET component_type = EXCLUDED.component_type,
                           component_ref_id = EXCLUDED.component_ref_id,
                           position = EXCLUDED.position,
                           updated_at = CURRENT_TIMESTAMP',
            [
                'theme_key' => $themeKey,
                'slot_key' => $slotKey,
                'component_type' => $componentType,
                'component_ref_id' => $componentRefId,
                'position' => $position,
            ]
        );
    }

    public function deletePlacement(string $themeKey, string $slotKey): void
    {
        $this->database->delete(
            'DELETE FROM template_module_placements WHERE theme_key = :theme_key AND slot_key = :slot_key',
            [
                'theme_key' => $themeKey,
                'slot_key' => $slotKey,
            ]
        );
    }
}
