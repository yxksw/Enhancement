<?php

class Enhancement_FormHelper
{
    private static function applyLinkFieldRules($name, $url, $email, $image, $description, $sort = null, $user = null)
    {
        $name->addRule('required', _t('必须填写友链名称'));
        $url->addRule('required', _t('必须填写友链地址'));
        $url->addRule('url', _t('不是一个合法的链接地址'));
        $url->addRule(array('Enhancement_Plugin', 'validateHttpUrl'), _t('友链地址仅支持 http:// 或 https://'));
        $email->addRule('email', _t('不是一个合法的邮箱地址'));
        $image->addRule('url', _t('不是一个合法的图片地址'));
        $image->addRule(array('Enhancement_Plugin', 'validateOptionalHttpUrl'), _t('友链图片仅支持 http:// 或 https://'));
        $name->addRule('maxLength', _t('友链名称最多包含50个字符'), 50);
        $url->addRule('maxLength', _t('友链地址最多包含200个字符'), 200);
        $email->addRule('maxLength', _t('友链邮箱最多包含50个字符'), 50);
        $image->addRule('maxLength', _t('友链图片最多包含200个字符'), 200);
        $description->addRule('maxLength', _t('友链描述最多包含200个字符'), 200);

        if ($sort !== null) {
            $sort->addRule('maxLength', _t('友链分类最多包含50个字符'), 50);
        }

        if ($user !== null) {
            $user->addRule('maxLength', _t('自定义数据最多包含200个字符'), 200);
        }
    }

    public static function form($action = null)
    {
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-edit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );

        $name = new Typecho_Widget_Helper_Form_Element_Text('name', null, null, _t('网站名称*'));
        $form->addInput($name);

        $url = new Typecho_Widget_Helper_Form_Element_Text('url', null, "http://", _t('网站地址*'));
        $form->addInput($url);

        $sort = new Typecho_Widget_Helper_Form_Element_Text('sort', null, null, _t('友链分类'), _t('建议以英文字母开头，只包含字母与数字'));
        $form->addInput($sort);

        $email = new Typecho_Widget_Helper_Form_Element_Text('email', null, null, _t('您的邮箱'), _t('填写友链邮箱'));
        $form->addInput($email);

        $image = new Typecho_Widget_Helper_Form_Element_Text('image', null, null, _t('网站图片'), _t('需要以http://或https://开头，留空表示没有网站图片'));
        $form->addInput($image);

        $description = new Typecho_Widget_Helper_Form_Element_Textarea('description', null, null, _t('网站描述'));
        $description->setAttribute('class', 'typecho-option enhancement-public-full');
        $form->addInput($description);

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', null, null, _t('自定义数据'), _t('该项用于用户自定义数据扩展'));
        $form->addInput($user);

        $list = array('0' => '待审核', '1' => '已通过');
        $state = new Typecho_Widget_Helper_Form_Element_Radio('state', $list, '1', '审核状态');
        $form->addInput($state);

        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $form->addInput($do);

        $lid = new Typecho_Widget_Helper_Form_Element_Hidden('lid');
        $form->addInput($lid);

        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);
        $request = Typecho_Request::getInstance();

        if (isset($request->lid) && 'insert' != $action) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $item = $db->fetchRow($db->select()->from($prefix . 'links')->where('lid = ?', $request->lid));
            if (!$item) {
                throw new Typecho_Widget_Exception(_t('记录不存在'), 404);
            }

            $name->value($item['name']);
            $url->value($item['url']);
            $sort->value($item['sort']);
            $email->value($item['email']);
            $image->value($item['image']);
            $description->value($item['description']);
            $user->value($item['user']);
            $state->value($item['state']);
            $do->value('update');
            $lid->value($item['lid']);
            $submit->value(_t('编辑记录'));
            $_action = 'update';
        } else {
            $do->value('insert');
            $submit->value(_t('增加记录'));
            $_action = 'insert';
        }

        if (empty($action)) {
            $action = $_action;
        }

        if ('insert' == $action || 'update' == $action) {
            self::applyLinkFieldRules($name, $url, $email, $image, $description, $sort, $user);
        }
        if ('update' == $action) {
            $lid->addRule('required', _t('记录主键不存在'));
            $lid->addRule(array(new Enhancement_Plugin, 'enhancementExists'), _t('记录不存在'));
        }

        return $form;
    }

    public static function publicForm()
    {
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-submit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );
        $form->setAttribute('class', 'enhancement-public-form');
        $form->setAttribute('data-enhancement-form', 'link-submit');

        $name = new Typecho_Widget_Helper_Form_Element_Text('name', null, null, _t('网站名称*'));
        $form->addInput($name);

        $url = new Typecho_Widget_Helper_Form_Element_Text('url', null, "http://", _t('网站地址*'));
        $form->addInput($url);

        $email = new Typecho_Widget_Helper_Form_Element_Text('email', null, null, _t('您的邮箱'), _t('填写您的邮箱'));
        $form->addInput($email);

        $image = new Typecho_Widget_Helper_Form_Element_Text('image', null, null, _t('网站图片'), _t('需要以http://或https://开头，留空表示没有网站图片'));
        $form->addInput($image);

        $description = new Typecho_Widget_Helper_Form_Element_Textarea('description', null, null, _t('网站描述'));
        $description->setAttribute('class', 'typecho-option enhancement-public-full');
        $form->addInput($description);

        $honeypot = new Typecho_Widget_Helper_Form_Element_Text('homepage', null, '', _t('网站'), _t('请勿填写此字段'));
        $honeypot->setAttribute('class', 'hidden');
        $honeypot->input->setAttribute('style', 'display:none !important;');
        $honeypot->input->setAttribute('tabindex', '-1');
        $honeypot->input->setAttribute('autocomplete', 'off');
        $form->addInput($honeypot);

        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $do->value('submit');
        $form->addInput($do);

        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->setAttribute('class', 'typecho-option enhancement-public-submit enhancement-public-full');
        $submit->input->setAttribute('class', 'btn primary enhancement-public-submit-btn');
        $submit->value(_t('提交申请'));
        $form->addItem($submit);

        self::applyLinkFieldRules($name, $url, $email, $image, $description);

        return $form;
    }

    public static function momentsForm($action = null)
    {
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-moments-edit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );

        $content = new Typecho_Widget_Helper_Form_Element_Textarea('content', null, null, _t('内容*'));
        $form->addInput($content);

        $tags = new Typecho_Widget_Helper_Form_Element_Text('tags', null, null, _t('标签'), _t('可填逗号分隔或 JSON 数组'));
        $form->addInput($tags);

        $status = new Typecho_Widget_Helper_Form_Element_Radio(
            'status',
            array(
                'public' => _t('公开'),
                'private' => _t('私密')
            ),
            'public',
            _t('状态'),
            _t('公开：前台 API 默认可见；私密：仅携带有效 Token 请求 API 时可见')
        );
        $form->addInput($status);

        $locationAddress = new Typecho_Widget_Helper_Form_Element_Text(
            'location_address',
            null,
            null,
            _t('定位地址'),
            _t('点击“获取定位”后自动填充地址并保存到数据库，避免重复查询')
        );
        $locationAddress->input->setAttribute('placeholder', _t('未获取定位'));
        $form->addInput($locationAddress);

        $latitude = new Typecho_Widget_Helper_Form_Element_Hidden('latitude');
        $form->addInput($latitude);

        $longitude = new Typecho_Widget_Helper_Form_Element_Hidden('longitude');
        $form->addInput($longitude);

        $mapKeyConfigured = Enhancement_Plugin::tencentMapKey() !== '';
        $tencentMapKey = Enhancement_Plugin::tencentMapKey();
        $locationAction = new Typecho_Widget_Helper_Form_Element_Fake('location_action', null);
        $locationAction->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $locationAction->input->setAttribute('type', 'hidden');
        $locationAction->description(
            '<div class="enhancement-action-row">'
            . '<button type="button" class="btn enhancement-action-btn" id="enhancement-moment-locate-btn"'
            . ' data-map-key="' . htmlspecialchars($tencentMapKey, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-map-key-ready="' . ($mapKeyConfigured ? '1' : '0') . '">'
            . _t('获取定位')
            . '</button>'
            . '<span class="enhancement-action-note" id="enhancement-moment-locate-status">'
            . ($mapKeyConfigured ? _t('将通过浏览器直接调用腾讯地图 API 解析详细地址') : _t('未配置腾讯地图 API Key，仅获取经纬度'))
            . '</span>'
            . '</div>'
        );
        if (isset($locationAction->container)) {
            $locationAction->container->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        }
        $form->addInput($locationAction);

        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $form->addInput($do);

        $mid = new Typecho_Widget_Helper_Form_Element_Hidden('mid');
        $form->addInput($mid);

        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);

        $request = Typecho_Request::getInstance();

        if (isset($request->mid) && 'insert' != $action) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $item = $db->fetchRow($db->select()->from($prefix . 'moments')->where('mid = ?', $request->mid));
            if (!$item) {
                throw new Typecho_Widget_Exception(_t('记录不存在'), 404);
            }

            $content->value($item['content']);
            $tags->value($item['tags']);
            $status->value(isset($item['status']) ? Enhancement_Plugin::normalizeMomentStatus($item['status'], 'public') : 'public');
            $locationAddress->value(isset($item['location_address']) ? $item['location_address'] : '');
            $latitude->value(isset($item['latitude']) ? $item['latitude'] : '');
            $longitude->value(isset($item['longitude']) ? $item['longitude'] : '');
            $do->value('update');
            $mid->value($item['mid']);
            $submit->value(_t('编辑瞬间'));
            $_action = 'update';
        } else {
            $do->value('insert');
            $status->value('public');
            $submit->value(_t('发布瞬间'));
            $_action = 'insert';
        }

        if (empty($action)) {
            $action = $_action;
        }

        if ('insert' == $action || 'update' == $action) {
            $content->addRule('required', _t('必须填写内容'));
            $tags->addRule('maxLength', _t('标签最多包含200个字符'), 200);
            $status->addRule(array('Enhancement_Plugin', 'validateMomentStatus'), _t('状态值无效'));
            $locationAddress->addRule('maxLength', _t('定位地址最多255个字符'), 255);
            $latitude->addRule(array('Enhancement_Plugin', 'validateMomentLatitude'), _t('纬度格式不正确，范围应为 -90 到 90'));
            $longitude->addRule(array('Enhancement_Plugin', 'validateMomentLongitude'), _t('经度格式不正确，范围应为 -180 到 180'));
        }
        if ('update' == $action) {
            $mid->addRule('required', _t('记录主键不存在'));
            $mid->addRule(array(new Enhancement_Plugin, 'momentsExists'), _t('记录不存在'));
        }

        return $form;
    }

    private static function recordExists($table, $primaryKey, $value)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $item = $db->fetchRow(
            $db->select($primaryKey)
                ->from($prefix . $table)
                ->where($primaryKey . ' = ?', $value)
                ->limit(1)
        );

        return $item ? true : false;
    }

    public static function enhancementExists($lid)
    {
        return self::recordExists('links', 'lid', $lid);
    }

    public static function momentsExists($mid)
    {
        return self::recordExists('moments', 'mid', $mid);
    }

    public static function validateHttpUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http', 'https'), true);
    }

    public static function validateOptionalHttpUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return true;
        }

        return self::validateHttpUrl($url);
    }
}
