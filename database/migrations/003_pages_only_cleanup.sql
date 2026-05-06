WITH former_posts AS (
    SELECT id, path
    FROM pages
    WHERE type = 'post'
      AND deleted_at IS NULL
      AND path LIKE '/blog/%'
)
UPDATE pages AS child
SET parent_id = parent.id,
    updated_at = CURRENT_TIMESTAMP
FROM former_posts
INNER JOIN pages AS parent
    ON parent.path = regexp_replace(former_posts.path, '/[^/]+$', '')
   AND parent.deleted_at IS NULL
WHERE child.id = former_posts.id;

UPDATE pages
SET type = 'page',
    updated_at = CURRENT_TIMESTAMP
WHERE type = 'post'
  AND deleted_at IS NULL;

UPDATE pages
SET content_raw = 'This section lists child pages published under Blog.' || E'\n\n[child_pages]',
    template = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE path = '/blog'
  AND deleted_at IS NULL;
