<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']                    = 'STACK question generator (from image)';
$string['pluginname_desc']               = 'Automatically generates Moodle STACK questions from textbook images.';

$string['generate_from_image']           = 'Generate from image';

$string['upload_page_title']             = 'Generate question from image';
$string['upload_instructions']           = 'Upload an image of a textbook or worksheet. The image will be preprocessed in your browser and the question will be generated automatically in the background.';
$string['upload_label']                  = 'Select image file (JPEG / PNG / WebP, max 20MB)';
$string['upload_preview_alt']            = 'Preview of uploaded image';
$string['upload_submit']                 = 'Start generation';
$string['upload_success']                = 'Image received. Processing in the background.';
$string['upload_error_nofile']           = 'Please select an image file.';
$string['upload_error_invalid']          = 'Invalid file format. Please select JPEG, PNG, or WebP.';
$string['upload_processing']             = 'Processing in the background. Please wait.';

$string['settings_api_heading']          = 'API settings';
$string['settings_google_api_key']       = 'Google API key (Gemini)';
$string['settings_google_api_key_desc']  = 'Google Cloud API key used for Gemini 1.5 Flash OCR.';
$string['settings_llm_provider']         = 'LLM provider';
$string['settings_llm_provider_desc']    = 'Select the LLM to use for STACK XML generation.';
$string['settings_anthropic_api_key']    = 'Anthropic API key (Claude)';
$string['settings_anthropic_api_key_desc'] = 'Required when using Claude.';
$string['settings_openai_api_key']       = 'OpenAI API key';
$string['settings_openai_api_key_desc']  = 'Required when using OpenAI (GPT-4o).';
$string['settings_maxima_url']           = 'goemaxima URL';
$string['settings_maxima_url_desc']      = 'URL of the goemaxima service (within the Docker network).';
$string['settings_max_retry']            = 'Max CAS validation retries';
$string['settings_max_retry_desc']       = 'Maximum number of LLM re-generation attempts on CAS validation failure.';

$string['status_pending']                = 'Pending';
$string['status_ocr_done']               = 'OCR complete';
$string['status_feedback_pending']       = 'Awaiting feedback review';
$string['status_llm_done']               = 'XML generation complete';
$string['status_validated']              = 'CAS validation OK';
$string['status_review_pending']         = 'Awaiting review';
$string['status_approved']               = 'Approved';
$string['status_rejected']               = 'Rejected';
$string['status_validation_failed']      = 'CAS validation failed';
$string['status_requires_manual_review'] = 'Requires manual review';
$string['status_autoprocessing']         = 'Processing automatically...';

// Job list.
$string['job_list_heading']              = 'Recent processing history';
$string['xml_generated']                 = 'STACK XML generated';
$string['xml_show_raw']                  = 'Show XML';
$string['ocr_question_text']             = 'Question text';
$string['ocr_question_type']             = 'Question type';
$string['ocr_problem_expression']        = 'Expression (LaTeX)';
$string['ocr_answer_expression']         = 'Answer (LaTeX)';
$string['retry_button']                  = 'Retry';
$string['retry_count_label']             = 'Retry attempt {$a}';
$string['retry_success']                 = 'Queued for retry. It will be processed automatically shortly.';
$string['review_link']                   = 'Review wrong answers & feedback';

// Review page (Stage 3).
$string['review_page_title']             = 'Review wrong answers & feedback';
$string['review_instructions']           = 'Review and edit the AI-generated wrong-answer examples and feedback below. Once confirmed, they will be used to generate the PRT (scoring logic) in the STACK XML.';
$string['review_problem_name']           = 'Question name';
$string['review_wronganswers_heading']   = 'Wrong-answer examples & feedback';
$string['review_wa_expression']          = 'Wrong answer (Maxima expression)';
$string['review_wa_misconception']       = 'Likely cause';
$string['review_wa_feedback']            = 'Feedback text';
$string['review_wa_add']                 = '+ Add wrong-answer example';
$string['review_wa_remove']              = 'Remove';
$string['review_confirm_button']         = 'Confirm and generate XML';
$string['review_confirm_success']        = 'Confirmed. Generating STACK XML in the background.';
$string['review_error_wrongstatus']      = 'This job is not currently awaiting feedback review.';
$string['review_error_invalid']          = 'Invalid feedback data.';
$string['review_awaiting_xml']           = 'Generating STACK XML in the background. Please wait.';
$string['import_category_name']          = 'STACKGen Auto-generated';
$string['import_success']                = 'Registered in the question bank.';
$string['import_view_bank']              = 'View in question bank';

// Errors.
$string['error_no_capability']           = 'You do not have permission to perform this action.';
$string['error_invalid_courseid']        = 'Invalid course ID.';
$string['error_ocr_api_key_missing']     = 'Google API key is not set. Please configure it in the admin settings.';
$string['error_generic']                 = 'An error occurred.';
$string['error_llm_api_key_missing']     = 'Google API key is not set. Please configure it in the admin settings.';
