// AMD module: image preprocessing (Stage 1) and upload form wiring.
define(['core/notification'], function(Notification) {

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

            if (!fileInput || !b64Input) {
                return;
            }

            fileInput.addEventListener('change', function(e) {
                var file = e.target.files[0];
                if (!file) {
                    return;
                }

                // Validate MIME type client-side.
                var allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
                if (allowed.indexOf(file.type) === -1 && file.type !== '') {
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
                        submitBtn.disabled = false;
                    }
                }).catch(function(err) {
                    Notification.exception(err);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                });
            });
        }
    };
});
