<?php namespace Phpcmf\Controllers;

class Login extends \Phpcmf\Common
{

    /**
     * 初始化
     */
    public function __construct($object = NULL)
    {
        parent::__construct();
        if ($object) {
            foreach ($object as $var => $value) {
                $this->$var = $value;
            }
        }
    }

    // 正常登录
    public function index() {

        // 获取返回页面
        $url = dr_safe_url($_GET['back'] ? urldecode((string)\Phpcmf\Service::L('input')->get('back')) : $_SERVER['HTTP_REFERER']);
        if (strpos($url, 'login') !== false || strpos($url, 'register') !== false) {
            $url = MEMBER_URL; // 当来自登录或注册页面时返回到用户中心去
        } else {
            $url = str_ireplace(['<iframe', '<', '/>'], '', $url);
        }

        // 判断重复登录
        if ($this->uid) {
            if (IS_POST) {
                $this->_json(0, dr_lang('请退出登录账号再操作'));
            }
            $url = $url ? dr_redirect_safe_check($url) : MEMBER_URL;
            if (IS_DEV) {
                \Phpcmf\Service::C()->_admin_msg(1, '开发者模式：已经登录过了<br>正在自动跳转到用户中心（关闭开发者模式时即可自动跳转）', $url, 9);
            } else {
                dr_redirect($url);exit;
            }
        }

        if (IS_AJAX_POST) {
            $post = \Phpcmf\Service::L('input')->post('data');
            $back = dr_redirect_safe_check((string)\Phpcmf\Service::L('input')->xss_clean($_POST['back'] ? \Phpcmf\Service::L('input')->post('back') : MEMBER_URL, true));
            $back = str_ireplace(['<iframe', '<', '/>'], '', $back);
            // 回调钩子
            \Phpcmf\Hooks::trigger('member_login_before', $post);
            if ($this->member_cache['login']['code']
                && !\Phpcmf\Service::L('Form')->check_captcha('code')) {
                $this->_json(0, dr_lang('图片验证码不正确'));
            } elseif (empty($post['password'])) {
                $this->_json(0, dr_lang('密码必须填写'));
            } elseif (empty($post['username'])) {
                $this->_json(0, dr_lang('账号必须填写'));
            } else {
                $rt = \Phpcmf\Service::M('member')->login(dr_safe_username($post['username']), $post['password'], (int)$_POST['remember']);
                if ($rt['code']) {
                    // 登录成功
                    $rt['data']['url'] = urldecode($back);
                    return $this->_json(1, dr_lang('登录成功'), $rt['data'], true);
                } else {
                    $this->_json(0, $rt['msg']);
                }
            }
        }
        
        \Phpcmf\Service::V()->assign([
            'form' => dr_form_hidden(['back' => htmlspecialchars($url)]),
            'is_code' => $this->member_cache['login']['code'],
            'meta_name' => dr_lang('用户登录'),
            'meta_title' => dr_lang('用户登录').SITE_SEOJOIN.SITE_NAME,
        ]);
        return \Phpcmf\Service::V()->display('login.html');
    }

    // 短信验证码登录
    public function sms() {

        // 获取返回页面
        $url = dr_safe_url($_GET['back'] ? urldecode((string)\Phpcmf\Service::L('input')->get('back')) : $_SERVER['HTTP_REFERER']);
        strpos($url, 'login') !== false && $url = MEMBER_URL;

        $is_img_code = $this->member_cache['login']['code'];
        // 当关闭图形验证码时，启用短信图形验证时，再次开启图形验证
        if (!$this->member_cache['login']['code'] && $this->member_cache['login']['sms'] && !SYS_SMS_IMG_CODE) {
            $is_img_code = 1;
        }

        if (IS_AJAX_POST) {
            $post = \Phpcmf\Service::L('input')->post('data');
            $back = dr_redirect_safe_check(\Phpcmf\Service::L('input')->xss_clean($_POST['back'] ? \Phpcmf\Service::L('input')->post('back') : MEMBER_URL));
            \Phpcmf\Hooks::trigger('member_login_before', $post);
            if ($is_img_code && !\Phpcmf\Service::L('Form')->check_captcha('code')) {
                $this->_json(0, dr_lang('图片验证码不正确'));
            } elseif (empty($post['phone'])) {
                $this->_json(0, dr_lang('手机号码必须填写'));
            } else {
                $sms = \Phpcmf\Service::L('Form')->get_mobile_code($post['phone']);
                if (!$sms) {
                    $this->_json(0, dr_lang('未发送手机验证码'), ['field' => 'sms']);
                } elseif (!$_POST['sms']) {
                    $this->_json(0, dr_lang('手机验证码未填写'), ['field' => 'sms']);
                } elseif ($sms != trim($_POST['sms'])) {
                    $this->_json(0, dr_lang('手机验证码不正确'), ['field' => 'sms']);
                } else {
                    $rt = \Phpcmf\Service::M('member')->login_sms($post['phone'], (int)$_POST['remember']);
                    if ($rt['code']) {
                        // 登录成功
                        $rt['data']['url'] = urldecode($back);
                        return $this->_json(1, 'ok', $rt['data'], true);
                    } else {
                        $this->_json(0, $rt['msg']);
                    }
                }
            }
        } else {
            $this->_json(0, dr_lang('提交方式不正确'));
        }
    }

    /**
     * 授权登录
     */
    public function oauth() {

        $id = intval(\Phpcmf\Service::L('input')->get('id'));
        $name = dr_safe_replace(\Phpcmf\Service::L('input')->get('name'));
        $auth_name = 'member_auth_login_'.$name.'_'.$id;
        $oauth_id = \Phpcmf\Service::L('cache')->get_auth_data($auth_name, SITE_ID, 300);
        if (!$oauth_id && !IS_XRDEV) {
            // 需要加强判断条件
            $this->_msg(0, IS_DEV ? dr_lang('授权信息(%s)获取失败', $name) : dr_lang('授权信息获取失败'));
        }

        $oauth = \Phpcmf\Service::M()->table('member_oauth')->get($oauth_id);
        if (!$oauth && !IS_XRDEV) {
            $this->_msg(0, dr_lang('授权信息(#%s)不存在', $oauth_id));
        }

        // 查询关联用户
        $member = [];
        if ($oauth['uid']) {
            $member = \Phpcmf\Service::M('member')->get_member($oauth['uid']);
        } elseif ($oauth['unionid']) {
            $row = \Phpcmf\Service::M()->table('member_oauth')->where('unionid', $oauth['unionid'])->where('uid>0')->getRow();
            if ($row) {
                // 直接绑定unionid用户
                \Phpcmf\Service::M()->table('member_oauth')->update($oauth['id'], [
                    'uid' => $row['uid']
                ]);
                $member = \Phpcmf\Service::M('member')->get_member($row['uid']);
            }
        }

        // 跳转地址
        $back = urldecode(dr_redirect_safe_check(dr_safe_replace((string)\Phpcmf\Service::L('input')->get('back'))));
        $state = \Phpcmf\Service::L('input')->get('state');
        $goto_url = $back ? $back : MEMBER_URL;
        if ($state && $state != 'member') {
            $goto_url = strpos($state, 'http') === 0 ? $state : OAUTH_URL.'index.php?s=weixin&c='.$state.'&oid='.$oauth['oid'];
        }

        if ($member) {

            // 已经绑定,直接登录
            $sso = '';
            $member['uid'] = $oauth['uid'];
            $rt = \Phpcmf\Service::M('member')->login_oauth($name, $member);
            foreach ($rt as $url) {
                $sso.= '<script src="'.$url.'"></script>';
            }

            // 删除认证缓存
            \Phpcmf\Service::L('cache')->del_auth_data($auth_name);

            if (strpos($goto_url, 'is_admin_call')) {
                // 存储后台回话
                \Phpcmf\Service::M('auth')->save_login_auth($name, $member['uid']);
                $goto_url.= '&name='.$name.'&uid='.$member['uid'];
                return $this->_admin_msg(1, dr_lang('欢迎回来').$sso, \Phpcmf\Service::L('input')->xss_clean($goto_url), 0, true);
            } else {
                return $this->_msg(1, dr_lang('欢迎回来').$sso, \Phpcmf\Service::L('input')->xss_clean($goto_url), 0, true);
            }

        } else {

            // 来自后台
            if (strpos($goto_url, 'is_admin_call')) {
                $this->_admin_msg(0, dr_lang('%s，没有绑定本站账号', dr_html2emoji($oauth['nickname'])));
            } elseif ($this->member_cache['register']['close']) {
                $this->_msg(0, dr_lang('系统关闭了注册功能'));
            } elseif (!$this->member_cache['register']['group']) {
                $this->_msg(0, dr_lang('系统没有可注册的用户组'));
            }

            // 验证用户组
            $groupid = (int)\Phpcmf\Service::L('input')->get('groupid');
            if (!$groupid) {
                $groupid = (int)$this->member_cache['register']['groupid'];
                if (!$groupid) {
                    $this->_msg(0, dr_lang('系统没有设置默认注册的用户组'));
                }
            }

            if (!$groupid) {
                $this->_msg(0, dr_lang('无效的用户组'));
            } elseif (!$this->member_cache['group'][$groupid]['register']) {
                $this->_msg(0, dr_lang('该用户组不允许注册'));
            }

            \Phpcmf\Service::V()->assign([
                'id' => $id,
                'name' => $name,
                'oauth' => $oauth,
                'group' => $this->member_cache['group'],
                'groupid' => $groupid,
                'register' => $this->member_cache['register'],
            ]);

            // 没有绑定账号
            if ($this->member_cache['oauth']['login']) {
                // 直接登录 就直接创建账号
                if ($this->member_cache['register']['group'] && dr_count($this->member_cache['register']['group']) > 1) {
                    // 多个组需要选择
                    if (IS_POST) {
                        $groupid = (int)\Phpcmf\Service::L('input')->post('groupid');
                        if (!$this->member_cache['group'][$groupid]['register']) {
                            $this->_json(0, dr_lang('该用户组不允许注册'));
                        }
                        // 注册
                        $rt = \Phpcmf\Service::M('member')->register_oauth($groupid, $oauth);
                        if ($rt['code']) {
                            // 登录成功
                            $rt['data']['url'] = \Phpcmf\Service::L('input')->xss_clean($goto_url);
                            // 删除认证缓存
                            \Phpcmf\Service::L('cache')->del_auth_data($auth_name);
                            return $this->_json(1, 'ok', $rt['data'], true);
                        } else {
                            $this->_json(0, $rt['msg']);
                        }
                    }
                    \Phpcmf\Service::V()->assign([
                        'form' => dr_form_hidden(),
                        'meta_name' => dr_lang('授权登录'),
                        'meta_title' => dr_lang('授权登录').SITE_SEOJOIN.SITE_NAME,
                    ]);
                    \Phpcmf\Service::V()->display('login_select.html');
                } else {
                    // 单个用户组组直接注册
                    $rt = \Phpcmf\Service::M('member')->register_oauth(0, $oauth);
                    if ($rt['code']) {
                        // 登录成功
                        $rt['data']['url'] = \Phpcmf\Service::L('input')->xss_clean($goto_url);
                        // 删除认证缓存
                        \Phpcmf\Service::L('cache')->del_auth_data($auth_name);
                        $sso = '';
                        foreach ($rt['data']['sso'] as $url) {
                            $sso.= '<script src="'.$url.'"></script>';
                        }
                        return $this->_msg(1, dr_lang('登录成功').$sso, $goto_url, true);
                    } else {
                        $this->_msg(0, $rt['msg']);
                    }
                }
            } else {
                // 绑定账号 绑定已有的账号或者注册新账号
                $type = intval(\Phpcmf\Service::L('input')->get('type'));
                if ($type) {
                    // 获取该组可用注册字段
                    $field = [];
                    if ($this->member_cache['group'][$groupid]['register_field']) {
                        foreach ($this->member_cache['group'][$groupid]['register_field'] as $fname) {
                            $field[$fname] = $this->member_cache['field'][$fname];
                            $field[$fname]['ismember'] = 1;
                        }
                    }
                }
                if (IS_POST) {
                    $post = \Phpcmf\Service::L('input')->post('data');
                    if (!\Phpcmf\Service::L('input')->post('is_protocol')) {
                        $this->_json(0, dr_lang('你没有同意注册协议'));
                    }
                    if ($type) {
                        // 注册绑定
                        if (empty($post['password'])) {
                            $this->_json(0, dr_lang('密码必须填写'), ['field' => 'password']);
                        } elseif ($post['password'] != $post['password2']) {
                            $this->_json(0, dr_lang('确认密码不一致'), ['field' => 'password2']);
                        } else {
                            // 注册之前的钩子
                            \Phpcmf\Hooks::trigger('member_register_before', $post);
                            // 验证操作
                            if ($this->member_cache['register']['sms']) {
                                $sms = \Phpcmf\Service::L('Form')->get_mobile_code($post['phone']);
                                if (!$sms) {
                                    $this->_json(0, dr_lang('未发送手机验证码'), ['field' => 'sms']);
                                } elseif (!$_POST['sms']) {
                                    $this->_json(0, dr_lang('手机验证码未填写'), ['field' => 'sms']);
                                } elseif ($sms != (string)$_POST['sms']) {
                                    $this->_json(0, dr_lang('手机验证码不正确'), ['field' => 'sms']);
                                }
                            }
                            // 验证字段
                            if ($this->member_cache['oauth']['field']) {
                                list($data, $return, $attach) = \Phpcmf\Service::L('Form')->validation($post, null, $field);
                                // 输出错误
                                if ($return) {
                                    $this->_json(0, $return['error'], ['field' => $return['name']]);
                                }
                            } else {
                                $data = [1 => []];
                                $attach = [];
                            }

                            // 入库记录
                            $rt = \Phpcmf\Service::M('member')->register_oauth_bang($oauth, $groupid, [
                                'username' => (string)$post['username'],
                                'phone' => (string)$post['phone'],
                                'email' => (string)$post['email'],
                                'password' => dr_safe_password($post['password']),
                                'name' => dr_safe_replace($post['name']),
                            ], $data[1]);
                            if ($rt['code']) {
                                // 注册绑定成功
                                $this->member = $rt['data'];
                                \Phpcmf\Service::M('member')->save_cookie($this->member, 1);
                                // 附件归档
                                SYS_ATTACHMENT_DB && $attach && \Phpcmf\Service::M('Attachment')->handle(
                                    $this->member['id'],
                                    \Phpcmf\Service::M()->dbprefix('member').'-'.$rt['code'],
                                    $attach
                                );
                                // 手机认证成功
                                if ($this->member_cache['register']['sms']) {
                                    \Phpcmf\Service::M()->db->table('member_data')->where('id', $this->member['id'])->update(['is_mobile' => 1]);
                                }
                                $rt['data']['url'] = \Phpcmf\Service::L('input')->xss_clean($goto_url);
                                // 删除认证缓存
                                \Phpcmf\Service::L('cache')->del_auth_data($auth_name);
                                return $this->_json(1, dr_lang('注册成功'), $rt['data'], true);
                            } else {
                                $this->_json(0, $rt['msg'], ['field' => $rt['data']['field']]);
                            }
                        }
                    } else {
                        // 登录绑定
                        if (empty($post['username']) || empty($post['password'])) {
                            $this->_json(0, dr_lang('账号或密码必须填写'));
                        } else {
                            \Phpcmf\Hooks::trigger('member_login_before', $post);
                            $rt = \Phpcmf\Service::M('member')->login($post['username'], $post['password'], (int)$_POST['remember']);
                            if ($rt['code']) {
                                // 登录成功
                                $rt['data']['url'] = \Phpcmf\Service::L('input')->xss_clean($goto_url);
                                // 删除认证缓存
                                \Phpcmf\Service::L('cache')->del_auth_data($auth_name);
                                // 删除旧账号
                                \Phpcmf\Service::M()->db->table('member_oauth')->where('oauth', $oauth['oauth'])->where('uid', $rt['data']['member']['id'])->delete();
                                // 更改状态
                                \Phpcmf\Service::M()->db->table('member_oauth')->where('id', $oauth['id'])->update(['uid' => $rt['data']['member']['id']]);
                                dr_is_app('weixin') && $oauth['oauth'] == 'wechat' && \Phpcmf\Service::M()->db->table('weixin_user')->where('openid', $oauth['oid'])->update([
                                    'uid' => $rt['data']['member']['id'],
                                    'username' => $rt['data']['member']['username'],
                                ]);
                                return $this->_json(1, dr_lang('绑定成功'), $rt['data'], true);
                            } else {
                                $this->_json(0, $rt['msg']);
                            }
                        }
                    }
                    exit;
                }

                $is_img_code = $this->member_cache['register']['code'];

                // 当关闭图形验证码时，启用短信图形验证时，再次开启图形验证
                if (!$this->member_cache['register']['code'] && $this->member_cache['register']['sms'] && !SYS_SMS_IMG_CODE) {
                    $is_img_code = 1;
                }

                \Phpcmf\Service::V()->assign([
                    'type' => $type,
                    'form' => dr_form_hidden(['type' => $type]),
                    'myfield' => $type && $this->member_cache['oauth']['field'] ? \Phpcmf\Service::L('field')->toform(0, $field) : '',
                    'register' => $this->member_cache['register'],
                    'meta_name' => dr_lang('绑定账号'),
                    'meta_title' => dr_lang('绑定账号').SITE_SEOJOIN.SITE_NAME,
                    'is_img_code' => $is_img_code,
                ]);
                \Phpcmf\Service::V()->display('login_oauth.html');
            }
        }
        exit;
    }
    
    /**
     * 找回密码
     */
    public function find() {

        if (IS_POST) {

            $post = \Phpcmf\Service::L('input')->post('data');
            $value = dr_safe_replace($post['value']);
            if (strpos($value, '@') !== false) {
                // 邮箱模式
                $data = \Phpcmf\Service::M()->db->table('member')->where('email', $value)->get()->getRowArray();
                if (!$data) {
                    $this->_json(0, dr_lang('账号凭证不存在'), ['field' => 'value']);
                }
            } elseif (is_numeric($value) && strlen($value) == 11) {
                // 手机
                $data = \Phpcmf\Service::M()->db->table('member')->where('phone', $value)->get()->getRowArray();
                if (!$data) {
                    $this->_json(0, dr_lang('账号凭证不存在'), ['field' => 'value']);
                }
            } else {
                $this->_json(0, dr_lang('账号凭证格式不正确'), ['field' => 'value']);
            }

            // 防止验证码猜测
            if (dr_is_app('xrsafe')) {
                // 如果安装迅睿安全插件，进入插件验证机制
                \Phpcmf\Service::M('safe', 'xrsafe')->find_check($value);
            } else {
                // 普通验证
                $sn = 'fc_pass_find_'.date('Ymd', SYS_TIME).$value;
                $count = (int)\Phpcmf\Service::L('cache')->get_data($sn);
                if ($count > 20) {
                    $this->_json(0, dr_lang('今日找回密码次数达到上限'));
                }
                \Phpcmf\Service::L('cache')->set_data($sn, $count + 1, 3600 * 24);
            }

            if ((!$post['code'] || !$data['randcode'] || $post['code'] != $data['randcode'])) {
                $this->_json(0, dr_lang('凭证验证码不正确'), ['field' => 'code']);
            } elseif (!$post['password']) {
                $this->_json(0, dr_lang('密码不能为空'), ['field' => 'password']);
            } elseif ($post['password'] != $post['password2']) {
                $this->_json(0, dr_lang('两次密码不一致'), ['field' => 'password2']);
            }

            // 修改密码
            \Phpcmf\Service::M('member')->edit_password($data, $post['password']);
            \Phpcmf\Service::M()->db->table('member')->where('id', $data['id'])->update(['randcode' => 0]);

            $this->_json(1, dr_lang('账号[%s]密码修改成功', $data['username']), $data);
        }

        \Phpcmf\Service::V()->assign([
            'form' => dr_form_hidden(),
            'api_url' => dr_member_url('api/find_code'),
            'meta_name' => dr_lang('找回密码'),
            'meta_title' => dr_lang('找回密码').SITE_SEOJOIN.SITE_NAME,
        ]);
        \Phpcmf\Service::V()->display('find.html');
    }

    /**
     * 退出
     */
    public function out() {

        // 注销授权登陆的会员
        if ($this->session()->get('member_auth_uid')) {
            \Phpcmf\Service::C()->session()->delete('member_auth_uid');
            $this->_json(0, dr_lang('当前状态无法退出账号'));
        }

        $this->_json(1, dr_lang('您的账号已退出系统'), [
            'url' => dr_safe_url(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : SITE_URL),
            'sso' => \Phpcmf\Service::M('member')->logout(),
        ]);
    }

}
