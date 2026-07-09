// AMD module: inline Stage 3 review (wrong-answer/feedback editing) on upload.php's job list.
define(['core/notification'], function(Notification) {

    /**
     * Collects all wrong-answer rows for a given problem block into a plain object.
     *
     * @param {Element} problemEl  .stackgen-review-problem element
     * @returns {Object} {wronganswers: [{expression, misconception, feedback}, ...]}
     */
    function collectProblem(problemEl) {
        var rows = problemEl.querySelectorAll('.stackgen-wronganswer-row');
        var wronganswers = [];

        rows.forEach(function(row) {
            var expression = row.querySelector('.stackgen-wa-expression').value.trim();
            if (expression === '') {
                return;
            }
            wronganswers.push({
                expression: expression,
                misconception: row.querySelector('.stackgen-wa-misconception').value.trim(),
                feedback: row.querySelector('.stackgen-wa-feedback').value.trim()
            });
        });

        return {wronganswers: wronganswers};
    }

    function typeset(panel) {
        if (window.MathJax && window.MathJax.typesetPromise) {
            window.MathJax.typesetPromise([panel]).catch(function(err) {
                Notification.exception(err);
            });
        }
    }

    function handleConfirm(confirmBtn) {
        var jobid = confirmBtn.getAttribute('data-jobid');
        var panel = document.querySelector('.stackgen-review-panel[data-jobid="' + jobid + '"]');
        if (!panel) {
            return;
        }

        var sesskey   = panel.querySelector('.stackgen-review-sesskey').value;
        var courseid  = panel.querySelector('.stackgen-review-courseid').value;
        var actionurl = panel.querySelector('.stackgen-review-actionurl').value;
        var statusEl  = panel.querySelector('.stackgen-review-status');

        var problems = [];
        panel.querySelectorAll('.stackgen-review-problem').forEach(function(problemEl) {
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

            // 確定成功: パネルを閉じ、トグルボタンをスピナー付きのグレーアウト状態にして押せなくする。
            // この見た目はリロード後（DBのfeedback_confirmed列に基づく awaitingxml 状態）と同じにする。
            panel.style.display = 'none';

            var toggle = document.querySelector('.stackgen-review-toggle[data-jobid="' + jobid + '"]');
            if (toggle) {
                var label = toggle.getAttribute('data-awaiting-label') || '';
                toggle.disabled = true;
                toggle.classList.remove('btn-warning');
                toggle.classList.add('btn-secondary');
                toggle.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span>'
                    + toggle.textContent.trim();

                if (label !== '') {
                    var small = document.createElement('small');
                    small.className = 'text-muted ml-2';
                    small.textContent = label;
                    toggle.insertAdjacentElement('afterend', small);
                }
            }
        }).catch(function(err) {
            confirmBtn.disabled = false;
            Notification.alert('', err.message || String(err));
        });
    }

    function init() {
        document.addEventListener('click', function(e) {
            var toggle = e.target.closest('.stackgen-review-toggle');
            if (toggle) {
                if (toggle.disabled) {
                    return;
                }
                var jobid = toggle.getAttribute('data-jobid');
                var panel = document.querySelector('.stackgen-review-panel[data-jobid="' + jobid + '"]');
                if (!panel) {
                    return;
                }
                var wasHidden = panel.style.display === 'none' || !panel.style.display;
                panel.style.display = wasHidden ? 'block' : 'none';
                if (wasHidden) {
                    typeset(panel);
                }
                return;
            }

            var addBtn = e.target.closest('.stackgen-wa-add');
            if (addBtn) {
                var problemEl = addBtn.closest('.stackgen-review-problem');
                var problemIndex = addBtn.getAttribute('data-problem-index');
                var list = problemEl.querySelector('.stackgen-wronganswer-list[data-problem-index="' + problemIndex + '"]');
                var tpl = problemEl.querySelector('.stackgen-wronganswer-template');
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

            var removeBtn = e.target.closest('.stackgen-wa-remove');
            if (removeBtn) {
                var row = removeBtn.closest('.stackgen-wronganswer-row');
                if (row) {
                    row.parentNode.removeChild(row);
                }
                return;
            }

            var confirmBtn = e.target.closest('.stackgen-review-confirm');
            if (confirmBtn) {
                handleConfirm(confirmBtn);
            }
        });
    }

    return {init: init};
});
