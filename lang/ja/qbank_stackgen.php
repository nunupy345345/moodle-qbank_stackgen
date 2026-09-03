<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']                    = 'STACK問題生成（画像から）';
$string['pluginname_desc']               = '教科書画像からMoodle STACK問題を半自動生成するプラグイン。';

// Navigation.
$string['generate_from_image']           = '画像から生成';

// Upload page.
$string['upload_page_title']             = '教科書画像から問題を生成';
$string['upload_instructions']           = '教科書や問題用紙の画像をアップロードしてください。画像はブラウザ上で前処理され、バックグラウンドで問題が自動生成されます。';
$string['upload_label']                  = '画像ファイルを選択（JPEG / PNG / WebP、最大20MB）';
$string['upload_preview_alt']            = 'アップロード画像のプレビュー';
$string['upload_submit']                 = '生成開始';
$string['upload_success']                = '画像を受け付けました。バックグラウンドで処理中です。';
$string['upload_error_nofile']           = '画像ファイルを選択してください。';
$string['upload_error_invalid']          = '無効なファイル形式です。JPEG / PNG / WebP を選択してください。';
$string['upload_processing']             = 'バックグラウンドで処理中です。しばらくお待ちください。';

// Settings.
$string['settings_api_heading']          = 'API設定';
$string['settings_google_api_key']       = 'Google API キー（Gemini）';
$string['settings_google_api_key_desc']  = 'Gemini 1.5 Flash OCR に使用するGoogle Cloud APIキー。';
$string['settings_llm_provider']         = 'LLMプロバイダ';
$string['settings_llm_provider_desc']    = 'STACK XML生成に使用するLLMを選択してください。';
$string['settings_anthropic_api_key']    = 'Anthropic API キー（Claude）';
$string['settings_anthropic_api_key_desc'] = 'Claude を使用する場合に必要です。';
$string['settings_openai_api_key']       = 'OpenAI API キー';
$string['settings_openai_api_key_desc']  = 'OpenAI (GPT-4o) を使用する場合に必要です。';
$string['settings_maxima_url']           = 'goemaxima URL';
$string['settings_maxima_url_desc']      = 'goemaxima サービスのURL（Dockerネットワーク内）。';
$string['settings_max_retry']            = 'CAS検証 最大再試行回数';
$string['settings_max_retry_desc']       = 'CAS検証失敗時にLLM再生成を試みる最大回数。';
$string['settings_rag_heading']          = 'RAG設定（Step 6）';
$string['settings_rag_enabled']          = 'RAG検索を有効にする';
$string['settings_rag_enabled_desc']     = '教員手作りの良問コーパス（qbank_stackgen_examples）を検索し、Stage3/4のプロンプトに類似例を組み込む。無効にすると従来通り検索なしで生成する。';
$string['settings_rag_topk']             = 'RAG検索件数（top-k）';
$string['settings_rag_topk_desc']        = 'プロンプトに組み込む類似問題の件数。';

// Job statuses.
$string['status_pending']                = '処理待ち';
$string['status_ocr_done']               = 'OCR完了';
$string['status_feedback_pending']       = '誤答例レビュー待ち';
$string['status_llm_done']               = 'XML生成完了';
$string['status_validated']              = 'CAS検証OK';
$string['status_review_pending']         = 'レビュー待ち';
$string['status_approved']               = '承認済み';
$string['status_rejected']               = '却下';
$string['status_validation_failed']      = 'CAS検証失敗';
$string['status_requires_manual_review'] = '手動レビュー要';
$string['status_autoprocessing']         = '自動実行中...';

// Job list.
$string['job_list_heading']              = '最近の処理履歴';
$string['xml_generated']                 = 'STACK XML 生成済み';
$string['xml_show_raw']                  = 'XML を表示';
$string['ocr_question_text']             = '問題文';
$string['ocr_question_type']             = '問題タイプ';
$string['ocr_problem_expression']        = '数式（LaTeX）';
$string['ocr_answer_expression']         = '答え（LaTeX）';
$string['retry_button']                  = '再実行';
$string['retry_count_label']             = '再試行{$a}回目';
$string['retry_success']                 = '再実行キューに登録しました。しばらくすると自動的に処理されます。';
$string['review_link']                   = '誤答例・フィードバックをレビューする';

// Review page (Stage 3).
$string['review_page_title']             = '誤答例・フィードバックのレビュー';
$string['review_instructions']           = 'AIが生成した誤答例とフィードバック案を確認・修正してください。確定するとSTACK XMLのPRT（採点ロジック）に反映されます。';
$string['review_problem_name']           = '問題名';
$string['review_wronganswers_heading']   = '誤答例・フィードバック';
$string['review_wa_expression']          = '誤答の式（Maxima記法）';
$string['review_wa_misconception']       = '想定される誤りの原因';
$string['review_wa_feedback']            = 'フィードバック文';
$string['review_wa_add']                 = '＋ 誤答例を追加';
$string['review_wa_remove']              = '削除';
$string['review_confirm_button']         = '確定してXML生成へ進む';
$string['review_confirm_success']        = '確定しました。バックグラウンドでSTACK XMLを生成します。';
$string['review_error_wrongstatus']      = 'このジョブは現在レビュー待ちではありません。';
$string['review_error_invalid']          = '不正なフィードバックデータです。';
$string['review_awaiting_xml']           = 'バックグラウンドでSTACK XMLを生成中です。しばらくお待ちください。';
$string['import_category_name']          = 'STACKGen自動生成';
$string['import_success']                = '問題バンクに登録済みです。';
$string['import_view_bank']              = '問題バンクで確認';

// Errors.
$string['error_no_capability']           = 'この操作を実行する権限がありません。';
$string['error_invalid_courseid']        = '無効なコースIDです。';
$string['error_ocr_api_key_missing']     = 'Google API キーが設定されていません。管理画面で設定してください。';
$string['error_generic']                 = 'エラーが発生しました。';
$string['error_llm_api_key_missing']     = 'Google API キーが設定されていません。管理画面で設定してください。';
