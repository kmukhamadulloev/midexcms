# Stack Stress Cyberpunk Theme for MidexCMS

Адаптированная CMS-версия персонального neon/cyberpunk шаблона.

## Структура

```text
cyberpunk/
├── theme.json
├── layout.html
├── home.html
├── page.html
├── post.html
├── archive.html
├── contact.html
├── partials/
│   ├── header.html
│   ├── footer.html
│   └── sidebar.html
└── assets/
    ├── style.css
    └── app.js
```

## Что важно

- Статичные страницы `index.html`, `articles.html`, `article.html` убраны.
- Используются плейсхолдеры CMS: `{{ content }}`, `{{ page.content }}`, `{{ menu.main }}`, `{{ likes }}`, `{{ comments }}`, `{{ archive.posts }}`.
- JS сохранён совместимым с лайками CMS через `[data-like-toggle]`.
- Внешних CDN, Font Awesome и отсутствующих картинок нет.
- Тема не зависит от `profile.jpg`, `article1.jpg`, SVG-иконок и других локальных ассетов.

## Установка

Скопируй папку `cyberpunk` в директорию тем CMS рядом с `default`.

Если CMS выбирает тему по ключу, используй:

```json
"key": "cyberpunk"
```
