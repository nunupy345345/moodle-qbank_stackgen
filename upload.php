<?php
/**
 * Image upload page for qbank_stackgen.
 *
 * Accepts a base64-encoded image (preprocessed by upload.js via Canvas API),
 * stores it in the DB, and queues an Adhoc Task.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_url(new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('upload_page_title', 'qbank_stackgen'));
$PAGE->set_heading($course->fullname);

// Handle POST submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    // retry_stage3のようにアンダースコア・数字を含む値があるため、英字のみのPARAM_ALPHAは使えない。
    $action = optional_param('action', 'upload', PARAM_ALPHANUMEXT);

    if ($action === 'retry') {
        $jobid = required_param('jobid', PARAM_INT);
        $job = $DB->get_record('qbank_stackgen_jobs',
            ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], '*', MUST_EXIST);

        // Stage6（問題バンク登録）だけが失敗した場合に限り、xml_resultは既にCAS検証済みとみなして
        // llm_done から（Stage6だけ再実行）。それ以外（Stage4のLLM生成エラーやStage5のCAS検証失敗）は
        // xml_resultが残っていても「検証済みで安全なXML」とは限らないため、feedback_confirmedがあれば
        // feedback_pending から（Stage4のXML生成・Stage5のCAS検証からやり直す）。
        // これを区別せず「xml_resultがあれば無条件にllm_doneへ」としていると、CAS検証に失敗したままの
        // XMLが再検証されずにそのまま問題バンクへ登録されてしまう事故につながる。
        $stage6only = !empty($job->xml_result) && !empty($job->error_msg)
            && strpos($job->error_msg, 'Stage6') === 0;

        if ($stage6only) {
            $resumestatus = 'llm_done';
        } else if (!empty($job->feedback_confirmed)) {
            $resumestatus = 'feedback_pending';
        } else if (!empty($job->ocr_result)) {
            $resumestatus = 'ocr_done';
        } else {
            $resumestatus = 'pending';
        }
        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'status'       => $resumestatus,
            'error_msg'    => null,
            'retry_count'  => $job->retry_count + 1,
            'timemodified' => time(),
        ]);

        $task = new \qbank_stackgen\task\generate_stack_question();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);

        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('retry_success', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'retry_stage3') {
        // Stage4以降で繰り返し失敗する場合、確定済みの誤答例（feedback_confirmed）自体に
        // 問題があることが多い（例: taと数式的に同値な誤答例）。feedback_confirmedを空に戻し、
        // 既存のfeedback_result（Stage3のAI提案）を使ってレビュー編集画面を再度開けるようにする。
        // Adhoc Taskはここではキューしない（review.php側で確定時にキューされる）。
        $jobid = required_param('jobid', PARAM_INT);
        $job = $DB->get_record('qbank_stackgen_jobs',
            ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], '*', MUST_EXIST);

        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'                 => $jobid,
            'status'             => 'feedback_pending',
            'feedback_confirmed' => null,
            'xml_result'         => null,
            'llm_cot'            => null,
            'error_msg'          => null,
            'retry_count'        => 0,
            'timemodified'       => time(),
        ]);

        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('retry_stage3_success', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $image_b64      = optional_param('image_b64', '', PARAM_RAW);
    // フォルダ一括アップロード（amd/src/upload.jsのfetch経由）から送られる元ファイル名。
    // 単発アップロードのフォームには存在しないため、送られなければNULLのまま(重複チェックはしない)。
    $sourcefilename = optional_param('sourcefilename', '', PARAM_FILE) ?: null;
    // 生成方式チェックボックス。未チェックのフィールドはPOSTされないため、両方optional_param。
    $track_traditional = optional_param('track_traditional', '', PARAM_ALPHANUM) !== '';
    $track_kc           = optional_param('track_kc', '', PARAM_ALPHANUM) !== '';

    if (empty($image_b64)) {
        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('upload_error_nofile', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if (!$track_traditional && !$track_kc) {
        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('upload_error_notrack', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Insert job record.
    $now   = time();
    $jobid = $DB->insert_record('qbank_stackgen_jobs', (object)[
        'courseid'          => $courseid,
        'userid'            => $USER->id,
        'status'            => 'pending',
        'image_b64'         => $image_b64,
        'sourcefilename'    => $sourcefilename,
        'track_traditional' => (int)$track_traditional,
        'track_kc'          => (int)$track_kc,
        'retry_count'       => 0,
        'timecreated'       => $now,
        'timemodified'      => $now,
    ]);

    // Queue Adhoc Task.
    $task = new \qbank_stackgen\task\generate_stack_question();
    $task->set_custom_data(['jobid' => $jobid]);
    \core\task\manager::queue_adhoc_task($task, true);

    redirect(
        new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
        get_string('upload_success', 'qbank_stackgen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Render upload form.
// ページ構成: フィルタなし(status='')のときだけ、1ページ目（page=0）は最新1件・2ページ目以降は
// 10件ずつという不均等な構成にする。ステータスで絞り込んだ場合は「最新1件だけ特別扱い」という
// 前提が意味をなさないため、1ページ目から10件ずつの通常ページングに切り替える。
$page    = optional_param('page', 0, PARAM_INT);
// ocr_done / feedback_pending / requires_manual_review のようにアンダースコアを含む値があるため、
// 英字のみのPARAM_ALPHAは使えない（$actionと同じ理由）。
$status  = optional_param('status', '', PARAM_ALPHANUMEXT);
$perpage = 10;

$PAGE->requires->js_call_amd('qbank_stackgen/upload', 'init', ['stackgen-upload-form']);
$PAGE->requires->js_call_amd('qbank_stackgen/job_review', 'init');
// ポーリングは「フィルタなしの1ページ目（＝最新1件）」だけが対象。絞り込み表示や過去ページの
// ジョブはもう状態が変わらない（レビュー待ちで教員操作待ちのものが大半）ので、
// ポーリングさせない（get_job_statuses も常に最新1件しか返さないため、他のページで回しても無意味）。
if ($page === 0 && $status === '') {
    $PAGE->requires->js_call_amd('qbank_stackgen/status_poll', 'init', [$courseid]);
}

echo $OUTPUT->header();

// 問題バンク共通ツールバー（問題/エクスポート/インポート/カテゴリ/画像から生成）。
// upload.php独自ページはこれまでこのツールバーを表示していなかったため、他のqbank系画面に
// 戻れなかった。question/bank/exportquestions/export.php等と同じ組み立て方で表示する。
$qbankrenderer = $PAGE->get_renderer('core_question', 'bank');
$qbankaction    = new \core_question\output\qbank_action_menu($PAGE->url);
echo $qbankrenderer->render($qbankaction);

// KC診断版（別ページ、kc_review.php）はMoodle側の制約で上のツールバーには載せられない
// （1プラグイン1項目までのため）。シンプルなリンクで行き来できるようにしておく。
echo \html_writer::div(
    \html_writer::link(
        new \moodle_url('/question/bank/stackgen/kc_review.php', ['courseid' => $courseid]),
        get_string('kc_page_short_title', 'qbank_stackgen')
    ),
    'mb-3'
);

// このユーザー・このコースのジョブが1件でもあれば、絞り込みが空振りでも一覧セクション自体
// （フィルタUI含む）は表示する。
$totalcountall = $DB->count_records_select(
    'qbank_stackgen_jobs',
    'courseid = ? AND userid = ?',
    [$courseid, $USER->id]
);

$where  = 'courseid = ? AND userid = ?';
$params = [$courseid, $USER->id];
if ($status !== '') {
    $where    .= ' AND status = ?';
    $params[]  = $status;
}

$totalcount = $DB->count_records_select('qbank_stackgen_jobs', $where, $params);

if ($status !== '') {
    $limitfrom = $page * $perpage;
    $limitnum  = $perpage;
} else if ($page <= 0) {
    $limitfrom = 0;
    $limitnum  = 1;
} else {
    $limitfrom = 1 + ($page - 1) * $perpage;
    $limitnum  = $perpage;
}

$dbjobs = $DB->get_records_select(
    'qbank_stackgen_jobs',
    $where,
    $params,
    'id DESC',
    'id, status, ocr_result, feedback_result, feedback_confirmed, xml_result, error_msg, ' .
        'question_category_id, retry_count, timecreated, rag_examples_used',
    $limitfrom, $limitnum
);

$sesskey = sesskey();
$jobs = [];
foreach ($dbjobs as $job) {
    $jobs[] = \qbank_stackgen\local\job_view::build($job, $courseid, $sesskey);
}

if ($status !== '') {
    $totalpages = max(1, (int)ceil($totalcount / $perpage));
} else {
    // 1ページ目=1件、2ページ目以降=10件という不均等な構成なので、独自の簡易ページャーを組み立てる
    // （$OUTPUT->paging_bar は全ページ同じperpage前提のため、この構成には使えない）。
    $remaining  = max(0, $totalcount - 1);
    $extrapages = (int)ceil($remaining / $perpage);
    $totalpages = 1 + $extrapages;
}

$pagingbar = '';
if ($totalpages > 1) {
    $links = [];
    for ($p = 0; $p < $totalpages; $p++) {
        $label = (string)($p + 1);
        $pageurlparams = ['page' => $p];
        if ($status !== '') {
            $pageurlparams['status'] = $status;
        }
        if ($p === $page) {
            $links[] = html_writer::span($label, 'font-weight-bold px-2');
        } else {
            $links[] = html_writer::link(
                new moodle_url($PAGE->url, $pageurlparams), $label, ['class' => 'px-2']
            );
        }
    }
    $pagingbar = html_writer::tag(
        'nav', implode(' ', $links), ['class' => 'stackgen-paging mt-2', 'aria-label' => 'job list paging']
    );
}

// ステータス絞り込みドロップダウン。値はJSがそのままlocation.hrefに使う完全なURL
// （courseid+status付き）にしておき、courseidをJS側に渡す必要をなくす。
$statusoptions   = [];
$statusoptions[] = [
    'value'    => (new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]))->out(false),
    'label'    => get_string('filter_status_all', 'qbank_stackgen'),
    'selected' => $status === '',
];
foreach (\qbank_stackgen\local\job_view::status_labels() as $key => $label) {
    $statusoptions[] = [
        'value'    => (new moodle_url('/question/bank/stackgen/upload.php',
            ['courseid' => $courseid, 'status' => $key]))->out(false),
        'label'    => $label,
        'selected' => $status === $key,
    ];
}

// Trigger MathJax re-typeset after DOM is ready.
if (!empty($jobs)) {
    $PAGE->requires->js_amd_inline('
require(["core/first"], function() {
    document.addEventListener("DOMContentLoaded", function() {
        if (window.MathJax && MathJax.typesetPromise) {
            MathJax.typesetPromise();
        }
    });
    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise();
    }
});
');
}

$templatecontext = [
    'actionurl'     => (new moodle_url('/question/bank/stackgen/upload.php'))->out(false),
    'sesskey'       => sesskey(),
    'courseid'      => $courseid,
    'formid'        => 'stackgen-upload-form',
    'jobs'          => $jobs,
    'hasjobs'       => !empty($jobs),
    'showjoblist'   => $totalcountall > 0,
    'statusoptions' => $statusoptions,
    'pagingbar'     => $pagingbar,
];

echo $OUTPUT->render_from_template('qbank_stackgen/upload_form', $templatecontext);

echo $OUTPUT->footer();
