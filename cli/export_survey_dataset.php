<?php
/**
 * 3トラック（従来版/RAGなし版/KC診断版）がすべて承認済みのジョブを対象に、
 * アンケート用データセットを1本のJSONとしてエクスポートするCLIスクリプト。
 *
 * このプラグイン自体はアンケートWebページを持たない（別プロジェクトで構築する）。
 * ここでの役割は、同一の元問題（qbank_stackgen_jobs 1レコード = 1画像、ocr_result.problems
 * の配列インデックスで3トラック間が対応する）に対する3バリアントの生成結果を、
 * 外部のアンケートサイトが取り込める静的JSONとして書き出すところまで。
 *
 * --since で、指定時刻以降に作成されたジョブ（timecreated、= アップロード時刻）のみに絞り込める。
 * 出力には各アイテムの元ジョブの timecreated をそのまま含めるため、--since を指定しなくても
 * 後から「いつ生成されたものか」を自分でフィルタできる。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/export_survey_dataset.php
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/export_survey_dataset.php --courseid=2 --since=2026-10-01
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/export_survey_dataset.php --since="2026-10-01 09:00"
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

list($options, $unrecognized) = cli_get_params(
    ['courseid' => 0, 'since' => '', 'out' => '', 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    echo "3トラックとも承認済みのジョブをアンケート用データセット(JSON)としてエクスポートする。\n\n";
    echo "Usage: php export_survey_dataset.php [--courseid=N] [--since=DATE_OR_TIMESTAMP] [--out=PATH]\n";
    echo "  --courseid=N  このコースIDのジョブのみ対象にする（省略時は全コース）\n";
    echo "  --since=X     このジョブ作成時刻(timecreated)以降のみ対象にする\n";
    echo "                数字のみならUNIXタイムスタンプ、それ以外は strtotime() で解釈\n";
    echo "                （例: 2026-10-01 / \"2026-10-01 09:00\"）\n";
    echo "  --out=PATH    出力ファイルパス（省略時は cli/survey_dataset_export/survey_dataset_<実行時刻>.json）\n";
    exit(0);
}

$since = 0;
if ($options['since'] !== '') {
    $sincearg = (string)$options['since'];
    if (ctype_digit($sincearg)) {
        $since = (int)$sincearg;
    } else {
        $since = strtotime($sincearg);
        if ($since === false) {
            cli_error("--since の値を解釈できませんでした: {$sincearg}");
        }
    }
}

/**
 * <node> 内の単一タグのテキストを取り出す（存在しなければ空文字）。
 */
function qbank_stackgen_xml_tag_text(DOMElement $parent, string $tag): string {
    $els = $parent->getElementsByTagName($tag);
    return $els->length > 0 ? trim($els->item(0)->textContent) : '';
}

/**
 * <truefeedback>/<falsefeedback> のような <tag><text>...</text></tag> からフィードバック本文(HTML)を取り出す。
 */
function qbank_stackgen_xml_feedback_text(DOMElement $node, string $tag): string {
    $els = $node->getElementsByTagName($tag);
    if ($els->length === 0) {
        return '';
    }
    $texts = $els->item(0)->getElementsByTagName('text');
    return $texts->length > 0 ? trim($texts->item(0)->textContent) : '';
}

/**
 * 1トラック分の <quiz>...</quiz> XML（qbank_stackgen_jobs.xml_result 等、ジョブ全体の全問題分）から、
 * $index 番目の <question> の <prt> をノード構造のJSONに変換する。
 * CASを実際に実行するのではなく、生成済みXMLの静的な構造（answertest・スコア配分・フィードバック・
 * answer note）をそのまま取り出すだけ。
 *
 * @return array|null パース失敗・PRTが存在しない場合はnull
 */
function qbank_stackgen_parse_prt(?string $quizxml, int $index): ?array {
    if (empty($quizxml)) {
        return null;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($quizxml)) {
        libxml_clear_errors();
        return null;
    }
    libxml_clear_errors();

    $questions = $dom->getElementsByTagName('question');
    $question = $questions->item($index);
    if ($question === null) {
        return null;
    }

    $prts = $question->getElementsByTagName('prt');
    if ($prts->length === 0) {
        return null;
    }
    $prt = $prts->item(0);

    $nodes = [];
    foreach ($prt->getElementsByTagName('node') as $nodeel) {
        $nodes[] = [
            'name'        => qbank_stackgen_xml_tag_text($nodeel, 'name'),
            'description' => qbank_stackgen_xml_tag_text($nodeel, 'description'),
            'answertest'  => qbank_stackgen_xml_tag_text($nodeel, 'answertest'),
            'sans'        => qbank_stackgen_xml_tag_text($nodeel, 'sans'),
            'tans'        => qbank_stackgen_xml_tag_text($nodeel, 'tans'),
            'testoptions' => qbank_stackgen_xml_tag_text($nodeel, 'testoptions'),
            'true' => [
                'scoremode'  => qbank_stackgen_xml_tag_text($nodeel, 'truescoremode'),
                'score'      => qbank_stackgen_xml_tag_text($nodeel, 'truescore'),
                'nextnode'   => qbank_stackgen_xml_tag_text($nodeel, 'truenextnode'),
                'answernote' => qbank_stackgen_xml_tag_text($nodeel, 'trueanswernote'),
                'feedback'   => qbank_stackgen_xml_feedback_text($nodeel, 'truefeedback'),
            ],
            'false' => [
                'scoremode'  => qbank_stackgen_xml_tag_text($nodeel, 'falsescoremode'),
                'score'      => qbank_stackgen_xml_tag_text($nodeel, 'falsescore'),
                'nextnode'   => qbank_stackgen_xml_tag_text($nodeel, 'falsenextnode'),
                'answernote' => qbank_stackgen_xml_tag_text($nodeel, 'falseanswernote'),
                'feedback'   => qbank_stackgen_xml_feedback_text($nodeel, 'falsefeedback'),
            ],
        ];
    }

    return [
        'firstnodename'     => qbank_stackgen_xml_tag_text($prt, 'firstnodename'),
        'feedbackvariables' => qbank_stackgen_xml_feedback_text($prt, 'feedbackvariables'),
        'nodes'             => $nodes,
    ];
}

/**
 * $index 番目の <question> から、PRTが参照する変数の定義（ta, wa1, wa1_pred 等を定義する
 * questionvariables のMaxima コード）と解答欄の定義を取り出す。prt と同じXMLから取るので、
 * prt 内の sans/tans が参照する変数名と必ず整合する。
 *
 * @return array{questionvariables: string, inputs: array}
 */
function qbank_stackgen_parse_question_defs(?string $quizxml, int $index): array {
    $empty = ['questionvariables' => '', 'inputs' => []];
    if (empty($quizxml)) {
        return $empty;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($quizxml)) {
        libxml_clear_errors();
        return $empty;
    }
    libxml_clear_errors();

    $question = $dom->getElementsByTagName('question')->item($index);
    if ($question === null) {
        return $empty;
    }

    $inputs = [];
    foreach ($question->getElementsByTagName('input') as $inputel) {
        $inputs[] = [
            'name' => qbank_stackgen_xml_tag_text($inputel, 'name'),
            'type' => qbank_stackgen_xml_tag_text($inputel, 'type'),
            'tans' => qbank_stackgen_xml_tag_text($inputel, 'tans'),
        ];
    }

    return [
        'questionvariables' => qbank_stackgen_xml_feedback_text($question, 'questionvariables'),
        'inputs'            => $inputs,
    ];
}

/**
 * question_idの配列から、1トラック分の表示用データを作る。
 *
 * @return array|null $index番目の問題が存在しなければnull（トラック間で配列長が
 *                     食い違っている＝一部の問題だけ生成に失敗した、という異常系の防御）
 */
function qbank_stackgen_survey_track_item(
    array $questionids, int $index, context $context, ?string $trackxml
): ?array {
    if (!array_key_exists($index, $questionids)) {
        return null;
    }

    $qid = (int)$questionids[$index];

    try {
        $questiondata = question_bank::load_question_data($qid);
    } catch (\Throwable $e) {
        return null;
    }

    if (!$questiondata) {
        return null;
    }

    $previewurl = null;
    try {
        $previewurl = \qbank_previewquestion\helper::question_preview_url(
            $qid, null, null, null, null, $context)->out(false);
    } catch (\Throwable $e) {
        // previewにはログインセッションが必要なため、アンケートサイトからは直接開けない
        // （参考用のリンクとして同梱するのみ）。失敗しても他のデータには影響させない。
    }

    $defs = qbank_stackgen_parse_question_defs($trackxml, $index);

    return [
        'question_id'       => $qid,
        'name'              => $questiondata->name,
        'questiontext'      => $questiondata->questiontext,
        'preview_url'       => $previewurl,
        'questionvariables' => $defs['questionvariables'],
        'inputs'            => $defs['inputs'],
        'prt'               => qbank_stackgen_parse_prt($trackxml, $index),
    ];
}

function qbank_stackgen_export_survey_job(stdClass $job): array {
    $context = context_course::instance($job->courseid);

    $ocr      = json_decode($job->ocr_result ?: '{}', true) ?: [];
    $problems = $ocr['problems'] ?? [];

    $traditionalids = json_decode($job->question_ids ?: '[]', true) ?: [];
    $noragids       = json_decode($job->norag_question_ids ?: '[]', true) ?: [];
    $kcids          = json_decode($job->kc_question_ids ?: '[]', true) ?: [];

    $count = min(count($problems), count($traditionalids), count($noragids), count($kcids));

    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $traditional = qbank_stackgen_survey_track_item($traditionalids, $i, $context, $job->xml_result);
        $norag       = qbank_stackgen_survey_track_item($noragids, $i, $context, $job->norag_xml_result);
        $kc          = qbank_stackgen_survey_track_item($kcids, $i, $context, $job->kc_xml_result);

        if ($traditional === null || $norag === null || $kc === null) {
            echo "  [job {$job->id}] problem #{$i} はいずれかのトラックで問題が読み込めなかったためスキップします。\n";
            continue;
        }

        $items[] = [
            'job_id'               => (int)$job->id,
            'problem_index'        => $i,
            'courseid'             => (int)$job->courseid,
            'source_timecreated'   => (int)$job->timecreated,
            'source_question_text' => (string)($problems[$i]['question_text'] ?? ''),
            // OCR段階でリライト済みの「単独で意味が通る問題文」。3トラックで共通（トラックごとに
            // LLMが書き換えた tracks.*.questiontext と違い、トラックを推測する手がかりにならない）。
            'stack_question_text'  => (string)($problems[$i]['stack_question_text']
                ?? ($problems[$i]['question_text'] ?? '')),
            'tracks'                => [
                'traditional' => $traditional,
                'norag'       => $norag,
                'kc'          => $kc,
            ],
        ];
    }

    return $items;
}

$conditions = [
    'status'       => 'approved',
    'track_norag'  => 1,
    'norag_status' => 'approved',
    'track_kc'     => 1,
    'kc_status'    => 'approved',
];
if (!empty($options['courseid'])) {
    $conditions['courseid'] = (int)$options['courseid'];
}

$select = 'status = :status AND track_norag = :track_norag AND norag_status = :norag_status '
    . 'AND track_kc = :track_kc AND kc_status = :kc_status';
$params = [
    'status'       => $conditions['status'],
    'track_norag'  => $conditions['track_norag'],
    'norag_status' => $conditions['norag_status'],
    'track_kc'     => $conditions['track_kc'],
    'kc_status'    => $conditions['kc_status'],
];
if (isset($conditions['courseid'])) {
    $select .= ' AND courseid = :courseid';
    $params['courseid'] = $conditions['courseid'];
}
if ($since > 0) {
    $select .= ' AND timecreated >= :since';
    $params['since'] = $since;
}

$jobs = $DB->get_records_select('qbank_stackgen_jobs', $select, $params, 'id ASC');

echo count($jobs) . " 件の対象ジョブ（3トラックとも承認済み" . ($since > 0 ? "、timecreated >= {$since}" : "") . "）が見つかりました。\n";

$items = [];
foreach ($jobs as $job) {
    $items = array_merge($items, qbank_stackgen_export_survey_job($job));
}

$dataset = [
    'generated_at' => time(),
    'since'        => $since > 0 ? $since : null,
    'courseid'     => !empty($options['courseid']) ? (int)$options['courseid'] : null,
    'item_count'   => count($items),
    'items'        => $items,
];

if (!empty($options['out'])) {
    $outfile = (string)$options['out'];
} else {
    $outdir = __DIR__ . '/survey_dataset_export';
    if (!is_dir($outdir) && !mkdir($outdir, 0775, true) && !is_dir($outdir)) {
        cli_error("出力先ディレクトリの作成に失敗しました: {$outdir}");
    }
    $outfile = $outdir . '/survey_dataset_' . date('Ymd_His') . '.json';
}

file_put_contents($outfile, json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo count($items) . " 件のアイテムを書き出しました -> {$outfile}\n";
