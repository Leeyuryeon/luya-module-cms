<?php

namespace cms\components;

use Yii;
use Exception;
use cmsadmin\models\Cat;
use admin\models\Lang;
use cmsadmin\models\NavItemRedirect;

trigger_error("The Links component is deprecated, use Menu component instead", E_USER_DEPRECATED);

/**
 * Links component array base:.
 * 
 * ```php
 * [
 *  '1' => [ // 1 = de
 *      ['full_url' => '...'', 'url' => '...', 'id' => '', 'nav_id' => ''],
 *      // ...
 *  ],
 *  '2' => [ // 2 = en
 *  
 *  ],
 * ]
 * ```
 */
class Links extends \yii\base\Component
{
    private $_cats = null;

    private $_langs = null;

    private $_prefix = null;

    private $_links = [];

    public $activeUrl = null;

    public function init()
    {
        foreach ($this->getLangs() as $lang) {
            $this->_links[$lang['id']] = [];
            $this->recursiveFindChildren($lang, 0, '', 0);
            $this->resolveRedirect($lang['id']);
        }
    }

    public function getPrefix()
    {
        if ($this->_prefix === null) {
            $this->_prefix = Yii::$app->composition->getFull();
        }

        return $this->_prefix;
    }

    public function getLangs()
    {
        if ($this->_langs === null) {
            $this->_langs = Lang::getQuery();
        }

        return $this->_langs;
    }

    public function getLangId($shortCode)
    {
        $langs = $this->getLangs();

        if (array_key_exists($shortCode, $langs)) {
            return $langs[$shortCode]['id'];
        }

        throw new Exception("the requested langauge $shortCode does not exists in language array.");
    }

    public function getCats()
    {
        if ($this->_cats === null) {
            $this->_cats = Cat::getQuery();
        }

        return $this->_cats;
    }

    public function getCatRewrite($id)
    {
        $cats = $this->getCats();

        if (array_key_exists($id, $cats)) {
            return $cats[$id]['rewrite'];
        }

        return false;
    }
    
    private function resolveRedirect($langId)
    {
        foreach($this->findAll(['redirect_resolve' => true, 'lang_id' => $langId]) as $index => $link) {
            $redirect = NavItemRedirect::findOne($link['redirect_resolve_value']);
            if ($redirect) {
                switch($redirect->type) {
                    case NavItemRedirect::TYPE_INTERNAL_PAGE:
                        // $redirect->value is nav_id
                        $findLink = $this->findOne(['lang_id' => $langId, 'nav_id' => $redirect->value]);
                        $this->editLink($langId, $index, ['full_url' => $findLink['full_url']]);
                        break;
                    case NavItemRedirect::TYPE_EXTERNAL_URL:
                        $this->editLink($langId, $index, ['url' => $redirect->value, 'full_url' => $redirect->value]);
                        break;
                }
            }
        }
    }

    private function editLink($langId, $index, array $args)
    {
        $this->_links[$langId][$index] = array_merge($this->_links[$langId][$index], $args);
    }

    private function recursiveFindChildren($lang, $parentNavId, $urlPrefix, $depth)
    {
        foreach ($this->getChildren($lang['id'], $parentNavId) as $index => $item) {
            $rewrite = $urlPrefix.$item['rewrite'];
            $this->_links[$lang['id']][] = [
                'is_home' => $item['is_home'],
                'full_url' => ($item['is_home']) ? '' : $this->getPrefix().$rewrite,
                'url' => $rewrite,
                'rewrite' => $item['rewrite'],
                'nav_id' => $item['nav_id'],
                'nav_item_id' => $item['nav_item_id'], // alias of id
                'id' => $item['nav_item_id'], // alias of nav_item_id
                'parent_nav_id' => $item['parent_nav_id'],
                'title' => $item['title'],
                'lang' => $lang['short_code'],
                'lang_id' => $lang['id'],
                'cat' => $this->getCatRewrite($item['cat_id']),
                'depth' => (int) $depth,
                'is_hidden' => $item['is_hidden'],
                'is_offline' => $item['is_offline'],
                'redirect_resolve' => ($item['nav_item_type'] == 3) ? true : false,
                'redirect_resolve_value' => ($item['nav_item_type'] == 3) ? $item['nav_item_type_id'] : false,
            ];

            if ($this->hasChildren($lang['id'], $item['nav_id'])) {
                $this->recursiveFindChildren($lang, $item['nav_id'], $rewrite.'/', $depth + 1);
            }
        }
    }

    private function hasChildren($langId, $parentNavId)
    {
        $query = Yii::$app->db->createCommand('SELECT COUNT(*) as count FROM cms_nav_item as i LEFT JOIN (cms_nav as n) ON (n.id=i.nav_id) WHERE n.parent_nav_id=:parent_nav_id AND i.lang_id=:lang_id AND n.is_deleted=0')->bindValues([
            ':parent_nav_id' => $parentNavId, ':lang_id' => $langId,
        ])->queryOne();

        return $query['count'];
    }

    private function getChildren($langId, $parentNavId)
    {
        return Yii::$app->db->createCommand('SELECT n.id as nav_id, n.is_hidden, n.is_home, n.cat_id, n.parent_nav_id, n.is_offline, i.id as nav_item_id, i.lang_id, i.title, i.rewrite, i.lang_id, i.nav_item_type, i.nav_item_type_id FROM cms_nav_item as i LEFT JOIN (cms_nav as n) ON (n.id=i.nav_id) WHERE i.lang_id=:lang_id AND n.parent_nav_id=:parent_nav_id AND n.is_deleted=0 ORDER by n.sort_index ASC')->bindValues([
            ':parent_nav_id' => $parentNavId, ':lang_id' => $langId,
        ])->queryAll();
    }
    
    /**
     * @param numeric|string $langShortCode Could be `1` or `de`
     *
     * @return array
     */
    public function getLinksLanguageContainer($langShortCode)
    {
        if (!is_numeric($langShortCode)) {
            if (!$langShortCode) {
                $langShortCode = Yii::$app->composition->getKey('langShortCode');
            }

            if (!$langShortCode) {
                $assoc = Lang::getDefault();
                $langShortCode = $assoc['short_code'];
            }

            $langId = $this->getLangId($langShortCode);
        } else {
            $langId = $langShortCode;
        }

        return array_key_exists($langId, $this->_links) ? $this->_links[$langId] : [];
    }

    /**
     * alias class for findByArguemnts.
     * 
     * @param array $argsArray
     */
    public function findAll(array $argsArray)
    {
        return $this->findByArguments($argsArray);
    }

    public function findByArguments(array $argsArray = [])
    {
        $lang = false;

        if (!isset($argsArray['show_hidden'])) {
            if (!isset($argsArray['is_hidden'])) {
                $argsArray['is_hidden'] = 0;
            }
        } else {
            unset($argsArray['show_hidden']);
        }

        if (!isset($argsArray['show_offline'])) {
            if (!isset($argsArray['is_offline'])) {
                $argsArray['is_offline'] = 0;
            }
        } else {
            unset($argsArray['is_offline']);
        }

        if (isset($argsArray['lang'])) {
            $lang = $argsArray['lang'];
            unset($argsArray['lang']);
        }

        if (isset($argsArray['lang_id'])) {
            $lang = $argsArray['lang_id'];
            unset($argsArray['lang_id']);
        }

        $_index = $this->getLinksLanguageContainer($lang);

        foreach ($argsArray as $key => $value) {
            foreach ($_index as $linkIndexId => $args) {
                if (!isset($args[$key])) {
                    unset($_index[$linkIndexId]);
                }

                if (isset($args[$key]) && $args[$key] != $value) {
                    unset($_index[$linkIndexId]);
                }
            }
        }

        return $_index;
    }

    /**
     * alias class for findOnyByArguments.
     */
    public function findOne(array $argsArray)
    {
        return $this->findOneByArguments($argsArray);
    }

    public function findOneByArguments(array $argsArray)
    {
        $links = $this->findByArguments($argsArray);
        if (empty($links)) {
            return false;
        }

        return array_values($links)[0];
    }

    public function getActiveLanguages()
    {
        $data = [];
        $currentPage = Yii::$app->links->findOneByArguments(['url' => Yii::$app->links->activeUrl, 'show_hidden' => true]);

        foreach (Lang::find()->asArray()->all() as $lang) {
            $data[] = [
                'link' => Yii::$app->links->findOneByArguments(['nav_id' => $currentPage['nav_id'], 'show_hidden' => true, 'lang_id' => $lang['id']]),
                'lang' => $lang,
            ];
        }

        return $data;
    }

    public function teardown($link)
    {
        $parent = $this->getParent($link);

        $tears = [];
        
        $home = $this->findOneByArguments(['url' => $link, 'show_hidden' => true, 'is_home' => 0]);
        if ($home) {
            $tears[] = $home;
        }
        while ($parent) {
            $tears[] = $parent;
            $link = $parent['url'];
            $parent = $this->getParent($link);
        }

        $tears = array_reverse($tears);

        return $tears;
    }

    public function getParents($link)
    {
        $parent = $this->getParent($link);

        $tears = [];
        while ($parent) {
            $tears[] = $parent;
            $link = $parent['url'];
            $parent = $this->getParent($link);
        }

        $tears = array_reverse($tears);

        return $tears;
    }

    public function getParent($link)
    {
        $link = $this->getLink($link);

        return $this->findOne(['nav_id' => $link['parent_nav_id'], 'show_hidden' => true]);
    }

    public function getChilds($link)
    {
        $child = $this->getChild($link);
        $tears = [];
        while ($child) {
            $tears[] = $child;
            $link = $child['url'];
            $child = $this->getChild($link);
        }

        return $tears;
    }

    public function getChild($link)
    {
        $link = $this->getLink($link);

        return $this->findOneByArguments(['parent_nav_id' => $link['nav_id'], 'show_hidden' => true]);
    }

    public function hasLink($link)
    {
        return ($this->findOneByArguments(['url' => $link, 'show_hidden' => true])) ? true : false;
    }

    public function getLink($link)
    {
        return $this->findOneByArguments(['url' => $link, 'show_hidden' => true]);
    }

    public function getActiveUrlPart($part)
    {
        $parts = explode('/', $this->activeUrl);

        return (array_key_exists($part, $parts)) ? $parts[$part] : null;
    }

    public function getCurrentLink()
    {
        $url = $this->getResolveActiveUrl();

        return $this->findOneByArguments(['show_hidden' => true, 'url' => $url]);
    }

    public function getResolveActiveUrl()
    {
        if (empty($this->activeUrl)) {
            $this->activeUrl = $this->getDefaultLink();
        }

        return $this->activeUrl;
    }

    /**
     * if the current active link is `http://localhost/luya-project/public_html/de/asfasdfasdfasdf/moduel-iin-seite3/foo-modul-param` where `foo-modul-param` is a param this
     * function will remove the module params and isolate the active link.
     * 
     * @param unknown $link
     * @param unknown $parts
     *
     * @return string|bool
     */
    public function isolateLinkSuffix($link)
    {
        $parts = explode('/', $link);
        $parts[] = '__FIRST_REMOVAL'; // @todo remove

        while (array_pop($parts)) {
            $match = implode('/', $parts);
            if ($this->findOneByArguments(['url' => $match,  'show_hidden' => true])) {
                return $match;
            }
        }

        return false;
    }

    /**
     * will return the opposite value of isolateLinkSuffix (module parameters e.g.).
     *
     * @param unknown $links
     */
    public function isolateLinkAppendix($fullUrl, $suffix)
    {
        return substr($fullUrl, strlen($suffix) + 1);
    }

    public function getDefaultLink()
    {
        $link = $this->findOne(['is_home' => 1, 'show_hidden' => true]);
        /*
        $cat = Cat::getDefault();
        $link = $this->findOneByArguments(['nav_id' => $cat['default_nav_id'], 'show_hidden' => true, 'lang' => Yii::$app->composition->getKey('langShortCode')]);
        */
        return $link['url'];
    }
}
