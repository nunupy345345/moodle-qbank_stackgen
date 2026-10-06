// AMD module: image preprocessing (Stage 1) and upload form wiring.
define(['core/notification'], function(Notification) {

    var ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
    var ALLOWED_EXTENSIONS_RE = /\.(jpe?g|png|webp|heic)$/i;

    /**
     * Some browser/OS combinations don't set a MIME type for certain files (notably HEIC),
     * so fall back to checking the file extension in that case.
     *
     * @param {File} file
     * @returns {boolean}
     */
    function isAllowedFile(file) {
        if (file.type !== '') {
            return ALLOWED_MIME_TYPES.indexOf(file.type) !== -1;
        }
        return ALLOWED_EXTENSIONS_RE.test(file.name);
    }

    /**
     * Resize and contrast-enhance the image using Canvas API.
     * Returns a JPEG DataURL (max 2000px long side, quality 85%).
     *
     * @param {File} file
     * @returns {Promise<string>} DataURL
     */
    function preprocessImage(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();

            reader.onerror = function() {
                reject(new Error('FileReader error'));
            };

            reader.onload = function(e) {
                var img = new Image();

                img.onerror = function() {
                    reject(new Error('Image load error'));
                };

                img.onload = function() {
                    var MAX = 2000;
                    var scale = Math.min(1, MAX / Math.max(img.width, img.height));

                    var canvas = document.createElement('canvas');
                    canvas.width  = Math.round(img.width  * scale);
                    canvas.height = Math.round(img.height * scale);

                    var ctx = canvas.getContext('2d');
                    ctx.filter = 'contrast(1.2) brightness(1.05)';
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                    resolve(canvas.toDataURL('image/jpeg', 0.85));
                };

                img.src = e.target.result;
            };

            reader.readAsDataURL(file);
        });
    }

    return {
        /**
         * @param {string} uploadFormId  id attribute of the <form> element
         */
        init: function(uploadFormId) {
            var form      = document.getElementById(uploadFormId);
            if (!form) {
                return;
            }

            var fileInput = form.querySelector('input[type="file"]');
            var preview   = form.querySelector('.stackgen-preview');
            var b64Input  = form.querySelector('input[name="image_b64"]');
            var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');

            var trackTraditional = document.getElementById('stackgen-track-traditional');
            var trackKc          = document.getElementById('stackgen-track-kc');

            function trackSelected() {
                return (trackTraditional && trackTraditional.checked) || (trackKc && trackKc.checked);
            }

            // folderSubmit/selectedFiles are declared later in this same function (var hoisting);
            // this only runs from a 'change' event fired after init() has finished setting them up.
            function onTrackChange() {
                if (submitBtn && b64Input.value !== '') {
                    submitBtn.disabled = !trackSelected();
                }
                if (folderSubmit && selectedFiles.length > 0) {
                    folderSubmit.disabled = !trackSelected();
                }
            }

            if (trackTraditional) {
                trackTraditional.addEventListener('change', onTrackChange);
            }
            if (trackKc) {
                trackKc.addEventListener('change', onTrackChange);
            }

            if (fileInput && b64Input) {
                fileInput.addEventListener('change', function(e) {
                    var file = e.target.files[0];
                    if (!file) {
                        return;
                    }

                    if (!isAllowedFile(file)) {
                        Notification.alert('', 'JPEG / PNG / WebP / HEIC 形式の画像を選択してください。');
                        fileInput.value = '';
                        return;
                    }

                    if (submitBtn) {
                        submitBtn.disabled = true;
                    }

                    preprocessImage(file).then(function(dataUrl) {
                        if (preview) {
                            preview.src = dataUrl;
                            preview.style.display = 'block';
                        }
                        // Strip DataURL prefix (e.g. "data:image/jpeg;base64,") before storing.
                        var b64 = dataUrl.split(',')[1] || dataUrl;
                        b64Input.value = b64;

                        if (submitBtn) {
                            submitBtn.disabled = !trackSelected();
                        }
                    }).catch(function(err) {
                        Notification.exception(err);
                        if (submitBtn) {
                            submitBtn.disabled = !trackSelected();
                        }
                    });
                });
            }

            // Status filter dropdown (only present once this user has at least one job).
            var statusFilter = document.getElementById('stackgen-status-filter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    window.location.href = statusFilter.value;
                });
            }

            // Folder bulk upload: reuses the same upload.php POST endpoint as the single-image
            // form above, submitting one file at a time via fetch() so the page doesn't navigate
            // away between files. Server-side processing order is still left entirely to the
            // existing Adhoc Task queue / cron.
            var folderInput    = document.getElementById('stackgen-folder-input');
            var folderSubmit   = document.getElementById('stackgen-folder-submit');
            var folderProgress = document.getElementById('stackgen-folder-progress');
            if (!folderInput || !folderSubmit || !folderProgress) {
                return;
            }

            var actionUrl    = form.getAttribute('action');
            var sesskeyInput = form.querySelector('input[name="sesskey"]');
            var courseidInput = form.querySelector('input[name="courseid"]');
            var selectedFiles = [];

            folderInput.addEventListener('change', function(e) {
                selectedFiles = Array.prototype.filter.call(e.target.files, isAllowedFile);
                selectedFiles.sort(function(a, b) {
                    if (a.name === b.name) {
                        return 0;
                    }
                    return a.name < b.name ? -1 : 1;
                });
                folderSubmit.disabled = selectedFiles.length === 0 || !trackSelected();
                folderProgress.style.display = 'none';
            });

            folderSubmit.addEventListener('click', function() {
                if (selectedFiles.length === 0) {
                    return;
                }

                folderSubmit.disabled = true;
                folderInput.disabled  = true;
                folderProgress.style.display = 'block';

                var total       = selectedFiles.length;
                var done        = 0;
                var failedNames = [];

                var chain = Promise.resolve();
                selectedFiles.forEach(function(file) {
                    chain = chain.then(function() {
                        return preprocessImage(file)
                            .then(function(dataUrl) {
                                var b64  = dataUrl.split(',')[1] || dataUrl;
                                var body = new FormData();
                                body.append('sesskey', sesskeyInput.value);
                                body.append('courseid', courseidInput.value);
                                body.append('image_b64', b64);
                                body.append('sourcefilename', file.name);
                                if (trackTraditional && trackTraditional.checked) {
                                    body.append('track_traditional', '1');
                                }
                                if (trackKc && trackKc.checked) {
                                    body.append('track_kc', '1');
                                }
                                return fetch(actionUrl, {method: 'POST', body: body, credentials: 'same-origin'});
                            })
                            .catch(function() {
                                failedNames.push(file.name);
                            })
                            .then(function() {
                                done++;
                                folderProgress.textContent = done + ' / ' + total + ' 件処理中...';
                            });
                    });
                });

                chain.then(function() {
                    var succeeded = done - failedNames.length;
                    var msg = total + '件中' + succeeded + '件をキュー登録しました。';
                    if (failedNames.length > 0) {
                        msg += '（失敗: ' + failedNames.join(', ') + '）';
                    }
                    folderProgress.textContent = msg + ' ページを更新します...';
                    window.location.reload();
                });
            });
        }
    };
});
