<?php namespace Phpcmf\Controllers\Admin;


class Module_category extends \Phpcmf\Common
{

    public function index() {

        $mid = \Phpcmf\Service::L('input')->get('dir');
        $module = \Phpcmf\Service::L('cache')->get('module-'.SITE_ID.'-content');
        if (!$module) {
            $this->_admin_msg(0, dr_lang('系统没有安装内容模块'), dr_url('module/module/index'));
        }

        $share = 0;

        // 设置url
        foreach ($module as $dir => $t) {
            if ($t['share']) {
                $share = 1;
                unset($module[$dir]);
                continue;
            } elseif ($t['system'] == 2) {
                // 自定义菜单的
                unset($module[$dir]);
                continue;
            } elseif ($t['hcategory']) {
                // 禁止使用栏目
                unset($module[$dir]);
                continue;
            }
            if ($mid && $mid != $dir) {
                unset($module[$dir]);
                continue;
            }
            $module[$dir]['name'] = dr_lang('%s栏目', $t['name']);
            $module[$dir]['url'] = \Phpcmf\Service::L('Router')->url($dir.'/category/index');
        }

        if ($share) {
            $tmp['share'] = [
                'name' => '共享栏目',
                'icon' => 'fa fa-share-alt',
                'title' => '共享',
                'url' => \Phpcmf\Service::L('Router')->url('category/index'),
                'dirname' => 'share',
            ];
            $one = $tmp['share'];
            $module = dr_array22array($tmp, $module);
        } else {
            $one = reset($module);
        }

        if (!$module) {
            $this->_admin_msg(0, dr_lang('系统没有可用内容模块'), dr_url('module/module/index'));
        }

        // 只存在一个项目
        dr_count($module) == 1 && dr_redirect($one['url']);

        \Phpcmf\Service::V()->assign([
            'url' => $one['url'],
            'menu' => \Phpcmf\Service::M('auth')->_iframe_menu($module, $one['dirname']),
            'module' => $module,
            'dirname' => $one['dirname'],
        ]);
        \Phpcmf\Service::V()->display('iframe_content.html');exit;
    }

    public function field_index() {

        $dir = dr_safe_replace(\Phpcmf\Service::L('input')->get('dir'));
        if (!$dir) {
            $this->_admin_msg(0, dr_lang('系统没有可用内容模块'));
        }

        $module = \Phpcmf\Service::M()->table('module')->where('dirname', $dir)->getRow();
        if (!$module) {
            $this->_admin_msg(0, dr_lang('数据#%s不存在', $dir));
        }

        $list = [];
        // 字段查询
        $mid = $dir;
        $like = ['catmodule-'.$dir];
        if ($module['share']) {
            $like[] = 'catmodule-share';
            $mid = 'share';
        }
        $setting = dr_string2array($module['setting']);
        $category = \Phpcmf\Service::M('category')->init(['table' => dr_module_table_prefix($mid).'_category'])->data_for_move(true);
        $field = \Phpcmf\Service::M()->db->table('field')
            ->where('ismain', 1)
            ->where('disabled', 0)
            ->whereIn('relatedname', $like)
            ->orderBy('displayorder ASC, id ASC')->get()->getResultArray();
        if ($field) {
            foreach ($field as $f) {
                /*
                $f['setting'] = dr_string2array($f['setting']);
                if ($f['relatedid']) {
                    $f['setting']['diy']['cat_field_catids'][] = $f['relatedid'];
                }*/
                $catids = [];
                foreach ($category as $t) {
                    if ($module['share'] && $t['mid'] != $dir) {
                        continue;
                    }
                    if (isset($t['setting']) && isset($t['setting']['module_field'])
                        && is_array($t['setting']['module_field']) && isset($t['setting']['module_field'][$f['fieldname']])) {
                        $catids[] = $t['id'];
                    }
                }
                $f['select'] = \Phpcmf\Service::L('category', 'module')->ismain(1)->select(
                    $module['dirname'],
                    $catids,
                    'name=\'data['.$f['fieldname'].'][]\' multiple="multiple" data-actions-box="true"',
                    '',
                    0,
                    0
                );
                $list[$f['id']] = $f;
            }
        }

        if (IS_POST) {
            $setting['module_category_hide'] = (int)\Phpcmf\Service::L('input')->post('hide');
            \Phpcmf\Service::M()->table('module')->update($module['id'], ['setting' => dr_array2string($setting)]);
            $post = \Phpcmf\Service::L('input')->post('data');
            $table = SITE_ID.'_'.($module['share'] ? 'share_category' : $module['dirname'].'_category');
            $update = [];
            foreach ($category as $t) {
                if ($module['share'] && $t['mid'] != $dir) {
                    continue;
                }
                $setting = dr_string2array($t['setting']);
                $setting['module_field'] = [];
                if ($post) {
                    foreach ($post as $fname => $catids) {
                        $catids = dr_string2array($catids);
                        if (in_array($t['id'], $catids)) {
                            $setting['module_field'][$fname] = 1;
                        }
                    }
                }
                $update[] = [
                    'id' => (int)$t['id'],
                    'setting' => dr_array2string($setting)
                ];
            }
            $update && \Phpcmf\Service::M()->db->table($table)->updateBatch($update, 'id');
            $this->_json(1, dr_lang('操作成功'));
        }

        \Phpcmf\Service::V()->assign([
            'mid' => $mid,
            'menu' => \Phpcmf\Service::M('auth')->_admin_menu(
                [
                    '内容模块' => ['module/module/index', 'fa fa-cogs'],
                    '模块【'.$dir.'】栏目模型字段' => ['module/module_category/field_index{dir='.$dir.'}', 'fa fa-code', 'module_category/field_index'],
                    '自定义字段' => ['url:'.\Phpcmf\Service::L('Router')->url('field/index', ['rname'=>'catmodule-'.$dir, 'rid'=>0]), 'fa fa-code', 'field/add'],
                    'help' => [798],
                ]
            ),
            'list' => $list,
            'hide' => $setting['module_category_hide'],
        ]);
        \Phpcmf\Service::V()->display('module_category_field.html');
    }

}
