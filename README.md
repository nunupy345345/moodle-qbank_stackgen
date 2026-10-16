# qbank_stackgen

Moodle question bank plugin that semi-automatically generates [STACK](https://github.com/maths/moodle-qtype_stack)
questions (including PRT grading logic) from images of textbook pages.

教科書画像から STACK 問題（採点ロジック PRT を含む）を半自動生成する Moodle 問題バンクプラグインです。

**Status: alpha (0.1.0).** This is research software. Interfaces, DB schema and prompts may change.

## Pipeline

1. A teacher drops textbook images onto the upload page (browser-side preprocessing).
2. Gemini OCR: image → LaTeX JSON.
3. Gemini proposes typical wrong answers and feedback; the teacher reviews and edits them.
4. Gemini generates STACK XML (with chain-of-thought) using the confirmed wrong answers.
5. goemaxima validates the Maxima expressions (syntax, wrong answers vs. the model answer); failures are regenerated up to `max_retry` times.
6. Valid questions are imported directly into the question bank.

Optionally, similar hand-made questions are retrieved with embeddings (RAG) and added to the prompts.

## Requirements

- Moodle 4.5 (`MOODLE_405_STABLE`)
- [qtype_stack](https://github.com/maths/moodle-qtype_stack) installed
- A running [goemaxima](https://github.com/mathinstitut/goemaxima) server reachable from Moodle
- A Google Gemini API key
- Moodle cron running (generation runs as ad-hoc tasks)

> **Privacy:** uploaded images and generated text are sent to the Google Gemini API.
> Make sure this is acceptable for your institution and for the copyright status of the source material.

## Installation

```bash
cd /path/to/moodle
git clone https://github.com/<owner>/moodle-qbank_stackgen.git question/bank/stackgen
php admin/cli/upgrade.php --non-interactive
```

The directory name must be `stackgen`. Alternatively upload a release zip
(top-level folder `stackgen`) via *Site administration → Plugins → Install plugins*.

## Configuration

*Site administration → Plugins → Question bank plugins → STACK generator*

- `google_api_key`: Gemini API key
- `maxima_url`: goemaxima URL (e.g. `http://maxima:8080/goemaxima`)

The `llm_provider`, `anthropic_api_key` and `openai_api_key` settings exist for future use and are currently not read.

Users need the `moodle/question:add` capability.

## Optional data

- **RAG corpus**: `cli/import_corpus.php` imports STACK XML files (hand-made good questions) from a directory
  (`--sourcedir=`) into `qbank_stackgen_examples`. No corpus is shipped; without one, RAG simply finds nothing.
- **KC catalog**: the experimental KC track reads `fixtures_kc/kc_master.csv` inside the plugin directory.
  It is not shipped; without it the KC list is empty.

## Experimental features

The plugin also contains research-comparison tracks (generation without RAG, generation based on knowledge
components (KC)) and survey/dataset export CLIs under `cli/`. They are used for experiments and may be removed or
changed without notice.

## CLI

| Script | Purpose |
|---|---|
| `cli/bulk_import.php` | Register all images in a folder as jobs |
| `cli/import_corpus.php` | Import RAG corpus from STACK XML |
| `cli/export_dataset.php` | Export approved jobs (LLM draft vs. teacher-edited) |

Run with `php question/bank/stackgen/cli/<script>.php --help`.

## License

GPL v3 or later, the same as Moodle. See `LICENSE`.
