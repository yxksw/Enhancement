<?php

class Enhancement_QqNotifyHelper
{
    private static $queueProcessed = false;
    private static $queueEnsured = false;

    private static function runtimeSettings()
    {
        return Enhancement_Plugin::runtimeSettings();
    }

    public static function mark($comment, $edit, $status)
    {
        $status = trim((string)$status);
        if ($status !== 'approved') {
            return;
        }

        $settings = self::runtimeSettings();
        if (!isset($settings->enable_comment_by_qq) || $settings->enable_comment_by_qq != '1') {
            return;
        }

        self::comment($edit, 'approved');
    }

    public static function notifyLinkSubmission(array $link)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::runtimeSettings();
        if (!isset($settings->enable_link_submit_by_qq) || $settings->enable_link_submit_by_qq != '1') {
            return;
        }
        if (!self::configured($settings)) {
            return;
        }

        $siteTitle = isset($options->title) ? trim((string)$options->title) : '';
        if ($siteTitle === '') {
            $siteTitle = '网站';
        }

        $linkName = isset($link['name']) ? trim((string)$link['name']) : '';
        $linkUrl = isset($link['url']) ? trim((string)$link['url']) : '';
        $linkEmail = isset($link['email']) ? trim((string)$link['email']) : '';
        $linkDescription = isset($link['description']) ? trim((string)$link['description']) : '';
        $reviewUrl = Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $options->adminUrl);

        $message = sprintf(
            "【友情链接申请通知】\n"
            . "🌐 站点：%s\n"
            . "🔖 友链名称：%s\n"
            . "🔗 友链地址：%s\n"
            . "📮 申请邮箱：%s\n"
            . "📝 网站描述：%s\n"
            . "🕒 提交时间：%s\n"
            . "🛠 审核地址：%s",
            $siteTitle,
            $linkName !== '' ? $linkName : '-',
            $linkUrl !== '' ? $linkUrl : '-',
            $linkEmail !== '' ? $linkEmail : '-',
            $linkDescription !== '' ? $linkDescription : '-',
            date('Y-m-d H:i:s'),
            $reviewUrl
        );

        self::dispatchMessage($message, $settings);
    }

    public static function comment($comment, $statusOverride = null)
    {
        $settings = self::runtimeSettings();

        $status = $statusOverride !== null
            ? trim((string)$statusOverride)
            : (isset($comment->status) ? trim((string)$comment->status) : '');
        if ($status !== 'approved') {
            return;
        }

        if ($comment->authorId === $comment->ownerId) {
            return;
        }

        $commentText = '';
        if (isset($comment->text)) {
            $commentText = $comment->text;
        } elseif (isset($comment->content)) {
            $commentText = $comment->content;
        }
        $commentText = strip_tags((string)$commentText);

        $message = sprintf(
            "【新评论通知】\n"
            . "📝 评论者：%s\n"
            . "📖 文章标题：《%s》\n"
            . "💬 评论内容：%s\n"
            . "🔗 文章链接：%s",
            $comment->author,
            $comment->title,
            $commentText,
            $comment->permalink
        );

        self::dispatchMessage((string)$message, $settings);
    }

    public static function processQueue()
    {
        if (self::$queueProcessed) {
            return;
        }
        self::$queueProcessed = true;

        $settings = self::runtimeSettings();
        if (!self::featureEnabled($settings)) {
            return;
        }
        if (!self::asyncQueueEnabled($settings)) {
            return;
        }

        self::ensureQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $row = $db->fetchRow(
                $db->select()
                    ->from($table)
                    ->where('status = ?', 0)
                    ->where('retries < ?', 5)
                    ->order('qid', Typecho_Db::SORT_ASC)
                    ->limit(1)
            );

            if (!is_array($row) || empty($row)) {
                return;
            }

            $qid = isset($row['qid']) ? intval($row['qid']) : 0;
            $message = isset($row['message']) ? (string)$row['message'] : '';
            $retries = isset($row['retries']) ? intval($row['retries']) : 0;
            if ($qid <= 0 || trim($message) === '') {
                return;
            }

            $result = self::sendMessage($message, true);
            if (!empty($result['success'])) {
                $db->query(
                    $db->update($table)
                        ->rows(array(
                            'status' => 1,
                            'updated' => time(),
                            'last_error' => null
                        ))
                        ->where('qid = ?', $qid)
                );
                return;
            }

            $retries++;
            $error = isset($result['error']) ? trim((string)$result['error']) : '';
            if ($error === '') {
                $error = 'send failed';
            }

            $db->query(
                $db->update($table)
                    ->rows(array(
                        'status' => ($retries >= 5 ? 2 : 0),
                        'retries' => $retries,
                        'updated' => time(),
                        'last_error' => Typecho_Common::subStr($error, 0, 250, '')
                    ))
                    ->where('qid = ?', $qid)
            );
        } catch (Exception $e) {
            // ignore queue errors
        }
    }

    public static function getQueueStats(): array
    {
        self::ensureQueueTable();

        $stats = array(
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'total' => 0,
        );

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $rows = $db->fetchAll(
                $db->select('status', array('COUNT(qid)' => 'num'))
                    ->from($table)
                    ->group('status')
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $status = isset($row['status']) ? intval($row['status']) : 0;
                    $num = isset($row['num']) ? intval($row['num']) : 0;
                    if ($status === 1) {
                        $stats['success'] += $num;
                    } elseif ($status === 2) {
                        $stats['failed'] += $num;
                    } else {
                        $stats['pending'] += $num;
                    }
                    $stats['total'] += $num;
                }
            }
        } catch (Exception $e) {
            // ignore queue stat errors
        }

        return $stats;
    }

    private static function featureEnabled($settings = null): bool
    {
        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        return (isset($settings->enable_comment_by_qq) && $settings->enable_comment_by_qq == '1')
            || (isset($settings->enable_link_submit_by_qq) && $settings->enable_link_submit_by_qq == '1');
    }

    private static function configured($settings = null): bool
    {
        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        $apiUrl = isset($settings->qqboturl) ? trim((string)$settings->qqboturl) : '';
        $qqNum = isset($settings->qq) ? trim((string)$settings->qq) : '';

        return $apiUrl !== '' && $qqNum !== '';
    }

    private static function dispatchMessage(string $message, $settings = null)
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        if (!self::configured($settings)) {
            return;
        }

        if (self::asyncQueueEnabled($settings)) {
            self::enqueue($message);
            return;
        }

        self::sendMessage($message, false);
    }

    private static function asyncQueueEnabled($settings = null): bool
    {
        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        if (!isset($settings->qq_async_queue)) {
            return true;
        }

        return $settings->qq_async_queue == '1';
    }

    private static function enqueue(string $message)
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        self::ensureQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $db->query(
                $db->insert($table)->rows(array(
                    'message' => $message,
                    'status' => 0,
                    'retries' => 0,
                    'last_error' => null,
                    'created' => time(),
                    'updated' => time()
                ))
            );
        } catch (Exception $e) {
            self::sendMessage($message, false);
        }
    }

    private static function ensureQueueTable()
    {
        if (self::$queueEnsured) {
            return;
        }
        self::$queueEnsured = true;

        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();

        try {
            if ('Pgsql' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS "' . $prefix . 'qq_notify_queue" ('
                    . '"qid" serial PRIMARY KEY,'
                    . '"message" text NOT NULL,'
                    . '"status" integer DEFAULT 0,'
                    . '"retries" integer DEFAULT 0,'
                    . '"last_error" varchar(255),'
                    . '"created" integer DEFAULT 0,'
                    . '"updated" integer DEFAULT 0'
                    . ')',
                    Typecho_Db::WRITE
                );
                return;
            }

            if ('Mysql' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS `' . $prefix . 'qq_notify_queue` ('
                    . '`qid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . '`message` text NOT NULL,'
                    . '`status` int(10) DEFAULT 0,'
                    . '`retries` int(10) DEFAULT 0,'
                    . '`last_error` varchar(255) DEFAULT NULL,'
                    . '`created` int(10) DEFAULT 0,'
                    . '`updated` int(10) DEFAULT 0,'
                    . 'PRIMARY KEY (`qid`)'
                    . ') ENGINE=MyISAM DEFAULT CHARSET=utf8',
                    Typecho_Db::WRITE
                );
                return;
            }

            if ('SQLite' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS `' . $prefix . 'qq_notify_queue` ('
                    . '`qid` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                    . '`message` text NOT NULL,'
                    . '`status` int(10) DEFAULT 0,'
                    . '`retries` int(10) DEFAULT 0,'
                    . '`last_error` varchar(255) DEFAULT NULL,'
                    . '`created` integer DEFAULT 0,'
                    . '`updated` integer DEFAULT 0'
                    . ')',
                    Typecho_Db::WRITE
                );
            }
        } catch (Exception $e) {
            // ignore queue table errors
        }
    }

    private static function sendMessage(string $message, bool $returnResult = false)
    {
        $result = array('success' => false, 'error' => '');

        $settings = self::runtimeSettings();
        $apiUrl = isset($settings->qqboturl) ? trim((string)$settings->qqboturl) : '';
        $qqNum = isset($settings->qq) ? trim((string)$settings->qq) : '';

        if ($apiUrl === '' || $qqNum === '') {
            $result['error'] = 'qq settings missing';
            return $returnResult ? $result : false;
        }

        $payload = array(
            'user_id' => (int)$qqNum,
            'message' => (string)$message
        );

        if (!function_exists('curl_init')) {
            $result['error'] = 'curl extension missing';
            return $returnResult ? $result : false;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            $result['error'] = 'payload json encode failed';
            return $returnResult ? $result : false;
        }

        $endpoint = rtrim($apiUrl, '/') . '/send_msg';
        $lastErrorNo = 0;
        $lastError = '';
        $lastHttpCode = 0;
        $lastResponse = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init();
            $curlOptions = array(
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json; charset=UTF-8',
                    'Accept: application/json'
                ),
                CURLOPT_SSL_VERIFYPEER => false
            );

            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if (defined('CURLOPT_NOSIGNAL')) {
                $curlOptions[CURLOPT_NOSIGNAL] = true;
            }

            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = $errno ? curl_error($ch) : '';
            curl_close($ch);

            if ($errno === 0 && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode((string)$response, true);
                if (is_array($decoded)) {
                    if (isset($decoded['retcode']) && intval($decoded['retcode']) !== 0) {
                        $lastErrorNo = 0;
                        $lastError = 'retcode=' . intval($decoded['retcode']);
                        $lastHttpCode = $httpCode;
                        $lastResponse = substr((string)$response, 0, 200);
                        break;
                    }
                    if (isset($decoded['status']) && strtolower((string)$decoded['status']) !== 'ok') {
                        $lastErrorNo = 0;
                        $lastError = 'status=' . strtolower((string)$decoded['status']);
                        $lastHttpCode = $httpCode;
                        $lastResponse = substr((string)$response, 0, 200);
                        break;
                    }
                }

                $result['success'] = true;
                return $returnResult ? $result : true;
            }

            $lastErrorNo = $errno;
            $lastError = $error;
            $lastHttpCode = $httpCode;
            $lastResponse = substr((string)$response, 0, 200);

            if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < 2) {
                usleep(150000);
                continue;
            }
            break;
        }

        if ($lastErrorNo !== 0) {
            if ($lastErrorNo !== CURLE_OPERATION_TIMEDOUT) {
                error_log('[Enhancement][CommentsByQQ] CURL错误: ' . $lastError);
            }
            $result['error'] = $lastError !== '' ? $lastError : ('curl errno=' . $lastErrorNo);
            return $returnResult ? $result : false;
        }

        if ($lastHttpCode >= 400) {
            error_log(sprintf('[Enhancement][CommentsByQQ] 响应异常 [HTTP %d]: %s', $lastHttpCode, $lastResponse));
        }

        $result['error'] = $lastError !== ''
            ? $lastError
            : ($lastResponse !== '' ? $lastResponse : ('http=' . $lastHttpCode));

        return $returnResult ? $result : false;
    }
}
