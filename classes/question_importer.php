<?php
namespace qbank_stackgen;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/question/format/xml/format.php');
require_once($GLOBALS['CFG']->dirroot . '/question/engine/bank.php');

/**
 * Stage6: 確定済みSTACK XMLを、Moodle標準の qformat_xml パーサを使って
 * 問題バンクに直接登録する。
 *
 * qformat_default::importprocess() のSTAGE2（DB書き込み部分、format.php:412-554）を
 * バックグラウンドタスク向けに移植したもの。$OUTPUT->notification() による画面出力は行わず、
 * 失敗時は例外を投げて呼び出し元（generate_stack_question タスク）に処理を委ねる。
 */
class question_importer {

    // question_categories.idnumber に埋め込む、言語に依存しない識別マーカー。
    // get_string('import_category_name', ...) の結果（name列）は表示言語によって
    // 変わるため、カテゴリの重複作成を防ぐための検索キーには使えない
    // （info列はTEXT型でWHERE句の等価比較にそのまま使えないため、idnumberを使う）。
    // 従来版・RAGなし版は同じカテゴリを共有する（比較しやすいように）。KC診断版は
    // 前提知識起点の別系統の誤答例を使うため、教員が区別しやすいよう別カテゴリに分ける。
    const CATEGORY_MARKER = 'qbank_stackgen_auto_category';
    const CATEGORY_MARKER_KC = 'qbank_stackgen_auto_category_kc';

    /**
     * @param \stdClass $job qbank_stackgen_jobs の1レコード（xml_result/courseidが必須）
     * @param string $categorymarker 登録先カテゴリのidnumber（CATEGORY_MARKER系の定数）。
     *                                省略時は従来版・RAGなし版共通のカテゴリ。
     * @return array{questionids: int[], categoryid: int}
     */
    public static function import(\stdClass $job, string $categorymarker = self::CATEGORY_MARKER): array {
        global $DB, $USER;

        if (empty($job->xml_result)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null, 'No XML to import');
        }

        $course  = get_course($job->courseid);
        $context = \context_course::instance($job->courseid);
        $category = self::get_target_category($context, $categorymarker);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        $questions = $qformat->readquestions([$job->xml_result]);
        if (empty($questions)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'XML contained no importable questions');
        }

        $questionids = [];
        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($questions as $question) {
                if ($question->qtype === 'category') {
                    continue;
                }

                $question->context      = $context;
                $question->category     = $category->id;
                $question->stamp        = make_unique_id_code();
                $question->createdby    = $USER->id;
                $question->timecreated  = time();
                $question->modifiedby   = $USER->id;
                $question->timemodified = time();

                $fileoptions = [
                    'subdirs'  => true,
                    'maxfiles' => -1,
                    'maxbytes' => 0,
                ];

                $question->id = $DB->insert_record('question', $question);

                $bankentry = new \stdClass();
                $bankentry->questioncategoryid = $category->id;
                $bankentry->ownerid            = $question->createdby;
                $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);

                $version = new \stdClass();
                $version->questionbankentryid = $bankentry->id;
                $version->questionid          = $question->id;
                $version->version             = 1;
                $version->status              = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
                $DB->insert_record('question_versions', $version);

                if (isset($question->questiontextitemid)) {
                    $question->questiontext = file_save_draft_area_files($question->questiontextitemid,
                        $context->id, 'question', 'questiontext', $question->id, $fileoptions, $question->questiontext);
                    file_clear_draft_area($question->questiontextitemid);
                } else if (isset($question->questiontextfiles)) {
                    foreach ($question->questiontextfiles as $file) {
                        \question_bank::get_qtype($question->qtype)->import_file(
                            $context, 'question', 'questiontext', $question->id, $file);
                    }
                }
                if (isset($question->generalfeedbackitemid)) {
                    $question->generalfeedback = file_save_draft_area_files($question->generalfeedbackitemid,
                        $context->id, 'question', 'generalfeedback', $question->id, $fileoptions,
                        $question->generalfeedback);
                    file_clear_draft_area($question->generalfeedbackitemid);
                } else if (isset($question->generalfeedbackfiles)) {
                    foreach ($question->generalfeedbackfiles as $file) {
                        \question_bank::get_qtype($question->qtype)->import_file(
                            $context, 'question', 'generalfeedback', $question->id, $file);
                    }
                }
                $DB->update_record('question', $question);

                $result = \question_bank::get_qtype($question->qtype)->save_question_options($question);
                if (!empty($result->error)) {
                    throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null, $result->error);
                }

                \core\event\question_created::create_from_question_instance($question, $context)->trigger();

                $questionids[] = $question->id;
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return ['questionids' => $questionids, 'categoryid' => $category->id];
    }

    /**
     * コースの「Default for <コース名>」カテゴリの下に、STACKGen専用のサブカテゴリを
     * 取得または作成する（$categorymarker ごとに1つ）。
     */
    private static function get_target_category(\context $context, string $categorymarker): \stdClass {
        global $DB;

        $default = question_make_default_categories([$context]);

        $existing = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent'    => $default->id,
            'idnumber'  => $categorymarker,
        ]);
        if ($existing) {
            return $existing;
        }

        $namestring = $categorymarker === self::CATEGORY_MARKER_KC ? 'kc_import_category_name' : 'import_category_name';

        $category = new \stdClass();
        $category->name       = get_string($namestring, 'qbank_stackgen');
        $category->idnumber   = $categorymarker;
        $category->info       = '';
        $category->infoformat = FORMAT_PLAIN;
        $category->contextid  = $context->id;
        $category->parent     = $default->id;
        $category->sortorder  = 999;
        $category->stamp      = make_unique_id_code();
        $category->id = $DB->insert_record('question_categories', $category);

        return $category;
    }
}
