# Younic-Workspace-Report

A small PHP app for creating, saving, and exporting **daily work plans** as
single-page PDFs. Plans live as JSON files on disk; PDFs are rendered on demand
by headless Chrome from a styled HTML template.

## What it does

- Create a work plan with a date, "Prepared by" / "Approved by" fields, and a
  list of tasks.
- Save the plan as JSON.
- Open a saved plan to flip each task's status between **Pending** and **Done**.
- Export the plan as a single-page PDF.
- Browse, edit, and delete existing reports from the home page.

## Running it

The app is a single PHP file with no dependencies beyond PHP and a headless
Chrome / Chromium binary on the host.

```bash
php -S 127.0.0.1:8000 generate_plan.php
# then open http://127.0.0.1:8000/generate_plan.php
```

### Requirements

- PHP 8.0+
- One of: `google-chrome`, `chromium`, or `chromium-browser` available on `PATH`
  (used for headless PDF rendering)

Plans are stored under `plans/<id>.json` and cached PDFs under
`plans/<id>_report.pdf`.

## File layout

```
.
├── generate_plan.php      # the whole app (router + form + PDF generator)
├── plans/                 # JSON plan files + cached PDF reports
├── LICENSE
├── README.md
├── llms.txt               # AI-crawler friendly project summary
└── robots.txt             # allows common AI crawlers
```

## PDF output

The PDF generator keeps the report on a **single A4 page** by picking a CSS
scale factor based on the number of tasks. The stylesheet lives inline in
`generate_plan.php`; every `pt` value is multiplied by `var(--scale, 1)`,
which is set on `<body>` to one of `1.00 / 0.85 / 0.72 / 0.62 / 0.52 / 0.45`.

## License

[MIT](LICENSE)
