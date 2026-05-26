<?php

class TypechoBio_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $this->on($this->request->is('do=profile'))->profile();
        $this->on($this->request->is('do=ping'))->ping();
        $this->on($this->request->is('do=comment'))->comment();
        $this->on($this->request->is('do=themes-list'))->themesList();
        $this->on($this->request->is('do=themes-install'))->themesInstall();
        $this->on($this->request->is('do=themes-update'))->themesUpdate();

        $this->response->setStatus(404);
        $this->response->setContentType('application/json');
        echo json_encode(array(
            'ok' => false,
            'message' => 'not found',
        ));
    }

    public function profile()
    {
        $user = TypechoBio_Plugin::getCurrentUser();

        $this->response->setContentType('application/json');
        echo json_encode(array(
            'ok' => true,
            'logged' => $user->hasLogin(),
            'uid' => $user->hasLogin() ? (int) $user->uid : 0,
            'name' => $user->hasLogin() ? (string) $user->name : '',
            'group' => $user->hasLogin() ? (string) $user->group : '',
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function ping()
    {
        $this->response->setContentType('application/json');
        echo json_encode(array(
            'ok' => true,
            'time' => date('c'),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function themesList()
    {
        $this->requireAdminThemeAccess(false);
        TypechoBio_Plugin::sendJsonResponse(TypechoBio_Plugin::buildThemeMarketPayload());
    }

    public function themesInstall()
    {
        $this->requireAdminThemeAccess(true);
        $dir = trim((string) $this->request->get('dir'));

        try {
            TypechoBio_Plugin::installThemeFromCatalog($dir, 'install');
            $payload = TypechoBio_Plugin::buildThemeMarketPayload();
            $payload['message'] = '主题已安装，启用主题选项和默认配置已同步。';
            TypechoBio_Plugin::sendJsonResponse($payload);
        } catch (Throwable $e) {
            $payload = TypechoBio_Plugin::buildThemeMarketPayload();
            $payload['ok'] = false;
            $payload['message'] = $e->getMessage();
            TypechoBio_Plugin::sendJsonResponse($payload, 400);
        }
    }

    public function themesUpdate()
    {
        $this->requireAdminThemeAccess(true);
        $dir = trim((string) $this->request->get('dir'));

        try {
            TypechoBio_Plugin::installThemeFromCatalog($dir, 'update');
            $payload = TypechoBio_Plugin::buildThemeMarketPayload();
            $payload['message'] = '主题已更新，启用主题选项已刷新。';
            TypechoBio_Plugin::sendJsonResponse($payload);
        } catch (Throwable $e) {
            $payload = TypechoBio_Plugin::buildThemeMarketPayload();
            $payload['ok'] = false;
            $payload['message'] = $e->getMessage();
            TypechoBio_Plugin::sendJsonResponse($payload, 400);
        }
    }

    public function comment()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $user = Typecho_Widget::widget('Widget_User');
        $isLoggedIn = is_object($user) && method_exists($user, 'hasLogin') ? (bool) $user->hasLogin() : false;

        $settings = TypechoBio_Plugin::getSettings($options);
        $target = TypechoBio_Plugin::getCommentTargetPost($settings, $options);

        if (!$target || empty($target['permalink'])) {
            $this->respondCommentFailure(_t('未配置可用的评论文章 ID'));
        }

        // Feedback::action() 调用 Router::match($permalink)，
        // 路由表的 regex 匹配的是路径（如 /archives/5/）而不是完整 URL。
        // $target['path'] 即 Widget_Archive->path，与标准评论表单提交的字段一致。
        $feedbackPermalink = !empty($target['path']) ? $target['path'] : (string) $target['permalink'];
        $input = array(
            'permalink' => $feedbackPermalink,
            'type' => 'comment',
            'text' => (string) $this->request->get('text'),
            'parent' => (int) $this->request->get('parent'),
        );

        if (trim($input['text']) === '') {
            $this->respondCommentFailure(_t('必须填写评论内容'));
        }

        if (!$isLoggedIn) {
            $input['author'] = (string) $this->request->get('author');
            $input['mail'] = (string) $this->request->get('mail');
            $input['url'] = (string) $this->request->get('url');
        }

        $beforeCoid = $this->findLatestCommentCoid((int) $target['cid']);

        // 获取 CSRF 安全 token 并注入 sandbox 请求，避免 Security::protect() 调用 goBack()
        $actualReferer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $securityClass = class_exists('Widget_Security') ? 'Widget_Security' : '\\Widget\\Security';
        if (class_exists($securityClass)) {
            try {
                $sec = Typecho_Widget::widget($securityClass);
                if (is_object($sec) && method_exists($sec, 'getToken')) {
                    $input['_'] = $sec->getToken($actualReferer);
                }
            } catch (Exception $secEx) {
                // ignore; proceed without token (protect() will call goBack and be caught below)
            } catch (Throwable $secEx) {
                // ignore
            }
        }

        try {
            $feedbackClass = class_exists('Widget_Feedback') ? 'Widget_Feedback' : '\\Widget\\Feedback';
            if (!class_exists($feedbackClass)) {
                $this->respondCommentFailure(_t('评论组件不可用'));
            }

            // Widget::widget() 的 finally { return } 会静默吞掉 callback 里抛出的 Exception，
            // 所以在 callback 内自行捕获并保存到外部变量。
            $caughtError = null;
            call_user_func(
                array($feedbackClass, 'alloc'),
                array('checkReferer' => 'false'),  // 必须传字符串 'false'，布尔 false 不等于字符串 'false'
                $input,
                function ($widget) use (&$caughtError) {
                    try {
                        $widget->action();
                    } catch (\Typecho\Widget\Terminal $e) {
                        // Terminal 是 sandbox 的正常退出信号（评论已写入，redirect 被拦截）
                        // 必须 re-throw，让 Widget::widget() 的 catch(Terminal) 正常处理
                        throw $e;
                    } catch (Exception $e) {
                        $caughtError = $e->getMessage();
                    } catch (Throwable $e) {
                        $caughtError = $e->getMessage();
                    }
                }
            );

            if ($caughtError !== null) {
                $this->respondCommentFailure($caughtError);
            }

            $inserted = $this->locateInsertedComment((int) $target['cid'], $beforeCoid);
            if ($inserted !== null) {
                $coid = isset($inserted['coid']) ? (int) $inserted['coid'] : 0;
                $status = isset($inserted['status']) ? (string) $inserted['status'] : 'approved';
                $message = $status === 'approved' ? _t('评论已提交') : _t('评论已提交，等待审核');
                $this->respondCommentSuccess($coid, $message, $status);
            }

            $this->respondCommentFailure(_t('评论未写入数据库，请检查评论规则或安全策略'));
        } catch (Exception $e) {
            $this->respondCommentFailure($e->getMessage());
        } catch (Throwable $e) {
            $this->respondCommentFailure($e->getMessage());
        }
    }

    private function findLatestCommentCoid($cid)
    {
        $db = TypechoBio_Plugin::getDb();
        $prefix = $db->getPrefix();
        $row = $db->fetchRow(
            $db->select('coid')
                ->from($prefix . 'comments')
                ->where('cid = ?', (int) $cid)
                ->order($prefix . 'comments.coid', Typecho_Db::SORT_DESC)
                ->limit(1)
        );

        return (is_array($row) && isset($row['coid'])) ? (int) $row['coid'] : 0;
    }

    private function locateInsertedComment($cid, $beforeCoid)
    {
        $db = TypechoBio_Plugin::getDb();
        $prefix = $db->getPrefix();

        // 只靠 coid 自增判断是否写入，避免 text/ip/author 字段被 Typecho filter 处理后不匹配
        $row = $db->fetchRow(
            $db->select('coid', 'status')
                ->from($prefix . 'comments')
                ->where('cid = ?', (int) $cid)
                ->where('coid > ?', (int) $beforeCoid)
                ->where('type = ?', 'comment')
                ->order($prefix . 'comments.coid', Typecho_Db::SORT_DESC)
                ->limit(1)
        );

        if (is_array($row) && !empty($row)) {
            return $row;
        }

        return null;
    }

    private function isAjaxRequest()
    {
        if ((string) $this->request->get('ajax') === '1') {
            return true;
        }

        $xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
        if ($xhr === 'xmlhttprequest') {
            return true;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
        if (strpos($accept, 'application/json') !== false) {
            return true;
        }

        return false;
    }

    private function requireAdminThemeAccess($requireCsrf)
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!is_object($user) || !method_exists($user, 'pass') || !$user->pass('administrator', true)) {
            TypechoBio_Plugin::sendJsonResponse(array(
                'ok' => false,
                'message' => '需要管理员权限',
            ), 403);
        }

        if ($requireCsrf && !$this->hasValidCsrfToken()) {
            TypechoBio_Plugin::sendJsonResponse(array(
                'ok' => false,
                'message' => '无效的安全令牌，请刷新页面后重试',
            ), 403);
        }
    }

    private function hasValidCsrfToken()
    {
        $securityClass = class_exists('Widget_Security') ? 'Widget_Security' : '\\Widget\\Security';
        if (!class_exists($securityClass)) {
            return true;
        }

        try {
            $security = Typecho_Widget::widget($securityClass);
            if (!is_object($security) || !method_exists($security, 'getToken')) {
                return true;
            }

            $token = isset($_POST['_']) ? trim((string) $_POST['_']) : '';
            if ($token === '') {
                $token = (string) $this->request->get('_');
            }
            if ($token === '') {
                return false;
            }

            $candidates = array();

            $pageUrl = isset($_POST['page_url']) ? trim((string) $_POST['page_url']) : '';
            if ($pageUrl === '') {
                $pageUrl = trim((string) $this->request->get('page_url'));
            }
            if ($pageUrl !== '') {
                $candidates[] = $pageUrl;
            }

            $referer = method_exists($this->request, 'getReferer')
                ? (string) $this->request->getReferer()
                : (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
            if ($referer !== '') {
                $candidates[] = $referer;
            }

            $candidates = array_values(array_unique(array_filter($candidates, function ($value) {
                return trim((string) $value) !== '';
            })));

            if (empty($candidates)) {
                return false;
            }

            foreach ($candidates as $candidate) {
                if (hash_equals((string) $security->getToken((string) $candidate), $token)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function respondCommentSuccess($coid, $message = 'ok', $status = 'approved')
    {
        if ($this->isAjaxRequest()) {
            $this->response->setContentType('application/json');
            echo json_encode(array(
                'ok' => true,
                'coid' => (int) $coid,
                'status' => (string) $status,
                'message' => (string) $message,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $redirect = trim((string) $this->request->get('redirect'));
        if ($redirect !== '') {
            $glue = strpos($redirect, '?') === false ? '?' : '&';
            $this->response->redirect($redirect . $glue . 'bio_comment=ok&bio_comment_status=' . rawurlencode((string) $status) . '&bio_comment_msg=' . rawurlencode((string) $message) . '#bio-comments');
        }

        $this->response->setContentType('application/json');
        echo json_encode(array(
            'ok' => true,
            'coid' => (int) $coid,
            'status' => (string) $status,
            'message' => (string) $message,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function respondCommentFailure($message)
    {
        if ($this->isAjaxRequest()) {
            $this->response->setStatus(400);
            $this->response->setContentType('application/json');
            echo json_encode(array(
                'ok' => false,
                'message' => (string) $message,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $redirect = trim((string) $this->request->get('redirect'));
        if ($redirect !== '') {
            $glue = strpos($redirect, '?') === false ? '?' : '&';
            $this->response->redirect($redirect . $glue . 'bio_comment=fail&bio_comment_msg=' . rawurlencode((string) $message) . '#bio-comments');
        }

        $this->response->setStatus(400);
        $this->response->setContentType('application/json');
        echo json_encode(array(
            'ok' => false,
            'message' => (string) $message,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
