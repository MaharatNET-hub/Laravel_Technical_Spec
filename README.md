# Submittal Review Assistant — demo

Reads a contractor's technical submittal (PDF from AutoCAD/Word), extracts each panel's values,
checks them against the project specification rules and drafts the consultant's full return file:
transmittal (Form F12.5A, parts A–D), comment sheet, the client's technical-control comments and the
submittal stamped and marked up — for the engineer to edit and approve.

- `extract.mjs` — reads every page (text + coordinates), classifies pages, extracts panel values, finds text/images to redact
- `review.mjs` — applies the rules in `rules.json`, groups findings into comments, builds the reviewed PDF
- `redact.mjs` — client disclosure: removes the hidden text and logos from the PDF content itself
- `server.mjs` + `public/` — the web app (upload, live progress, review workspace, client-disclosure switch)

## Client disclosure
The **Client disclosure** switch (top right) hides the project's identifying details everywhere:

| Where | What happens when "Details hidden" |
|---|---|
| Screen | Names/refs replaced with neutral labels on the server — the real names never reach the browser |
| Generated pages | Transmittal, comment sheet and client comments use the labels; footer reads "CLIENT DETAILS WITHHELD" |
| Submittal drawings | Matching text and logos are **removed from the PDF** (not only covered), then blacked out |
| File | Download name uses the masked submittal no.; PDF title/author metadata cleared |

What is hidden is configured in `rules.json → disclosure`: `aliases` (real → label, longest first) and
`redactPatterns` (regexes for text on the drawings).

## Run locally
```
npm install
npm start          # http://localhost:4817
```
Put the sample submittal at `submittal.pdf` to enable "Run review" on the home page (not in git — confidential).

Set `DEMO_PASSWORD` (and optionally `DEMO_USER`) to require a login. Deploy: see `DEPLOY.md`.
