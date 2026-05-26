<script>
(function () {
    function setTip(tip, kind, text) {
        if (!tip) {
            return;
        }

        tip.className = 'bio-comment-tip is-' + kind;
        tip.textContent = text || '';
    }

    function initForms(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var forms = scope.querySelectorAll('[data-bio-comment-form]');
        if (!forms.length) {
            return;
        }

        forms.forEach(function (form) {
            if (form.getAttribute('data-bio-comment-bound') === '1') {
                return;
            }

            form.setAttribute('data-bio-comment-bound', '1');

            var tip = form.querySelector('[data-bio-comment-tip]');
            var submit = form.querySelector('button[type="submit"]');

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (submit) {
                    submit.disabled = true;
                }

                setTip(tip, 'pending', '正在提交...');

                var data = new FormData(form);
                data.set('ajax', '1');

                fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: data
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, message: '服务端返回格式错误' };
                    });
                }).then(function (json) {
                    if (json && json.ok) {
                        if (String(json.status || '') === 'approved') {
                            setTip(tip, 'success', json.message || '评论已提交并通过审核');
                        } else {
                            setTip(tip, 'waiting', json.message || '评论已提交，等待审核');
                        }

                        form.reset();
                        if (form.getAttribute('data-reload-on-success') !== '0') {
                            var delay = parseInt(form.getAttribute('data-reload-delay') || '500', 10);
                            window.setTimeout(function () {
                                window.location.reload();
                            }, isNaN(delay) ? 500 : delay);
                        }
                    } else {
                        setTip(tip, 'error', '提交失败：' + (json && json.message ? json.message : '未知错误'));
                    }
                }).catch(function (err) {
                    setTip(tip, 'error', '提交失败：' + (err && err.message ? err.message : '网络异常'));
                }).finally(function () {
                    if (submit) {
                        submit.disabled = false;
                    }
                });
            });
        });
    }

    window.TypechoBioInitCommentForms = initForms;
    initForms(document);
})();
</script>