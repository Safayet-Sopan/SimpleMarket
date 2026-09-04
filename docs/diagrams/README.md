# Diagrams

Mermaid source for the project diagrams. The `.mmd` files are the master copy —
regenerate the picture from them rather than editing an exported image.

| File | What it shows |
| ---- | ------------- |
| `er_diagram.mmd` | All 15 tables and the 25 foreign keys, taken from the live schema |
| `use_case_diagram.mmd` | The five actors and every use case, grouped by role |

## Rendering them

- **FigJam** — already generated; see the links in the project handoff.
- **Anything else** — paste the file contents into <https://mermaid.live>, then
  export PNG/SVG. draw.io imports Mermaid under *Arrange > Insert > Advanced >
  Mermaid*, and Miro has a Mermaid plugin that takes the same source.
- **GitHub** — a ```` ```mermaid ```` fence in a Markdown file renders inline, so
  these can be embedded directly in the README if you want them in the repo view.

## Keeping the ER diagram honest

It was built from `information_schema`, not by hand. If the schema changes, re-run
this and diff the result against `er_diagram.mmd`:

```
/Applications/XAMPP/xamppfiles/bin/mysql -u root -sN -e "
SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,' -> ',REFERENCED_TABLE_NAME,'.',REFERENCED_COLUMN_NAME)
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA='simplemarket_db' AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME;"
```
