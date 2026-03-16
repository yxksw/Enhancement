<?php

/**
 * Typecho后台附件增强：图片预览、批量插入、保留官方删除按钮与逻辑
 * @author jkjoy
 * @date 2025-04-25
 */
class Enhancement_AttachmentHelper
{
    public static function addS3UrlSyncScript()
    {
        $resolveUrl = Helper::security()->getIndex('/action/enhancement-edit?do=resolve-attachment-urls');
        ?>
        <script>
        (function(window, $) {
            if (!$ || !window.Typecho) {
                return;
            }

            var endpoint = <?php echo json_encode($resolveUrl); ?>;
            var cache = {};
            var api = window.EnhancementAttachmentUrlSync || {};

            function getEditor() {
                return $('#text');
            }

            function normalizeSelection(selection, length, fallbackScroll) {
                if (!selection || typeof selection.start !== 'number' || typeof selection.end !== 'number') {
                    return null;
                }

                var maxLength = typeof length === 'number' && length >= 0 ? length : 0;
                var start = Math.max(0, Math.min(selection.start, maxLength));
                var end = Math.max(start, Math.min(selection.end, maxLength));
                var scrollTop = fallbackScroll && typeof fallbackScroll.scrollTop === 'number' ? fallbackScroll.scrollTop : 0;
                var scrollLeft = fallbackScroll && typeof fallbackScroll.scrollLeft === 'number' ? fallbackScroll.scrollLeft : 0;

                if (typeof selection.scrollTop === 'number') {
                    scrollTop = selection.scrollTop;
                }

                if (typeof selection.scrollLeft === 'number') {
                    scrollLeft = selection.scrollLeft;
                }

                return {
                    start: start,
                    end: end,
                    scrollTop: scrollTop,
                    scrollLeft: scrollLeft
                };
            }

            function getCurrentSelection() {
                var $textarea = getEditor();
                if (!$textarea.length || typeof $textarea.getSelection !== 'function') {
                    return null;
                }

                return normalizeSelection($textarea.getSelection(), $textarea.val().length, {
                    scrollTop: $textarea.scrollTop(),
                    scrollLeft: $textarea.scrollLeft()
                });
            }

            function focusEditorWithoutScroll($textarea) {
                if (!$textarea || !$textarea.length) {
                    return;
                }

                var element = $textarea.get(0);
                if (!element || typeof element.focus !== 'function') {
                    return;
                }

                try {
                    element.focus({preventScroll: true});
                } catch (error) {
                    element.focus();
                }
            }

            function restoreEditorScroll($textarea, selection) {
                if (!$textarea || !$textarea.length || !selection) {
                    return;
                }

                if (typeof selection.scrollTop === 'number') {
                    $textarea.scrollTop(selection.scrollTop);
                }

                if (typeof selection.scrollLeft === 'number') {
                    $textarea.scrollLeft(selection.scrollLeft);
                }
            }

            function setEditorSelection(selection) {
                var $textarea = getEditor();
                if (!$textarea.length || typeof $textarea.setSelection !== 'function') {
                    return false;
                }

                selection = normalizeSelection(selection, $textarea.val().length, {
                    scrollTop: $textarea.scrollTop(),
                    scrollLeft: $textarea.scrollLeft()
                });
                if (!selection) {
                    return false;
                }

                focusEditorWithoutScroll($textarea);
                $textarea.setSelection(selection.start, selection.end);

                restoreEditorScroll($textarea, selection);
                window.setTimeout(function() {
                    restoreEditorScroll($textarea, selection);
                }, 0);

                return true;
            }

            function buildInsertContent(title, url, isImage) {
                return isImage ? '![' + title + '](' + url + ')' : '[' + title + '](' + url + ')';
            }

            function getCid($li) {
                var cid = parseInt($li.attr('data-cid'), 10);
                return isNaN(cid) || cid <= 0 ? 0 : cid;
            }

            function uniqueCids(cids) {
                var seen = {};
                var result = [];
                $.each(cids, function(_, cid) {
                    cid = parseInt(cid, 10);
                    if (!cid || seen[cid]) {
                        return;
                    }
                    seen[cid] = true;
                    result.push(cid);
                });
                return result;
            }

            function applyUrl($li, url) {
                if (!url) {
                    return;
                }
                $li.attr('data-url', url);
                $li.data('url', url);
            }

            function requestUrls(cids, callback) {
                cids = uniqueCids(cids);
                if (!cids.length) {
                    if ($.isFunction(callback)) {
                        callback({});
                    }
                    return;
                }

                $.post(endpoint, {cid: cids}, function(response) {
                    var urls = response && response.urls ? response.urls : {};
                    $.each(urls, function(cid, url) {
                        if (url) {
                            cache[String(cid)] = url;
                        }
                    });

                    if ($.isFunction(callback)) {
                        callback(urls);
                    }
                }, 'json').fail(function() {
                    if ($.isFunction(callback)) {
                        callback({});
                    }
                });
            }

            api.captureSelection = function() {
                var selection = getCurrentSelection();
                if (selection) {
                    api.lastSelection = selection;
                }

                return selection;
            };

            api.restoreSelection = function() {
                if (!api.lastSelection) {
                    return false;
                }

                return setEditorSelection(api.lastSelection);
            };

            api.insertContent = function(content) {
                var $textarea = getEditor();
                if (!$textarea.length) {
                    return;
                }

                var text = typeof content === 'string' ? content : '';
                var currentValue = $textarea.val();
                var selection = normalizeSelection(api.lastSelection || getCurrentSelection(), currentValue.length, {
                    scrollTop: $textarea.scrollTop(),
                    scrollLeft: $textarea.scrollLeft()
                });
                if (!selection) {
                    selection = {
                        start: currentValue.length,
                        end: currentValue.length,
                        scrollTop: $textarea.scrollTop(),
                        scrollLeft: $textarea.scrollLeft()
                    };
                }

                var nextValue = currentValue.substring(0, selection.start) + text + currentValue.substring(selection.end);
                var caret = selection.start + text.length;

                $textarea.val(nextValue);
                api.lastSelection = {
                    start: caret,
                    end: caret,
                    scrollTop: selection.scrollTop,
                    scrollLeft: selection.scrollLeft
                };

                setEditorSelection(api.lastSelection);
            };

            api.insertFile = function(title, url, isImage) {
                api.insertContent(buildInsertContent(title, url, isImage) + '\n');
            };

            api.getUrl = function($li) {
                var cid = getCid($li);
                if (cid && cache[String(cid)]) {
                    return cache[String(cid)];
                }

                var url = $li.data('url');
                return typeof url === 'string' ? url : '';
            };

            api.refreshItems = function(items, callback) {
                var $items = items && items.jquery ? items : $(items || '#file-list li[data-cid]');
                var cids = [];

                $items.each(function() {
                    var cid = getCid($(this));
                    if (cid > 0) {
                        cids.push(cid);
                    }
                });

                requestUrls(cids, function(urls) {
                    $items.each(function() {
                        var $li = $(this);
                        var cid = getCid($li);
                        var key = String(cid);
                        var url = urls[key] || cache[key] || '';
                        if (url) {
                            applyUrl($li, url);
                        }
                    });

                    if ($.isFunction(callback)) {
                        callback($items);
                    }
                });
            };

            api.refresh = function(callback) {
                api.refreshItems($('#file-list li[data-cid]'), function() {
                    if ($.isFunction(callback)) {
                        callback();
                    }
                });
            };

            api.refreshItem = function($li, callback) {
                api.refreshItems($li, function() {
                    if ($.isFunction(callback)) {
                        callback(api.getUrl($li));
                    }
                });
            };

            window.EnhancementAttachmentUrlSync = api;

            $(function() {
                var $textarea = getEditor();
                if ($textarea.length) {
                    $textarea.off('.enhancement-s3-selection');
                    $textarea.on('click.enhancement-s3-selection keyup.enhancement-s3-selection mouseup.enhancement-s3-selection select.enhancement-s3-selection input.enhancement-s3-selection focus.enhancement-s3-selection', function() {
                        api.captureSelection();
                    });
                    api.captureSelection();
                }

                $(document).off('mousedown.enhancement-s3-selection', '#file-list li .insert, .btn-insert, #batch-insert');
                $(document).on('mousedown.enhancement-s3-selection', '#file-list li .insert, .btn-insert, #batch-insert', function() {
                    api.captureSelection();
                });

                api.refresh();

                $(document).off('click.enhancement-s3-sync', '#file-list li .insert');
                $(document).on('click.enhancement-s3-sync', '#file-list li .insert', function(e) {
                    var $link = $(this);
                    var $li = $link.closest('li');
                    var cid = getCid($li);

                    if (!cid) {
                        return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    api.refreshItem($li, function(url) {
                        api.insertFile($link.text(), url || api.getUrl($li), $li.data('image') == 1);
                    });

                    return false;
                });
            });
        })(window, window.jQuery);
        </script>
        <?php
    }

    public static function addEnhancedFeatures()
    {
        ?>
        <style>
        #file-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;padding:15px;list-style:none;margin:0;}
        #file-list li{position:relative;border:1px solid #e0e0e0;border-radius:4px;padding:10px;background:#fff;transition:all 0.3s ease;list-style:none;margin:0;}
        #file-list li:hover{box-shadow:0 2px 8px rgba(0,0,0,0.1);}
        #file-list li.loading{opacity:0.7;pointer-events:none;}
        .att-enhanced-thumb{position:relative;width:100%;height:150px;margin-bottom:8px;background:#f5f5f5;overflow:hidden;border-radius:3px;display:flex;align-items:center;justify-content:center;}
        .att-enhanced-thumb img{width:100%;height:100%;object-fit:contain;display:block;}
        .att-enhanced-thumb .file-icon{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:40px;color:#999;}
        .att-enhanced-finfo{padding:5px 0;}
        .att-enhanced-fname{font-size:13px;margin-bottom:5px;word-break:break-all;color:#333;}
        .att-enhanced-fsize{font-size:12px;color:#999;}
        .att-enhanced-factions{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;}
        .att-enhanced-factions button{flex:1;padding:4px 8px;border:none;border-radius:3px;background:#e0e0e0;color:#333;cursor:pointer;font-size:12px;transition:all 0.2s ease;}
        .att-enhanced-factions button:hover{background:#d0d0d0;}
        .att-enhanced-factions .btn-insert{background:#467B96;color:white;}
        .att-enhanced-factions .btn-insert:hover{background:#3c6a81;}
        .att-enhanced-checkbox{position:absolute;top:5px;right:5px;z-index:2;width:18px;height:18px;cursor:pointer;}
        .batch-actions{margin:15px;display:flex;gap:10px;align-items:center;}
        .btn-batch{padding:8px 15px;border-radius:4px;border:none;cursor:pointer;transition:all 0.3s ease;font-size:10px;display:inline-flex;align-items:center;justify-content:center;}
        .btn-batch.primary{background:#467B96;color:white;}
        .btn-batch.primary:hover{background:#3c6a81;}
        .btn-batch.secondary{background:#e0e0e0;color:#333;}
        .btn-batch.secondary:hover{background:#d0d0d0;}
        .upload-progress{position:absolute;bottom:0;left:0;width:100%;height:2px;background:#467B96;transition:width 0.3s ease;}
        </style>
        <script>
        $(document).ready(function() {
            function captureEditorSelection() {
                if (window.EnhancementAttachmentUrlSync && typeof window.EnhancementAttachmentUrlSync.captureSelection === 'function') {
                    window.EnhancementAttachmentUrlSync.captureSelection();
                }
            }

            function insertEditorContent(content) {
                if (window.EnhancementAttachmentUrlSync && typeof window.EnhancementAttachmentUrlSync.insertContent === 'function') {
                    window.EnhancementAttachmentUrlSync.insertContent(content);
                    return;
                }

                var textarea = $('#text');
                var pos = textarea.getSelection();
                var scrollTop = textarea.scrollTop();
                var scrollLeft = textarea.scrollLeft();
                var newContent = textarea.val();
                newContent = newContent.substring(0, pos.start) + content + newContent.substring(pos.end);
                textarea.val(newContent);
                textarea.focus();
                if (typeof textarea.setSelection === 'function') {
                    var caret = pos.start + content.length;
                    textarea.setSelection(caret, caret);
                }
                textarea.scrollTop(scrollTop);
                textarea.scrollLeft(scrollLeft);
            }

            function refreshAttachmentUrls(items, callback) {
                if (window.EnhancementAttachmentUrlSync && typeof window.EnhancementAttachmentUrlSync.refreshItems === 'function') {
                    window.EnhancementAttachmentUrlSync.refreshItems(items || $('#file-list li[data-cid]'), function() {
                        if (typeof callback === 'function') {
                            callback();
                        }
                    });
                    return;
                }

                if (typeof callback === 'function') {
                    callback();
                }
            }

            var $batchActions = $('<div class="batch-actions"></div>')
                .append('<button type="button" class="btn-batch primary" id="batch-insert">批量插入</button>')
                .append('<button type="button" class="btn-batch secondary" id="select-all">全选</button>')
                .append('<button type="button" class="btn-batch secondary" id="unselect-all">取消全选</button>');
            $('#file-list').before($batchActions);

            Typecho.insertFileToEditor = function(title, url, isImage) {
                var insertContent = isImage ? '![' + title + '](' + url + ')' :
                    '[' + title + '](' + url + ')';
                insertEditorContent(insertContent + '\n');
            };

            $('#batch-insert').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                captureEditorSelection();
                refreshAttachmentUrls($('#file-list li'), function() {
                    var content = '';
                    $('#file-list li').each(function() {
                        if ($(this).find('.att-enhanced-checkbox').is(':checked')) {
                            var $li = $(this);
                            var title = $li.find('.att-enhanced-fname').text();
                            var url = $li.data('url');
                            var isImage = $li.data('image') == 1;
                            content += isImage ? '![' + title + '](' + url + ')\n' : '[' + title + '](' + url + ')\n';
                        }
                    });
                    if (content) {
                        insertEditorContent(content);
                    }
                });
            });

            $('#select-all').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#file-list .att-enhanced-checkbox').prop('checked', true);
                return false;
            });
            $('#unselect-all').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#file-list .att-enhanced-checkbox').prop('checked', false);
                return false;
            });

            $(document).on('click', '.att-enhanced-checkbox', function(e) {e.stopPropagation();});

            function enhanceFileList() {
                $('#file-list li').each(function() {
                    var $li = $(this);
                    if ($li.hasClass('att-enhanced')) return;
                    $li.addClass('att-enhanced');
                    if ($li.find('.att-enhanced-checkbox').length === 0) {
                        $li.prepend('<input type="checkbox" class="att-enhanced-checkbox" />');
                    }
                    if ($li.find('.att-enhanced-thumb').length === 0) {
                        var url = $li.data('url');
                        var isImage = $li.data('image') == 1;
                        var fileName = $li.find('.insert').text();
                        var $thumbContainer = $('<div class="att-enhanced-thumb"></div>');
                        if (isImage) {
                            var $img = $('<img src="' + url + '" alt="' + fileName + '" />');
                            $img.on('error', function() {
                                $(this).replaceWith('<div class="file-icon">🖼️</div>');
                            });
                            $thumbContainer.append($img);
                        } else {
                            $thumbContainer.append('<div class="file-icon">📄</div>');
                        }
                        $li.find('.insert').before($thumbContainer);
                    }

                });
            }

            $(document).on('click', '.btn-insert', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $li = $(this).closest('li');
                var title = $li.find('.att-enhanced-fname').text();
                captureEditorSelection();
                refreshAttachmentUrls($li, function() {
                    Typecho.insertFileToEditor(title, $li.data('url'), $li.data('image') == 1);
                });
            });

            var originalUploadComplete = Typecho.uploadComplete;
            Typecho.uploadComplete = function(attachment) {
                if (typeof originalUploadComplete === 'function') {
                    originalUploadComplete(attachment);
                }

                setTimeout(function() {
                    refreshAttachmentUrls($('#file-list li'), enhanceFileList);
                }, 200);
            };

            refreshAttachmentUrls($('#file-list li'), enhanceFileList);
        });
        </script>
        <?php
    }
}
