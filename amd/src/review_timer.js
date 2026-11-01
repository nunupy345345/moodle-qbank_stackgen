// AMD module: 確認画面（Stage 3: 誤答例・フィードバックの確認/修正）で、教員が実際に操作していた時間を測る。
// 画面が前面で見えていて、直近30秒以内に操作（マウス・キー・スクロール等）があった間だけを数える。
// 確認画面が初めて見えた時刻は log_event.php に送り、操作秒数は確定時に review.php 等へ一緒に送る。
define([], function() {

    var IDLE_LIMIT_MS = 30000;
    var PANELS = [
        {track: 'traditional', selector: '.stackgen-review-panel'},
        {track: 'norag', selector: '.stackgen-norag-review-panel'},
        {track: 'kc', selector: '.stackgen-kc-review-panel'}
    ];

    var states = {};
    var lastActivity = 0;
    // 直近に操作された確認欄（"版:ジョブID"）。操作秒数はこの欄にだけ足す（複数の欄が同時に見えていても二重に数えない）。
    var activeKey = '';
    var started = false;

    /**
     * Whether the panel is currently on screen.
     *
     * @param {Element} panel
     * @returns {boolean}
     */
    function isVisible(panel) {
        if (!panel.offsetParent && getComputedStyle(panel).position !== 'fixed') {
            return false;
        }
        var rect = panel.getBoundingClientRect();
        return rect.bottom > 0 && rect.top < (window.innerHeight || document.documentElement.clientHeight);
    }

    /**
     * Send the "panel first seen" event.
     *
     * @param {string} track
     * @param {string} jobid
     */
    function sendOpen(track, jobid) {
        var body = new URLSearchParams();
        body.set('sesskey', M.cfg.sesskey);
        body.set('courseid', M.cfg.courseId);
        body.set('jobid', jobid);
        body.set('track', track);
        fetch(M.cfg.wwwroot + '/question/bank/stackgen/log_event.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function() {
            // 計測の失敗で画面操作を妨げない。
        });
    }

    /**
     * Run once per second: count active seconds for every visible panel.
     */
    function tick() {
        var active = document.visibilityState === 'visible' && document.hasFocus()
            && (Date.now() - lastActivity) < IDLE_LIMIT_MS;
        PANELS.forEach(function(def) {
            document.querySelectorAll(def.selector + '[data-jobid]').forEach(function(panel) {
                if (!isVisible(panel)) {
                    return;
                }
                var key = def.track + ':' + panel.getAttribute('data-jobid');
                var st = states[key];
                if (!st) {
                    st = states[key] = {opened: false, seconds: 0};
                }
                if (!st.opened && document.visibilityState === 'visible') {
                    st.opened = true;
                    sendOpen(def.track, panel.getAttribute('data-jobid'));
                }
                if (active && key === activeKey) {
                    st.seconds += 1;
                }
            });
        });
    }

    return {
        /**
         * Start tracking (safe to call more than once).
         */
        init: function() {
            if (started) {
                return;
            }
            started = true;
            ['mousemove', 'mousedown', 'keydown', 'scroll', 'input', 'touchstart'].forEach(function(name) {
                document.addEventListener(name, function(e) {
                    lastActivity = Date.now();
                    var target = e.target && e.target.closest ? e.target : null;
                    if (!target) {
                        return;
                    }
                    PANELS.forEach(function(def) {
                        var panel = target.closest(def.selector + '[data-jobid]');
                        if (panel) {
                            activeKey = def.track + ':' + panel.getAttribute('data-jobid');
                        }
                    });
                }, {capture: true, passive: true});
            });
            window.setInterval(tick, 1000);
        },

        /**
         * Active seconds counted so far for a panel.
         *
         * @param {string} track traditional / norag / kc
         * @param {string} jobid
         * @returns {number}
         */
        seconds: function(track, jobid) {
            var st = states[track + ':' + jobid];
            return st ? st.seconds : 0;
        }
    };
});
