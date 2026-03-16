<?php

class Enhancement_ContentParseHelper
{
    public static function parse($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;
        if (!is_string($text)) {
            return $text;
        }

        $isContentWidget = $widget instanceof Widget_Abstract_Contents;
        $isCommentWidget = $widget instanceof Widget_Abstract_Comments;
        if (!$isContentWidget && !$isCommentWidget) {
            return $text;
        }

        if ($isCommentWidget) {
            Enhancement_GoRedirectHelper::upgradeCommentWidgetUrl($widget);
        }

        $text = preg_replace_callback(
            "/<(?:links|enhancement)\\s*(\\d*)\\s*(\\w*)\\s*(\\d*)>\\s*(.*?)\\s*<\\/(?:links|enhancement)>/is",
            array('Enhancement_Plugin', 'parseCallback'),
            $text ? $text : ''
        );

        if ($isContentWidget && Enhancement_Plugin::videoParserEnabled()) {
            $text = Enhancement_MediaHelper::replaceVideoLinks($text);
        }

        if ($isContentWidget && Enhancement_Plugin::musicParserEnabled()) {
            $text = Enhancement_MediaHelper::replaceMusicLinks($text);
        }

        if ($isContentWidget) {
            $text = Enhancement_ShortcodeHelper::parseContent($text, $widget);
        }

        if ($isCommentWidget && Enhancement_Plugin::commentSmileyEnabled()) {
            $text = Enhancement_CommentSmileyHelper::parseShortcodes($text);
        }

        $text = Enhancement_GoRedirectHelper::rewriteExternalLinks($text);

        if (Enhancement_Plugin::blankTargetEnabled()) {
            $text = Enhancement_GoRedirectHelper::addBlankTarget($text);
        }

        return $text;
    }
}
