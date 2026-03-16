<?php

class Enhancement_LinkOutputHelper
{
    public static function output(array $params)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = Enhancement_Plugin::runtimeSettings();
        if (!isset($options->plugins['activated']['Enhancement'])) {
            return _t('Enhancement 插件未激活');
        }

        $pattern = !empty($params[0]) && is_string($params[0]) ? $params[0] : 'SHOW_TEXT';
        $itemsNum = !empty($params[1]) && is_numeric($params[1]) ? $params[1] : 0;
        $sort = !empty($params[2]) && is_string($params[2]) ? $params[2] : null;
        $size = !empty($params[3]) && is_numeric($params[3]) ? $params[3] : $settings->dsize;
        $mode = isset($params[4]) ? $params[4] : 'FUNC';

        if ($pattern == 'SHOW_TEXT') {
            $pattern = $settings->pattern_text . "\n";
        } elseif ($pattern == 'SHOW_IMG') {
            $pattern = $settings->pattern_img . "\n";
        } elseif ($pattern == 'SHOW_MIX') {
            $pattern = $settings->pattern_mix . "\n";
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $nopicUrl = Enhancement_Plugin::appendVersionToAssetUrl(
            Typecho_Common::url('usr/plugins/Enhancement/nopic.png', $options->siteUrl)
        );

        $sql = $db->select()->from($prefix . 'links');
        if ($sort) {
            $sql = $sql->where('sort=?', $sort);
        }

        $sql = $sql->order($prefix . 'links.order', Typecho_Db::SORT_ASC);
        $itemsNum = intval($itemsNum);
        if ($itemsNum > 0) {
            $sql = $sql->limit($itemsNum);
        }

        $items = $db->fetchAll($sql);
        $result = '';

        foreach ($items as $item) {
            if ($item['image'] == null) {
                $item['image'] = $nopicUrl;
                if ($item['email'] != null) {
                    $item['image'] = Enhancement_Plugin::buildAvatarUrl($item['email'], $size, 'mm');
                }
            }

            if ($item['state'] == 1) {
                $safeName = htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8');
                $safeUrl = htmlspecialchars((string)$item['url'], ENT_QUOTES, 'UTF-8');
                $safeSort = htmlspecialchars((string)$item['sort'], ENT_QUOTES, 'UTF-8');
                $safeDescription = htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8');
                $safeImage = htmlspecialchars((string)$item['image'], ENT_QUOTES, 'UTF-8');
                $safeUser = htmlspecialchars((string)$item['user'], ENT_QUOTES, 'UTF-8');

                $result .= str_replace(
                    array('{lid}', '{name}', '{url}', '{sort}', '{title}', '{description}', '{image}', '{user}', '{size}'),
                    array((int)$item['lid'], $safeName, $safeUrl, $safeSort, $safeDescription, $safeDescription, $safeImage, $safeUser, (int)$size),
                    $pattern
                );
            }
        }

        if ($mode == 'HTML') {
            return $result;
        }

        echo $result;
    }

    public static function parseCallback($matches)
    {
        return self::output(array($matches[4], $matches[1], $matches[2], $matches[3], 'HTML'));
    }
}
