// AMD module: KC diagnosis (separate page, kc_review.php) Stage 3 review (wrong-answer/feedback editing).
// Fully independent from amd/src/job_review.js (existing page).
define(['core/notification'], function(Notification) {

    /**
     * Collects all wrong-answer rows for a given problem block into a plain object.
     *
     * @param {Element} problemEl  .stackgen-kc-review-problem element
     * @returns {Object} {wronganswers: [{expression, kc_id, feedback}, ...]}
     */
    function collectProblem(problemEl) {
        var rows = problemEl.querySelectorAll('.stackgen-kc-wa-row');
        var wronganswers = [];

        rows.forEach(function(row) {
            var expression = row.querySelector('.stackgen-kc-wa-expression').value.trim();
            if (expression === '') {
                return;
            }
            wronganswers.push({
                expression: expression,
                kc_id: row.querySelector('.stackgen-kc-wa-kc').value.trim(),
                feedback: row.querySelector('.stackgen-kc-wa-feedback').value.trim()
            });
        });

        return {wronganswers: wronganswers};
    }

    function handleConfirm(confirmBtn) {
        var jobid = confirmBtn.getAttribute('data-jobid');
        var panel = document.querySelector('.stackgen-kc-review-panel[data-jobid="' + jobid + '"]');
        if (!panel) {
            return;
        }

        var sesskey   = panel.getAttribute('data-sesskey');
        var courseid  = panel.getAttribute('data-courseid');
        var actionurl = panel.getAttribute('data-confirmurl');
        var statusEl  = panel.querySelector('.stackgen-kc-review-status');

        var problems = [];
        panel.querySelectorAll('.stackgen-kc-review-problem').forEach(function(problemEl) {
            problems.push(collectProblem(problemEl));
        });

        var body = new URLSearchParams();
        body.set('sesskey', sesskey);
        body.set('courseid', courseid);
        body.set('jobid', jobid);
        body.set('feedbackdata', JSON.stringify({problems: problems}));

        confirmBtn.disabled = true;
        if (statusEl) {
            statusEl.textContent = '';
        }

        fetch(actionurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json().then(function(data) {
                return {ok: response.ok, data: data};
            });
        }).then(function(result) {
            if (!result.ok || !result.data || !result.data.success) {
                throw new Error((result.data && result.data.message) || 'error');
            }

            // 確定成功: パネルを隠し、ポーリング（kc_status_poll.js）が次のkc_status変化を
            // 検知するまでの間、その場で簡単な「処理中」表示を出す（次のポーリングで
            // 実際のカードに置き換わる。amd/src/status_poll.js・job_review.jsの既存パターンと同様）。
            panel.style.display = 'none';

            var spinner = document.createElement('div');
            spinner.className = 'text-muted small stackgen-kc-awaiting-placeholder';
            spinner.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" '
                + 'role="status" aria-hidden="true"></span>'
                + panel.getAttribute('data-awaiting-label');
            panel.insertAdjacentElement('afterend', spinner);
        }).catch(function(err) {
            confirmBtn.disabled = false;
            Notification.alert('', err.message || String(err));
        });
    }

    function init() {
        document.addEventListener('click', function(e) {
            var addBtn = e.target.closest('.stackgen-kc-wa-add');
            if (addBtn) {
                var problemEl = addBtn.closest('.stackgen-kc-review-problem');
                var problemIndex = addBtn.getAttribute('data-problem-index');
                var list = problemEl.querySelector('.stackgen-kc-wa-list[data-problem-index="' + problemIndex + '"]');
                var tpl = problemEl.querySelector('.stackgen-kc-wa-template');
                if (!list || !tpl) {
                    return;
                }
                var nextIndex = list.children.length;
                var html = tpl.innerHTML.replace(/__INDEX__/g, nextIndex);
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                list.appendChild(wrapper.firstChild);
                return;
            }

            var removeBtn = e.target.closest('.stackgen-kc-wa-remove');
            if (removeBtn) {
                var row = removeBtn.closest('.stackgen-kc-wa-row');
                if (row) {
                    row.parentNode.removeChild(row);
                }
                return;
            }

            var confirmBtn = e.target.closest('.stackgen-kc-review-confirm');
            if (confirmBtn) {
                handleConfirm(confirmBtn);
            }
        });
    }

    return {init: init};
});
