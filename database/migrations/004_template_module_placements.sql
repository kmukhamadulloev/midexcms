CREATE TABLE IF NOT EXISTS template_module_placements (
    id BIGSERIAL PRIMARY KEY,
    theme_key VARCHAR(100) NOT NULL,
    slot_key VARCHAR(100) NOT NULL,
    component_type VARCHAR(50) NOT NULL,
    component_ref_id BIGINT NOT NULL,
    position INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS template_module_placements_theme_slot_unique
    ON template_module_placements(theme_key, slot_key);

CREATE INDEX IF NOT EXISTS template_module_placements_theme_idx
    ON template_module_placements(theme_key);

INSERT INTO template_module_placements (theme_key, slot_key, component_type, component_ref_id, position)
SELECT 'cyberpunk', 'header_nav', 'menu', menus.id, 1
FROM menus
WHERE menus.key = 'main'
  AND NOT EXISTS (
      SELECT 1
      FROM template_module_placements placements
      WHERE placements.theme_key = 'cyberpunk'
        AND placements.slot_key = 'header_nav'
  );
