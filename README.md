# Submittal Review Assistant — Laravel

Reads a contractor's technical submittal (PDF from AutoCAD/Word), extracts each panel's values,
checks them against the project specification rules and drafts the consultant's full return file:
transmittal (Form F12.5A, parts A–D), comment sheet, the client's technical-control comments and the
submittal stamped and marked up — for the engineer to edit and approve.

Pure PHP: no Node, no Docker, no database, no external programs. Runs on shared / free hosting.
Upload guide (Arabic): [`DEPLOY.md`](DEPLOY.md) — shared/free PHP hosting via `release/*.zip`, or Render via the `Dockerfile` / `render.yaml` (Web Service, runtime Docker).

## Versions
- **v1** — `/`: the original demo (single review, no login). Unchanged by v2.
- **v2** — `/v2`: company website (AR/EN: home, about, services, projects, contact), public submittal
  form with tracking codes, and an admin dashboard at `/v2/admin` (submissions, files, the same analysis
  tool per submission, issue + email the PDF, contact inbox, content editing, CSV export). Uses a
  database (SQLite by default, created on the first request; MySQL/Postgres via `DB_URL`).

## Layout
| Path | What |
|---|---|
| `app/Pdf/` | PDF engine: `Reader` (xref, object streams, filters, damaged-file repair), `Font` (ToUnicode, encodings, CID, widths), `ContentWalker` (text positions like pdf.js, image boxes, true redaction), `Writer` (full rewrite — removed content does not survive), `Canvas` (drawing with the standard fonts) |
| `app/Review/Extractor.php` | Step 1 — classify pages, extract panel values, find text/logos to redact (time-boxed batches) |
| `app/Review/Reviewer.php` | Step 2 — apply rules, draft comments, build the issued PDF |
| `app/Http/Controllers/DemoController.php` | JSON API used by the page (chunked upload, batched reading) |
| `resources/demo/rules.json` | Project, rules and client-disclosure config (copied to `storage/app/demo/` on first run; edits from the UI go there) |
| `public/app.js`, `public/style.css`, `public/lib/pdfjs/` | The single-page UI and the PDF viewer |
| `routes/v2.php`, `app/Http/Controllers/V2/`, `app/V2/`, `app/Models/V2/` | v2 site, submission flow and admin; `App\V2\Submissions` runs the engine inside `storage/app/v2/submissions/{id}` |
| `resources/views/v2/`, `lang/{ar,en}/v2.php`, `public/lib/v2/` | v2 views, translations and assets (`workspace.js` is generated from `public/app.js`) |
| `database/seeders/` | `DemoSeeder` (v1 sample review), `V2Seeder` (admin account + site content) |

## Client disclosure
The **Client disclosure** switch (top right) hides the project's identifying details everywhere:

| Where | What happens when "Details hidden" |
|---|---|
| Screen | Names/refs replaced with neutral labels on the server — the real names never reach the browser |
| Generated pages | Transmittal, comment sheet and client comments use the labels; footer reads "CLIENT DETAILS WITHHELD" |
| Submittal drawings | Matching text and logos are **removed from the PDF content** (not only covered), then blacked out |
| File | Masked file name; metadata, bookmarks, annotations and form fields dropped; the file is fully rewritten |

What is hidden: `disclosure.aliases` (real → label, longest first) and `disclosure.redactPatterns`
(regexes for text on the drawings) in `rules.json`. Set `DEMO_LOCK_DISCLOSURE=true` in `.env` to keep
details hidden permanently (the switch is removed) — recommended for a public link.

## Run locally
```
composer install
php artisan serve                       # http://localhost:8000
php artisan demo:review file.pdf --hide=1   # same pipeline from the command line
php artisan test
php artisan v2:install                  # create/migrate/seed the v2 database (also done on the first /v2 request)
```
Put the sample submittal at `storage/app/demo/sample/submittal.pdf` to enable "Run review" on the home
page (never committed — confidential). `.env` and its key are created automatically on the first request.

## Limits
- Encrypted (password-protected) PDFs are refused with a message.
- The extraction heuristics were written for the reference submittal's layout (Pioneer / EATON data
  sheets). Check the results — and that a search of the issued PDF finds none of the hidden names — on
  the first run of every new submittal format.
