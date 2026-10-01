# Submittal Review Assistant — demo

Reads a contractor's technical submittal (PDF from AutoCAD/Word), extracts each panel's values,
checks them against the project specification rules and drafts the consultant's comment sheet
plus a marked-up PDF for the engineer to approve.

- `extract.mjs` — reads every page (text + coordinates), classifies pages, extracts panel values, finds text/images to redact
- `review.mjs` — applies the rules in `rules.json`, groups findings into comments, builds the reviewed PDF
- `server.mjs` + `public/` — the web app (upload, live progress, review workspace, client-disclosure switch)

## Run locally
```
npm install
npm start          # http://localhost:4817
```
Put the sample submittal at `submittal.pdf` to enable "Run review" on the home page (not in git — confidential).

Set `DEMO_PASSWORD` (and optionally `DEMO_USER`) to require a login. Deploy: see `DEPLOY.md`.
