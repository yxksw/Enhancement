<?php

class Enhancement_S3Helper
{
    private static $runtimeLoaded = null;
    private static $uploadHookLogged = false;

    public static function registerHooks()
    {
        $targets = array(
            'Widget\\Upload',
            'Widget_Upload'
        );

        foreach ($targets as $target) {
            $factory = Typecho_Plugin::factory($target);
            $factory->uploadHandle = array(__CLASS__, 'uploadHandle');
            $factory->modifyHandle = array(__CLASS__, 'modifyHandle');
            $factory->deleteHandle = array(__CLASS__, 'deleteHandle');
            $factory->attachmentHandle = array(__CLASS__, 'attachmentHandle');
            $factory->attachmentDataHandle = array(__CLASS__, 'attachmentDataHandle');
        }
    }

    public static function enabled(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        if (!isset($settings->enable_s3_upload)) {
            return false;
        }

        return trim((string)$settings->enable_s3_upload) === '1';
    }

    public static function configured(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $required = array('s3_endpoint', 's3_bucket', 's3_region', 's3_access_key', 's3_secret_key');
        foreach ($required as $key) {
            $value = isset($settings->{$key}) ? trim((string)$settings->{$key}) : '';
            if ($value === '') {
                return false;
            }
        }

        return true;
    }

    private static function loadRuntime(): bool
    {
        if (self::$runtimeLoaded !== null) {
            return self::$runtimeLoaded;
        }

        $files = array(
            __DIR__ . '/S3Upload/Utils.php',
            __DIR__ . '/S3Upload/S3Client.php',
            __DIR__ . '/S3Upload/StreamUploader.php',
            __DIR__ . '/S3Upload/FileHandler.php'
        );

        foreach ($files as $file) {
            if (!is_file($file)) {
                self::$runtimeLoaded = false;
                return false;
            }
            require_once $file;
        }

        self::$runtimeLoaded = class_exists('Enhancement_S3Upload_FileHandler');
        return self::$runtimeLoaded;
    }

    public static function uploadHandle($file)
    {
        if (!self::loadRuntime()) {
            error_log('[Enhancement S3Upload] 上传钩子触发，但未加载到 S3 运行时文件');
            return false;
        }

        if (!self::$uploadHookLogged && class_exists('Enhancement_S3Upload_Utils')) {
            Enhancement_S3Upload_Utils::log('已进入 Enhancement S3 上传钩子', 'info');
            self::$uploadHookLogged = true;
        }

        return Enhancement_S3Upload_FileHandler::uploadHandle($file);
    }

    public static function modifyHandle($content, $file)
    {
        if (!self::loadRuntime()) {
            return false;
        }

        return Enhancement_S3Upload_FileHandler::modifyHandle($content, $file);
    }

    public static function deleteHandle($content)
    {
        if (!self::loadRuntime()) {
            return false;
        }

        return Enhancement_S3Upload_FileHandler::deleteHandle($content);
    }

    public static function attachmentHandle($content)
    {
        if (!self::loadRuntime()) {
            return '';
        }

        return Enhancement_S3Upload_FileHandler::attachmentHandle($content);
    }

    public static function attachmentDataHandle($content)
    {
        if (!self::loadRuntime()) {
            return '';
        }

        return Enhancement_S3Upload_FileHandler::attachmentDataHandle($content);
    }

    public static function resolveAttachmentUrl($content)
    {
        if (self::loadRuntime()) {
            return Enhancement_S3Upload_FileHandler::attachmentHandle($content);
        }

        $path = '';
        if (is_array($content)) {
            if (isset($content['attachment'])) {
                if (is_object($content['attachment']) && isset($content['attachment']->path)) {
                    $path = (string)$content['attachment']->path;
                } elseif (is_array($content['attachment']) && isset($content['attachment']['path'])) {
                    $path = (string)$content['attachment']['path'];
                }
            }

            if ($path === '' && isset($content['path'])) {
                $path = (string)$content['path'];
            }
        } elseif (is_object($content)) {
            if (isset($content->attachment)) {
                if (is_object($content->attachment) && isset($content->attachment->path)) {
                    $path = (string)$content->attachment->path;
                } elseif (is_array($content->attachment) && isset($content->attachment['path'])) {
                    $path = (string)$content->attachment['path'];
                }
            }

            if ($path === '' && isset($content->path)) {
                $path = (string)$content->path;
            }
        }

        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return '';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $base = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl;
        return Typecho_Common::url('/' . $path, $base);
    }
}
