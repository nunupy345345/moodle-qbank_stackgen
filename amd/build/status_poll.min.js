// AMD module: live status polling for upload.php's job list.
// Mirrors Moodle core's async backup/restore progress pattern
// (see moodle/backup/util/ui/amd/src/async_backup.js): core/ajax + adaptive-delay
// setTimeout loop, re-rendering only the job cards whose state actually changed.
define(['core/ajax', 'core/templates', 'core/notification'], function(Ajax, Templates, Notification) {

    var BASE_DELAY = 10000; // cronループの周期（10秒）に合わせる
    var BACKOFF_MULTIPLIER = 1.5;

    var courseid;
    var delay = BASE_DELAY;

    /**
     * このジョブのレビュートグルボタンが「未確定でまだ編集できる」状態かどうか。
     * パネル要素の存在では判定しない：job_review.js は確定成功後もパネルを
     * display:noneのままDOMに残す（削除しない）ため、存在チェックだと確定後も
     * 永久にスキップされ続けて二度と更新されなくなるバグになる。
     * job_review.js が確定成功時に確実にtoggle.disabled = trueをセットする、
     * という1点のシグナルだけを見る。
     *
     * @param {number|string} jobid
     * @returns {boolean}
     */
    function isOpenForEditing(jobid) {
        var toggle = document.querySelector('.stackgen-review-toggle[data-jobid="' + jobid + '"]');
        return !!toggle && !toggle.disabled;
    }

    function schedule(ms) {
        setTimeout(poll, ms);
    }

    function renderJobCard(job, card) {
        Templates.renderForPromise('qbank_stackgen/job_card', job).then(function(result) {
            Templates.replaceNode(card, result.html, result.js);
            if (window.MathJax && window.MathJax.typesetPromise) {
                window.MathJax.typesetPromise();
            }
            return;
        }).catch(function(err) {
            Notification.exception(err);
        });
    }

    function handleResponse(jobs) {
        var stillPolling = false;

        jobs.forEach(function(job) {
            var card = document.querySelector('.qbank-stackgen-jobs [data-jobid="' + job.id + '"]');

            if (card && !isOpenForEditing(job.id) && job.status !== card.dataset.status) {
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

    function handleFailure() {
        delay = delay * BACKOFF_MULTIPLIER;
        schedule(delay);
    }

    function poll() {
        Ajax.call([{
            methodname: 'qbank_stackgen_get_job_statuses',
            args: {courseid: courseid},
        }])[0].done(handleResponse).fail(handleFailure);
    }

    /**
     * @param {number} cid Course id
     */
    function init(cid) {
        courseid = cid;

        if (!document.querySelector('.qbank-stackgen-jobs [data-polling="1"]')) {
            return;
        }

        schedule(delay);
    }

    return {init: init};
});
