INSERT INTO settings (key, value, group_name)
VALUES
    ('site.title', '{"value":"Midex CMS Site"}', 'site'),
    ('site.description', '{"value":"Simple website powered by Midex CMS"}', 'site'),
    ('theme.active', '{"value":"default"}', 'theme'),
    ('comments.enabled', '{"value":true}', 'comments'),
    ('comments.moderation_required', '{"value":true}', 'comments')
ON CONFLICT (key) DO NOTHING;

INSERT INTO menus (key, name)
VALUES ('main', 'Main Menu')
ON CONFLICT (key) DO NOTHING;

INSERT INTO forms (key, name, description, success_message, send_email, save_submissions, captcha_enabled, is_active)
VALUES ('contact', 'Contact Form', 'Default contact form created during installation.', 'Thanks, your message has been received.', TRUE, TRUE, FALSE, TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO form_fields (form_id, type, name, label, placeholder, options, is_required, sort_order)
SELECT f.id, v.type, v.name, v.label, v.placeholder, NULL::jsonb, v.is_required, v.sort_order
FROM forms f
CROSS JOIN (
    VALUES
        ('text', 'name', 'Name', 'Your name', TRUE, 1),
        ('email', 'email', 'Email', 'you@example.com', TRUE, 2),
        ('textarea', 'message', 'Message', 'How can we help?', TRUE, 3)
) AS v(type, name, label, placeholder, is_required, sort_order)
WHERE f.key = 'contact'
AND NOT EXISTS (
    SELECT 1 FROM form_fields ff WHERE ff.form_id = f.id AND ff.name = v.name
);
