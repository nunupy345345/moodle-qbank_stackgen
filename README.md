# qbank_stackgen

教科書画像から [STACK](https://github.com/maths/moodle-qtype_stack) 問題（採点ロジック PRT を含む）を半自動生成する、Moodle 問題バンク用プラグインです。

*Moodle question bank plugin that semi-automatically generates STACK questions (including PRT grading logic) from images of textbook pages. English summary is at the bottom.*

> **ステータス: alpha（0.1.0）。** 研究用ソフトウェアです。仕様・DB スキーマ・プロンプトは予告なく変わることがあります。

## 目次

- [できること](#できること)
- [必要なもの](#必要なもの)
- [インストール](#インストール)
- [初期設定](#初期設定)
- [使い方](#使い方)
- [任意のデータ（RAG・KC）](#任意のデータragkc)
- [実験的機能](#実験的機能)
- [CLI](#cli)
- [トラブルシューティング](#トラブルシューティング)
- [ライセンス](#ライセンス)
- [English summary](#english-summary)

## できること

1. 教員が教科書の画像（またはフォルダごと）をアップロードします（ブラウザ側で縮小・コントラスト調整）。
2. Gemini が画像を読み取り、LaTeX 付きの JSON にします（OCR）。
3. Gemini が想定される誤答例とフィードバック案を作ります。教員が画面で確認・修正して確定します。
4. 確定した誤答例を組み込み、Gemini が STACK の XML（PRT 付き）を生成します。
5. goemaxima（Maxima）で式の構文と、誤答例が正答と数式的に異なることを検証します。NG の場合は、エラー内容を伝えて `max_retry` 回まで自動で再生成します。
6. 検証に通った問題は、問題バンクへ直接登録されます。教員は Moodle 標準の問題バンク画面で確認・修正します。

教員手作りの良問を埋め込み検索（RAG）してプロンプトに加える機能もあります（任意）。

## 必要なもの

| 項目 | 内容 |
|---|---|
| Moodle | 4.5（`MOODLE_405_STABLE` で動作確認。他のバージョンは未検証） |
| qtype_stack | [maths/moodle-qtype_stack](https://github.com/maths/moodle-qtype_stack) を先にインストール（下記「STACK の依存関係」参照） |
| goemaxima | Moodle サーバーから HTTP で届く場所で起動していること |
| Gemini API キー | [Google AI Studio](https://aistudio.google.com/apikey) で発行（下記「Gemini API キーの取得」参照） |
| Cron | Moodle の cron が定期的に動いていること（生成処理は Adhoc Task で動く） |

> **プライバシーに関する注意:** アップロードした画像と生成テキストは、Google の Gemini API に送信されます。所属機関の方針と、教科書など元資料の著作権上の扱いを確認したうえで使ってください。

### STACK の依存関係

`qtype_stack` 自体が、次のプラグインを必要とします。いずれも STACK の公開リポジトリ・配布ページから入手できます。**STACK を入れる前に、これらを入れてください。**

- `qbehaviour_dfexplicitvaildate`
- `qbehaviour_dfcbmexplicitvaildate`
- `qbehaviour_adaptivemultipart`
- `qbank_importasversion`

STACK 自身の Maxima（CAS）設定も必要です。STACK のインストール手順と、`/question/type/stack/adminui/healthcheck.php` のヘルスチェックが通ることは、STACK 側のドキュメントで確認してください。このプラグインの `maxima_url`（後述）は、STACK の設定とは別に、検証用に goemaxima を呼び出すためのものです。同じ goemaxima を指しても構いません。

### Gemini API キーの取得

1. [Google AI Studio](https://aistudio.google.com/apikey) に、Google アカウントでログインします。
2. 「Create API key」でキーを発行し、コピーします。
3. このプラグインの設定画面の `google_api_key` に貼り付けます。

このキーは、OCR・誤答例/フィードバック案の生成・STACK XML の生成・類似問題検索（埋め込み）のすべてに使われます（モデルは Gemini 2.5 Flash と gemini-embedding-001）。利用料金と無料枠の条件は、Google の最新の案内を確認してください。**無料枠では、入力が Google のサービス改善に使われる場合があります。** 授業資料や、公開できない内容を扱うときは、有料プランなどの条件を確認してください。

### goemaxima の起動例（Docker）

```yaml
# docker-compose.yaml の一部
maxima:
  image: mathinstitut/goemaxima:2026042200-latest
  ports:
    - "8765:8080"
```

動作確認:

```bash
curl http://localhost:8765/goemaxima
# → Hostname: ..., version: 1.2.0 のように返れば OK
```

イメージのタグは、STACK 側の Maxima バージョンと合わせてください（`question/type/stack/stack/maxima/stackmaxima.mac` の末尾で確認できます）。

## インストール

プラグインのディレクトリ名は、必ず **`stackgen`** にしてください（`question/bank/stackgen`）。

### 方法 A: git clone（サーバーを操作できる管理者向け）

```bash
cd /path/to/moodle

# 1. 依存プラグイン（未インストールの場合）
git clone https://github.com/maths/moodle-qtype_stack.git question/type/stack

# 2. このプラグイン
git clone https://github.com/nunupy345345/moodle-qbank_stackgen.git question/bank/stackgen

# 3. DB 更新
php admin/cli/upgrade.php --non-interactive
```

ブラウザで `サイト管理 → 通知` を開いて、画面の案内に従ってインストールしても同じです。

### 方法 B: ZIP をアップロード（管理画面だけで行う場合）

1. [Releases](https://github.com/nunupy345345/moodle-qbank_stackgen/releases) から `stackgen.zip` をダウンロードします。
2. `サイト管理 → プラグイン → プラグインのインストール` を開き、ZIP をアップロードします。
3. 画面の指示に従ってインストールを完了します。

注意:

- GitHub の「Download ZIP」で得られる ZIP は、最上位フォルダ名が `moodle-qbank_stackgen-main` で、**そのままでは使えません**。Releases の `stackgen.zip`（最上位フォルダが `stackgen`）を使うか、方法 A を使ってください。
- この画面を使うには、Web サーバーのユーザーが `question/bank/` に書き込める必要があります。権限がない環境では、方法 A で配置してください。
- 先に `qtype_stack` と、その依存プラグイン（[STACK の依存関係](#stack-の依存関係)）をインストールしておく必要があります。

### インストールの確認

- `サイト管理 → プラグイン → プラグインの概要` に `STACK問題生成（画像から）` が表示されること。
- テーブル `mdl_qbank_stackgen_jobs` と `mdl_qbank_stackgen_examples` が作られていること。

## 初期設定

`サイト管理 → プラグイン → 問題バンクのプラグイン → STACK問題生成（画像から）`

| 設定 | 内容 | 既定値 |
|---|---|---|
| `google_api_key` | Gemini API キー（必須。[取得方法](#gemini-api-キーの取得)） | 空 |
| `maxima_url` | goemaxima の URL | `http://maxima:8080/goemaxima` |
| `max_retry` | CAS 検証に失敗したときの再生成回数 | 3 |
| `rag_enabled` | RAG を使うか | ON |
| `rag_topk` | RAG で参照する類似問題の件数 | 3 |

`maxima_url` は、**Moodle サーバーから見た** URL です。Docker で動かしているなら、コンテナ名（例: `http://maxima:8080/goemaxima`）、サーバー外にあるなら、そのホスト名と IP を指定します。

### 権限

このプラグインを使えるのは、コンテキスト内で `moodle/question:add` を持つユーザー（既定では、コースの教師と管理者）です。

## 使い方

1. コースの問題バンク画面を開き、ナビゲーションの「**画像から生成**」を選びます。
2. 教科書の画像をドラッグ＆ドロップします。フォルダを選んで、複数の画像をまとめて投入することもできます。
3. ジョブ一覧に、進行状況が表示されます。OCR とフィードバック案の生成は、Cron が処理します。Cron を待たずに試すときは、次を実行します。
   ```bash
   php admin/cli/cron.php
   ```
4. 状態が「誤答例の確認待ち」になったら、誤答例とフィードバック案を確認・修正して確定します（問題名・問題文も、ここで編集できます）。
5. 確定すると、XML の生成と CAS 検証が行われ、通れば問題バンクに登録されます。ジョブ一覧の「問題バンクへ」リンクから、Moodle 標準の編集画面で確認・修正します。
6. 検証に失敗し続けたジョブは「要手動確認」になります。

### ジョブの状態

```
pending → ocr_done → feedback_pending → (教員が確定) → approved（問題バンクへ登録済み）
                                                      └→ requires_manual_review（検証NG・例外）
```

## 任意のデータ（RAG・KC）

このリポジトリには、次のデータは含まれていません。無くても動作します。

- **RAG コーパス**: 手作りの良問の STACK XML を、埋め込みを計算して取り込みます。
  ```bash
  php question/bank/stackgen/cli/import_corpus.php --sourcedir=/path/to/xml_dir
  ```
  取り込み前は検索結果が空になり、通常のプロンプトのみで生成します。取り込みの動作は、`rag_enabled` と `rag_topk` で制御します。
- **KC カタログ**: 実験的な KC 機能が、プラグイン内の `fixtures_kc/kc_master.csv` を読みます。無い場合は、KC 一覧が空になります。

## 実験的機能

研究用の比較実験のための機能が含まれています。予告なく変更・削除されることがあります。

- RAG を使わない従来版との比較生成（NoRAG）
- 前提知識（KC: Knowledge Component）に基づく生成
- アンケート・データセット出力用の CLI（`cli/export_survey_dataset.php` など）

通常の利用では、これらを意識する必要はありません。

## CLI

実行は、Moodle のルートで `php question/bank/stackgen/cli/<スクリプト>.php --help` です。

| スクリプト | 用途 |
|---|---|
| `cli/bulk_import.php` | フォルダ内の画像を、まとめてジョブ登録 |
| `cli/import_corpus.php` | RAG コーパスの取り込み |
| `cli/export_dataset.php` | 承認済みジョブの出力（LLM の下書きと、教員編集後の比較） |

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| 「画像から生成」が表示されない | プラグインが有効か（`サイト管理 → プラグイン → 問題バンクのプラグイン`）、`moodle/question:add` を持っているか、キャッシュのパージ |
| ジョブが `pending` のまま進まない | Cron が動いているか。手動で `php admin/cli/cron.php` を実行して、出力のエラーを確認 |
| OCR / 生成でエラーになる | `google_api_key` が正しいか、Moodle サーバーから Google へ外向きの通信ができるか |
| CAS 検証が必ず失敗する | `maxima_url` が Moodle から届くか、goemaxima のバージョンが STACK と合っているか（STACK のヘルスチェックも参照） |
| ZIP でインストールできない | 最上位フォルダ名が `stackgen` か、`question/bank/` の書き込み権限があるか |

STACK のヘルスチェック: `/question/type/stack/adminui/healthcheck.php`

## ライセンス

GPL v3 以降（Moodle 本体と同じ）。`LICENSE` を参照してください。

---

## English summary

A Moodle question bank plugin that generates STACK questions (with PRT grading logic) from textbook images.

**Pipeline:** image → Gemini OCR (LaTeX JSON) → AI-proposed wrong answers/feedback reviewed by the teacher → Gemini generates STACK XML → goemaxima CAS validation (auto-regeneration on failure) → direct import into the question bank. Optional RAG over hand-made questions.

**Requirements:** Moodle 4.5, [qtype_stack](https://github.com/maths/moodle-qtype_stack) (which itself requires `qbehaviour_dfexplicitvaildate`, `qbehaviour_dfcbmexplicitvaildate`, `qbehaviour_adaptivemultipart` and `qbank_importasversion`), a reachable [goemaxima](https://github.com/mathinstitut/goemaxima) server, a Gemini API key, and Moodle cron.

**Install:**

```bash
git clone https://github.com/nunupy345345/moodle-qbank_stackgen.git question/bank/stackgen   # directory must be "stackgen"
php admin/cli/upgrade.php --non-interactive
```

Or upload the release `stackgen.zip` (top-level folder `stackgen`) via *Site administration → Plugins → Install plugins*.

**Configure:** *Site administration → Plugins → Question bank plugins → STACK問題生成（画像から）* — set `google_api_key` (create one in [Google AI Studio](https://aistudio.google.com/apikey); it is used for OCR, generation and embeddings) and `maxima_url`.

**Privacy:** uploaded images and generated text are sent to the Google Gemini API.

**Status:** alpha, research software. Includes experimental comparison tracks (no-RAG, KC) that may change or be removed.

License: GPL v3 or later.
