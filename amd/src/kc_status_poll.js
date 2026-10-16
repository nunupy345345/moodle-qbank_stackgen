// AMD module: live status polling for kc_review.php's job list.
// Mirrors amd/src/status_poll.js (existing page), but fully independent: calls the KC-specific
// external function and re-renders via the KC-specific card template.
define(['core/ajax', 'core/templates', 'core/notification'], function(Ajax, Templates, Notification) {

    var BASE_DELAY = 10000; // cronループの周期（10秒）に合わせる
    var BACKOFF_MULTIPLIER = 1.5;

    var courseid;
    var delay = BASE_DELAY;

    /**
     * Schedule the next status poll.
     *
     * @param {number} ms Delay in milliseconds.
     */
    function schedule(ms) {
        setTimeout(poll, ms);
    }

    /**
     * Re-render a job card with the latest job data.
     *
     * @param {Object} job Job data returned by the web service.
     * @param {HTMLElement} card Existing card element to replace.
     */
    function renderJobCard(job, card) {
        Templates.renderForPromise('qbank_stackgen/kc_job_card', job).then(function(result) {
            Templates.replaceNode(card, result.html, result.js);
            if (window.MathJax && window.MathJax.typesetPromise) {
                window.MathJax.typesetPromise();
            }
            return;
        }).catch(function(err) {
            Notification.exception(err);
        });
    }

    /**
     * Update the cards of changed jobs and decide whether to keep polling.
     *
     * @param {Object[]} jobs Job data returned by the web service.
     */
    function handleResponse(jobs) {
        var stillPolling = false;

        jobs.forEach(function(job) {
            var card = document.querySelector('.qbank-stackgen-kc-jobs [data-jobid="' + job.id + '"]');

            // kc_statusが変わっていないジョブは再レンダリングしない。これにより、レビュー待ち
            // （教員が誤答例を編集中）のカードはkc_statusが変化するまで触れられない
            // （確定操作自体はkc_job_review.jsがその場でパネルを隠すので、ポーリングを待つ必要がない）。
            if (card && job.kc_status !== card.dataset.kcStatus) {
                renderJobCard(job, card);
            }

            if (job.polling) {
                stillPolling = true;
            }
        });

        delay = BASE_DELAY;
        if (stillPolling) {
            schedule(delay);
        }
    }

    /**
     * Back off the polling interval after a failed request.
     */
    function handleFailure() {
        delay = delay * BACKOFF_MULTIPLIER;
        schedule(delay);
    }

    /**
     * Fetch the latest job statuses of the course.
     */
    function poll() {
        Ajax.call([{
            methodname: 'qbank_stackgen_get_kc_job_statuses',
            args: {courseid: courseid},
        }])[0].done(handleResponse).fail(handleFailure);
    }

    /**
     * @param {number} cid Course id
     */
    function init(cid) {
        courseid = cid;

        if (!document.querySelector('.qbank-stackgen-kc-jobs [data-kc-polling="1"]')) {
            return;
        }

        schedule(delay);
    }

    return {init: init};
});
