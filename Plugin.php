<?php
/**
 * 搜索增强 SearchPlus：让 Typecho 的站内搜索「搜得准」
 *
 * 与轻言是松耦合：装了轻言（`Qingyan_Model` 在）就顺带在结果页下面列一组轻言命中，
 * 没装就只列文章 —— 本插件不依赖轻言。
 *
 * @package SearchPlus
 * @author  zizdog
 * @version 1.0.3
 * @link    https://zizdog.com
 */

namespace {

    if (!defined('__TYPECHO_ROOT_DIR__')) {
        exit;
    }

    require_once __DIR__ . '/compat.php';
    require_once __DIR__ . '/Engine.php';
    require_once __DIR__ . '/Search.php';

    if (class_exists('SearchPlus_Plugin_Base', false)) {
        return;
    }

    class SearchPlus_Plugin_Base implements Typecho_Plugin_Interface
    {
        /** @var string */
        const VERSION = '1.0.3';

        /** 跳板路由名 */
        const ROUTE_JUMP = 'searchplus';

        /** 跳板路由地址（精确路径，不会与核心 /search/关键词/ 抢） */
        const ROUTE_URL = '/search';

        /** @var array 配置缓存 */
        private static $optCache = array();

        /** @var bool 结果页里那段附加内容是否已经输出过 */
        private static $extraDone = false;

        /** @var string 本次请求解码出来的原文关键词 */
        private static $keyword = NULL;

        /* ============================================================== */
        /* 启停                                                            */
        /* ============================================================== */

        /**
         * 启用
         *
         * @return string
         */
        public static function activate()
        {
            self::installRoute();
            self::installHooks();

            return _t('搜索增强已启用。请到主题模板里把搜索框的 action 指到 ')
                . '<code>/search/</code>' . _t('（未启用时它会退回原生搜索，不会 404）。');
        }

        /**
         * 插件是否**已启用**
         *
         * 【为什么不能用 class_exists】类存在只说明文件被加载过（拷进去就可能成立），
         * 不代表插件在后台启用过 —— 主题若拿 `class_exists()` 当判据，
         * 就会把搜索框指向一个**并不存在**的 `/search/` 路由（搜索结果页变成 404/异常页）。
         *
         * 【键名约定（Typecho 1.3 实测）】`Plugin::export()['activated']` 的 key 是
         * **插件名（目录名）**，不是类名 —— 核心 `Plugin::activate(string $pluginName)`
         * 直接拿目录名做键：
         *     self::$plugin['activated'][$pluginName] = self::$tmp;
         * 所以 1.3 上判断要写 `activated['SearchPlus']`；
         * 老版本（1.2 时代）的惯例是 `SearchPlus_Plugin`，这里两个都认。
         *
         * @return bool
         */
        public static function isActive()
        {
            try {
                $export = Typecho_Plugin::export();
                $list   = (isset($export['activated']) && is_array($export['activated']))
                    ? $export['activated']
                    : array();
                $dir    = basename(dirname(__FILE__));   // SearchPlus

                return isset($list[$dir]) || isset($list[$dir . '_Plugin']);
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 插件是否**真的可用**（已启用 + 路由已注册）
         *
         * 主题应该用这个，而不是 `class_exists()`：
         * 只看「启用」还不够 —— 路由是**启用那一刻**写进 `routingTable` 的，
         * 之后若重建过路由表（例如保存「永久链接」设置），
         * 就会出现「插件是启用的、`/search` 却不存在」的状态，
         * 搜索框照样会把用户送进死路（Typecho 会抛 Router\Exception，
         * 开着 debug 时更会返回 200 的错误页，PJAX 只看到「没有 #main」）。
         *
         * @return bool
         */
        public static function isReady()
        {
            if (!self::isActive()) {
                return false;
            }

            try {
                return null !== Typecho_Router::get(self::ROUTE_JUMP);
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 禁用
         *
         * @return string
         */
        public static function deactivate()
        {
            try {
                Helper::removeRoute(self::ROUTE_JUMP);
            } catch (Throwable $e) {
                // 本来就不存在时忽略
            }

            return _t('搜索增强已停用，搜索恢复为 Typecho 原生行为。');
        }

        /**
         * 注册跳板路由
         *
         * 【两个细节都是踩过的坑】
         *   · `Helper::addRoute()` 是「追加」语义：若同名旧路由还在表里，
         *     旧定义会盖住新定义 → 必须先按名字移除再添加；
         *   · 第 5 个参数 `'index'` 表示插到首页路由之后（即所有核心路由之前），
         *     保证 `/search` 由我们接住。
         *
         * @return void
         */
        protected static function installRoute()
        {
            try {
                Helper::removeRoute(self::ROUTE_JUMP);
            } catch (Throwable $e) {
                // 忽略
            }

            Helper::addRoute(self::ROUTE_JUMP, self::ROUTE_URL, 'SearchPlus_Search', 'dispatch', 'index');
        }

        /**
         * 注册钩子
         *
         * 【search】只要这个钩子上有回调，核心就会跳过它自己那套搜索条件
         *          （`Typecho\Plugin::call()` 见到回调就把 signal 置真）；
         * 【searchHandle】核心唯一会把 `$select` 交出来的地方 —— 我们在这里加条件；
         * 【footer】给「不在模板里调用 renderExtra()」的主题兜底（本站主题会直接调，
         *          两条路都只输出一次）。
         *
         * @return void
         */
        protected static function installHooks()
        {
            Typecho_Plugin::factory('Widget_Archive')->search = array('SearchPlus_Plugin', 'onSearch');
            Typecho_Plugin::factory('Widget_Archive')->searchHandle = array('SearchPlus_Plugin', 'onSearchHandle');
            Typecho_Plugin::factory('Widget_Archive')->footer = array('SearchPlus_Plugin', 'onFooter');
        }

        /* ============================================================== */
        /* 钩子回调                                                        */
        /* ============================================================== */

        public static function onSearch($keywords = '', $archive = NULL)
        {
            self::$keyword = self::readKeyword($archive);

            if (!is_object($archive) || !method_exists($archive, 'query')) {
                return;
            }

            $select = SearchPlus_Engine::buildSelect($archive, self::$keyword);

            if (NULL === $select) {
                return;
            }

            /*
             * 总数要在 `page()` 之前算：`size()` 是把这条查询改成 COUNT 再跑，
             * 带上 LIMIT 就只数当前页了。
             */
            if (method_exists($archive, 'setCountSql')) {
                try {
                    $archive->setCountSql(clone $select);
                } catch (Throwable $e) {
                    // 忽略：分页条显示不出来不影响结果
                }
            }

            // 当前页从请求里取（`/search/x/page/2/` 的路由参数就是 page）
            $page = 1;
            $size = 20;

            try {
                $page = max(1, intval($archive->request->get('page', 1)));
                $size = max(1, intval($archive->parameter->pageSize));
            } catch (Throwable $e) {
                // 用上面的默认值
            }

            $select->order('table.contents.created', SP_SORT_DESC)->page($page, $size);

            // 压栈：主题照常 $this->next() 渲染。核心此刻已因 $hasPushed 直接返回，
            // 所以这是本次请求唯一一次查询。
            $archive->query($select);

        }

        public static function onSearchHandle($archive = NULL, $select = NULL)
        {
            if (!is_object($archive)) {
                return;
            }

            $keyword = self::readKeyword($archive);

            /*
             * 这里**只做一件事**：把标题/面包屑换成原文。
             *
             * 时序原因：核心在 `search` 钩子（我们查库的地方）之后，才用
             * **被过滤过的关键词**（对我们就是 `sp...` 那串 hex）设置
             * `archiveTitle` / `archiveKeywords`，所以必须等到这个钩子再覆盖，
             * 否则页面上会显示一串十六进制。
             *
             * 查询与分页总数都不在这里做（见 onSearch）：注册了 `search` 钩子后
             * 核心会直接 return，交到手上的 `$select` 根本不会被执行。
             */
            if ('' === $keyword || !method_exists($archive, 'setArchiveKeywords')) {
                return;
            }

            try {
                $archive->setArchiveKeywords($keyword);
                $archive->setArchiveTitle($keyword);
            } catch (Throwable $e) {
                // 忽略：标题显示不出来也不该影响搜索
            }
        }

        /**
         * `footer` 钩子：主题没显式调用时的兜底
         *
         * @param Widget_Archive $archive
         * @return void
         */
        public static function onFooter($archive = NULL)
        {
            self::renderExtra($archive);
        }

        /* ============================================================== */
        /* 结果页附加内容（轻言命中，松耦合）                              */
        /* ============================================================== */

        /**
         * 在搜索结果页追加一段「轻言里也有 N 条」
         *
         * 主题模板里显式调用（推荐，位置最好，且**不需要重新启用插件**）：
         *
         *     <?php if (class_exists('SearchPlus_Plugin')) SearchPlus_Plugin::renderExtra($this); ?>
         *
         * 没装/停用轻言时这里什么都不输出（本插件不依赖它）。
         *
         * @param Widget_Archive $archive
         * @return void
         */
        public static function renderExtra($archive = NULL)
        {
            if (self::$extraDone) {
                return;
            }

            if (is_object($archive) && method_exists($archive, 'is')) {
                try {
                    if (!$archive->is('search')) {
                        return;
                    }
                } catch (Throwable $e) {
                    // 判断不了就往下走，靠关键词兜底
                }
            }

            $keyword = self::readKeyword($archive);

            if ('' === $keyword) {
                return;
            }

            $cut  = self::isTruncated();
            $rows = self::qingyanRows($keyword);

            if (empty($rows) && !$cut) {
                return;
            }

            self::$extraDone = true;

            $total = self::qingyanTotal($keyword);
            $front = self::qingyanFrontUrl();
            $esc   = function ($value) {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            };

            // 结果页是主题渲染的，插件样式默认不在 —— 真要输出时才带上
            echo '<link rel="stylesheet" href="' . $esc(self::assetUrl('search.css')) . '">' . "\n";
            ?>
<div class="sp-extra" id="sp-extra">
    <?php if ($cut) { ?>
    <p class="sp-extra-note">关键词太长，只用了前一部分来搜索：<b><?php echo $esc($keyword); ?></b></p>
    <?php } ?>
    <?php if (!empty($rows)) { ?>
    <div class="sp-extra-head">
        <span class="sp-extra-title">轻言里也有 <b><?php echo intval($total); ?></b> 条相关</span>
        <a class="sp-extra-more" href="<?php echo $esc(self::qingyanSearchUrl($keyword, $front)); ?>">在轻言里看<?php echo $total > count($rows) ? '全部' : ''; ?> &rarr;</a>
    </div>
    <ul class="sp-extra-list">
        <?php foreach ($rows as $row) { ?>
        <li class="sp-extra-item">
            <a class="sp-extra-link" href="<?php echo $esc($front . '#qy-' . intval($row['id'])); ?>">
                <span class="sp-extra-time"><?php echo $esc(self::timeAgo($row['created'])); ?></span>
                <span class="sp-extra-text"><?php echo self::snippet($row['content'], $keyword); ?></span>
            </a>
        </li>
        <?php } ?>
    </ul>
    <?php } ?>
</div>
            <?php
        }

        /* ============================================================== */
        /* 配置                                                            */
        /* ============================================================== */

        /**
         * 默认配置
         *
         * @return array
         */
        public static function defaults()
        {
            return array(
                // 结果页里是否顺带列出轻言命中（需要启用轻言插件；没装时自动跳过）
                'qingyanExtra' => '1',
                'qingyanLimit' => '5',
            );
        }

        /**
         * 「怎么用」说明块（渲染在设置页最上面）
         *
         * 用本站主题 GardenWalk 的真实写法举例，并且写成判断式 —— 这样用户
         * 可以直接抄，而且插件停用时搜索框会自动回落到 Typecho 原生搜索。
         *
         * @return string HTML
         */
        public static function usageHelpHtml()
        {
            $esc = function ($code) {
                return htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8');
            };

            $snippetForm = <<<'CODE'
<?php
// ① 搜索框的目标地址：插件真的可用才走 /search/，否则回到原生 ?s= 搜索。
//    判据用 isReady()（已启用 + 路由已注册），不要只用 class_exists()——
//    类能加载不代表启用过；就算启用了，路由也可能因重建路由表而丢失，
//    那时 /search/ 会 404（开 debug 时是 200 的错误页），搜索框就成了死路。
$searchAction = (class_exists('SearchPlus_Plugin') && SearchPlus_Plugin::isReady())
    ? rtrim($this->options->siteUrl, '/') . '/search/'
    : $this->options->siteUrl;
?>
<form method="get" role="search"
      action="<?php echo htmlspecialchars($searchAction, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="s" placeholder="搜索文章...">
    <button type="submit">搜索</button>
</form>
CODE;

            $snippetExtra = <<<'CODE'
<?php
// ②（可选）在搜索结果模板里追加「轻言命中」那一段。
//    本站 GardenWalk 是 index.php，放在文章列表与分页之后。
if (class_exists('SearchPlus_Plugin')) {
    SearchPlus_Plugin::renderExtra($this);
}
CODE;

            $snippetCheck = <<<'CODE'
搜索「信任 背叛」 → 以前 0 条，现在应该是 2 条
搜索「《信任》」  → 以前 18 条（书名号被丢掉），现在应该是 2 条
CODE;

            return '<div id="sp-help" class="sp-help">'
                . '<style>'
                . '#sp-help{--sp-bd:var(--ad-line,#e3e6ea);--sp-bg:var(--ad-surface-alt,rgba(128,128,128,.06));'
                . '--sp-fg:var(--ad-fg,#444);--sp-muted:var(--ad-fg-faint,#999);--sp-ac:var(--ad-accent,#467b96);'
                . 'border:1px solid var(--sp-bd);border-radius:6px;padding:14px 16px;margin:0 0 18px;'
                . 'background:var(--sp-bg);color:var(--sp-fg);line-height:1.75;font-size:13px}'
                . '#sp-help h3{margin:0 0 10px;font-size:15px;color:var(--sp-fg)}'
                . '#sp-help h4{margin:16px 0 6px;font-size:13px;color:var(--sp-ac)}'
                . '#sp-help p{margin:6px 0}'
                . '#sp-help ul{margin:6px 0 6px 1.2em;padding:0}'
                . '#sp-help li{margin:2px 0}'
                . '#sp-help pre{margin:6px 0;padding:10px 12px;overflow-x:auto;'
                . 'border:1px solid var(--sp-bd);border-radius:4px;background:var(--ad-surface,#fff);'
                . 'color:var(--sp-fg);font-size:12px;line-height:1.6}'
                . '#sp-help code{font-family:Menlo,Monaco,Consolas,monospace}'
                . '#sp-help .sp-help-muted{color:var(--sp-muted)}'
                . '</style>'
                . '<h3>怎么用（第 ① 步必做，第 ② 步可选）</h3>'
                . '<p class="sp-help-muted">示例直接用本站主题 <b>GardenWalk</b> 的真实写法。'
                . '写成判断式的好处：<b>插件停用/未启用时会自动回落到 Typecho 原生搜索，不会 404</b>。</p>'

                . '<h4>① 把主题搜索框的 action 指到本插件（必做）</h4>'
                . '<p>主题的搜索框模板里（GardenWalk 是 <code>header.php</code>）这样写：</p>'
                . '<pre><code>' . $esc($snippetForm) . '</code></pre>'
                . '<ul>'
                . '<li>输入框的 <code>name</code> 保持 <code>s</code>（和 Typecho 原生一致，不用改）。</li>'
                . '<li><code>/search/</code> 是<b>精确路径</b>，与核心的 <code>/search/关键词/</code> 不冲突，两者可以并存。</li>'
                . '<li>只要 <code>action</code> 指过来，搜索就会用本插件的精确引擎（多词 AND、符号不过滤、顺序无关）。</li>'
                . '</ul>'

                . '<h4>②（可选）在搜索结果模板里追加「轻言」命中</h4>'
                . '<p>放在文章列表与分页<b>之后</b>（GardenWalk 是 <code>index.php</code>）：</p>'
                . '<pre><code>' . $esc($snippetExtra) . '</code></pre>'
                . '<ul>'
                . '<li>装了「轻言 Qingyan」才有那一段；没装、或上面选「不列」，这一行什么都不输出。</li>'
                . '<li><b>不写这一行也行</b>：插件注册了 <code>Widget_Archive:footer</code> 钩子兜底，'
                . '只是位置会落在页脚附近。</li>'
                . '</ul>'

                . '<h4>③ 怎么验证装对了</h4>'
                . '<pre><code>' . $esc($snippetCheck) . '</code></pre>'
                . '<p class="sp-help-muted">另外：本插件的<b>路由与钩子是在「启用插件」时写入配置的</b>，'
                . '以后升级插件若发现搜索没走插件路由，把插件「停用 → 启用」一次即可。</p>'
                . '</div>';
        }

        /**
         * 跨版本取 HTML 布局块类名（1.2 下划线 / 1.3 命名空间）
         *
         * @return string 找不到时返回空串
         */
        protected static function layoutClass()
        {
            foreach (array('Typecho\\Widget\\Helper\\Layout', 'Typecho_Widget_Helper_Layout') as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return '';
        }

        /**
         * 确保 `plugin:SearchPlus` 配置行存在、且是合法配置
         *
         * 【为什么必须有】Typecho 1.3 的插件设置页会**直接**读这一行
         * （`Widget\Plugins\Config::config()` → `Options->plugin('SearchPlus')`），
         * 行缺失或值为空就当场抛 `Plugin\Exception`——
         * 异常发生在核心里，插件自己的 try/catch 兜不住，
         * 表现就是后台报「插件SearchPlus的配置信息没有找到」。
         *
         * 而「禁用 → 重新启用」也不一定能救回来：当这一行**存在但值为空**时，
         * 核心 `Edit::configPlugin()` 会走 `json_decode('') === null` →
         * `array_merge(null, $settings)` → PHP 8 直接 TypeError，
         * 启用流程中断，值永远补不上（用户看到的就是「重启也没用」）。
         *
         * 所以这里自己把这一行补齐，并且**同时**写内存里的 Options 副本 ——
         * `Widget::__set()` 写的正是 `$this->row['plugin:SearchPlus']`，
         * 也就是核心后面那次读要用的键，于是**同一次请求**里设置页就能打开，
         * 不用刷新第二次。
         *
         * @return bool true = 本来就没问题或已修好；false = 数据库写不进去（已做内存兜底）
         */
        public static function repairConfigRow()
        {
            $name = 'plugin:SearchPlus';
            $json = json_encode(self::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $needFix = null;

            try {
                $db   = sp_db();
                $rows = $db->fetchAll($db->select()->from('table.options')->where('name = ?', $name));

                if (empty($rows)) {
                    $db->query($db->insert('table.options')->rows(array(
                        'name'  => $name,
                        'user'  => 0,
                        'value' => $json,
                    )));
                    $needFix = $json;
                } else {
                    foreach ($rows as $row) {
                        $current = (string) $row['value'];

                        // 兼容两种格式：核心写 JSON；老版本/别处可能写 PHP serialize
                        $decoded = (0 === strpos($current, 'a:'))
                            ? @unserialize($current)
                            : json_decode($current, true);

                        if (!is_array($decoded) || array() === $decoded) {
                            $db->query(
                                $db->update('table.options')
                                    ->rows(array('value' => $json))
                                    ->where('name = ?', $name)
                                    ->where('user = ?', $row['user'])
                            );
                            if (null === $needFix) {
                                $needFix = $json;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // 数据库动不了也要让本次请求能打开设置页 → 内存兜底
                $needFix = $json;
            }

            if (null !== $needFix) {
                try {
                    Helper::options()->{$name} = $needFix;
                } catch (Throwable $e) {
                    // 拿不到 Options 就算了，下次请求会从库里读到
                }
            }

            return true;
        }

        /**
         * 插件配置表单
         *
         * @param mixed $form
         * @return void
         */
        public static function config($form)
        {
            /*
             * 【第一步】先把配置行补齐。
             * 核心在 config() 之后会立刻 `Options->plugin('SearchPlus')` 取配置，
             * 那一行缺失/为空就直接抛异常（插件 catch 不到）。这里补库 + 补内存，
             * 保证同一次请求里那次读能拿到值。
             */
            self::repairConfigRow();

            /*
             * 最上面先放「怎么用」——用户装完插件最需要的就是这几行接线代码，
             * 示例直接用本站主题（GardenWalk）的真实写法，并写成**判断式**：
             * 插件没启用时自动回落到 Typecho 原生搜索，不会 404。
             */
            $helpLayout = self::layoutClass();

            if ('' !== $helpLayout) {
                try {
                    $help = new $helpLayout();
                    $help->html(self::usageHelpHtml());
                    $form->addItem($help);
                } catch (Throwable $e) {
                    // 布局类不可用时忽略，不影响表单本身
                }
            }

            $clsRadio = self::elementClass('Radio');
            $extra = new $clsRadio(
                'qingyanExtra',
                array('1' => _t('列出'), '0' => _t('不列')),
                '1',
                _t('搜索结果页里附带「轻言」命中'),
                _t('装了轻言插件时，搜索结果页（文章列表下面）会另列一组轻言里的命中。'
                    . '<br>本插件**不依赖**轻言：没装轻言、或这里选「不列」，就只显示文章结果。')
            );
            $form->addInput($extra);

            $clsText = self::elementClass('Text');
            $limit = new $clsText(
                'qingyanLimit',
                NULL,
                '5',
                _t('轻言那组最多显示几条'),
                _t('默认 5 条，后面跟一个「在轻言里看全部 →」的链接。')
            );
            $form->addInput($limit);
        }

        /**
         * 个人配置（本插件没有）
         *
         * @param mixed $form
         * @return void
         */
        public static function personalConfig($form)
        {
        }

        /**
         * 保存配置
         *
         * @param mixed $settings
         * @param bool  $isInit
         * @return mixed
         */
        public static function configHandle($settings, $isInit = false)
        {
            /*
             * 【回填阶段必须返回假值】核心的启用流程是：
             *     if ($options && !$configHandle($pluginName, $options, true)) {
             *         configPlugin($pluginName, $options);      // ← 写默认值
             *     }
             * 也就是说：插件在这里返回「真」的话，核心就**不写**默认配置了。
             * 结果是设置页打不开（`Config::config()` 拿不到配置行直接 500）——
             * 我第一版用 CLI 启用时就踩了这个坑（返回了数组 = 真值）。
             */
            if ($isInit) {
                /*
                 * 回填阶段：顺手把「空行/坏行」修成合法 JSON 再返回假值。
                 * 不修的话核心下面 `configPlugin()` 会对空值做
                 * `array_merge(json_decode('') = null, …)` → PHP 8 TypeError，
                 * 启用流程当场中断 ——「禁用→重新启用无效」就是这么来的。
                 */
                self::repairConfigRow();

                return false;
            }

            $clean = self::sanitize($settings);

            try {
                // 用核心自己的写配置方法：它同时处理「插入」与「合并更新」
                Widget\Plugins\Edit::configPlugin('SearchPlus', $clean);
                self::$optCache = array();
            } catch (Throwable $e) {
                // 自己写失败就让核心保存原始值，总比配置丢了强
                return false;
            }

            return true;
        }

        /**
         * 清洗配置（只留本版本声明的键）
         *
         * 【为什么要裁剪】旧版本残留的键会让设置页里
         * `$form->getInput($key)` 返回 null，然后 `->value()` 直接 fatal。
         *
         * @param mixed $settings
         * @return array
         */
        public static function sanitize($settings)
        {
            $settings = is_array($settings) ? $settings : (array) $settings;
            $defaults = self::defaults();
            $clean    = array();

            $clean['qingyanExtra'] = (isset($settings['qingyanExtra'])
                && '0' === strval($settings['qingyanExtra'])) ? '0' : '1';

            $limit = isset($settings['qingyanLimit']) ? intval($settings['qingyanLimit']) : 5;
            $clean['qingyanLimit'] = (string) max(1, min(20, $limit > 0 ? $limit : 5));

            // 其它未知键一律丢弃（见上面的说明）
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $clean)) {
                    $clean[$key] = $value;
                }
            }

            return $clean;
        }

        /* ============================================================== */
        /* 内部工具                                                        */
        /* ============================================================== */

        /**
         * 读配置（拿不到就返回默认值，绝不抛异常）
         *
         * @param string $key
         * @param mixed  $default
         * @return mixed
         */
        public static function opt($key, $default = NULL)
        {
            if (array_key_exists($key, self::$optCache)) {
                return self::$optCache[$key];
            }

            $value = NULL;

            try {
                $config = Helper::options()->plugin('SearchPlus');

                if (isset($config[$key]) && '' !== $config[$key] && NULL !== $config[$key]) {
                    $value = $config[$key];
                }
            } catch (Throwable $e) {
                $value = NULL;
            }

            if ((NULL === $value || '' === $value) && array_key_exists($key, self::defaults())) {
                $value = self::defaults()[$key];
            }

            self::$optCache[$key] = $value;

            return (NULL === $value || '' === $value) ? $default : $value;
        }

        /**
         * 读布尔配置
         *
         * @param string $key
         * @param bool   $default
         * @return bool
         */
        public static function boolOpt($key, $default = false)
        {
            $value = self::opt($key, $default ? '1' : '0');

            return '1' === strval($value) || true === $value;
        }

        /**
         * 读取**未经核心过滤**的原文关键词
         *
         * 【关键】搜索页是 `/search/<关键词>/`，关键词在**路由参数**里，
         * 而只有 `Typecho\Widget\Request`（底层 Request 用路由参数 proxy 过的）
         * 才读得到；直接 `Typecho_Request::getInstance()->get('keywords')`
         * 一定是空串。另外这里**不能**再调 `filter('url','search')` ——
         * 那正是我们要绕开的过滤器。
         *
         * @param Widget_Archive $archive
         * @return string
         */
        public static function readKeyword($archive = NULL)
        {
            if (NULL !== self::$keyword) {
                return self::$keyword;
            }

            $raw = '';

            if (is_object($archive) && isset($archive->request) && is_object($archive->request)) {
                try {
                    $raw = (string) $archive->request->get('keywords', '');
                } catch (Throwable $e) {
                    $raw = '';
                }
            }

            // 兜底：没拿到 widget 时看 query（还没跳到 /search/ 之前的老流程）
            if ('' === $raw) {
                try {
                    $request = Typecho_Request::getInstance();

                    foreach (array('keywords', 's', 'q') as $key) {
                        $value = trim((string) $request->get($key, ''));

                        if ('' !== $value) {
                            $raw = $value;
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    $raw = '';
                }
            }

            if ('' === $raw) {
                self::$keyword = '';

                return '';
            }

            $raw = preg_replace('/\s+/u', ' ', strip_tags($raw));
            $raw = is_string($raw) ? trim($raw) : '';

            self::$keyword = SearchPlus_Engine::decode($raw);

            return self::$keyword;
        }

        /**
         * 本次请求的关键词是否被截断过（跳板会带 `sp_cut=1`）
         *
         * @return bool
         */
        protected static function isTruncated()
        {
            try {
                return '1' === (string) Typecho_Request::getInstance()->get('sp_cut', '');
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 轻言的命中（轻言不在就返回空数组 —— 本插件不依赖它）
         *
         * @param string $keyword
         * @return array
         */
        protected static function qingyanRows($keyword)
        {
            if (!self::boolOpt('qingyanExtra', true) || !self::hasQingyan()) {
                return array();
            }

            try {
                return Qingyan_Model::search($keyword, intval(self::opt('qingyanLimit', 5)));
            } catch (Throwable $e) {
                return array();
            }
        }

        /**
         * 轻言的命中条数
         *
         * @param string $keyword
         * @return int
         */
        protected static function qingyanTotal($keyword)
        {
            if (!self::hasQingyan() || !method_exists('Qingyan_Model', 'searchCount')) {
                return 0;
            }

            try {
                return intval(Qingyan_Model::searchCount($keyword));
            } catch (Throwable $e) {
                return 0;
            }
        }

        /**
         * 轻言在不在
         *
         * @return bool
         */
        protected static function hasQingyan()
        {
            return class_exists('Qingyan_Model') && method_exists('Qingyan_Model', 'search');
        }

        /**
         * 轻言前台地址
         *
         * @return string
         */
        protected static function qingyanFrontUrl()
        {
            if (class_exists('Qingyan_Plugin') && method_exists('Qingyan_Plugin', 'frontUrl')) {
                try {
                    return (string) Qingyan_Plugin::frontUrl();
                } catch (Throwable $e) {
                    // 忽略，下面兜底
                }
            }

            return (string) Helper::options()->siteUrl;
        }

        /**
         * 「在轻言里看全部」的地址
         *
         * @param string $keyword
         * @param string $front
         * @return string
         */
        protected static function qingyanSearchUrl($keyword, $front)
        {
            return $front . (false === strpos($front, '?') ? '?' : '&') . 'q=' . urlencode($keyword);
        }

        /**
         * 摘要：优先用轻言那边的 snippet()（关键词附近截 + 高亮），没有就本地截
         *
         * @param string $text
         * @param string $keyword
         * @return string
         */
        protected static function snippet($text, $keyword)
        {
            if (self::hasQingyan() && method_exists('Qingyan_Model', 'snippet')) {
                try {
                    return Qingyan_Model::snippet($text, $keyword, 70);
                } catch (Throwable $e) {
                    // 落到下面兜底
                }
            }

            $plain = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $text)));
            $plain = is_string($plain) ? $plain : '';
            $cut   = function_exists('mb_substr') ? mb_substr($plain, 0, 70, 'UTF-8') : substr($plain, 0, 70);

            return htmlspecialchars((string) $cut, ENT_QUOTES, 'UTF-8');
        }

        /**
         * 「多久以前」：优先用轻言的 timeAgo()，没有就退回日期
         *
         * @param int $timestamp
         * @return string
         */
        protected static function timeAgo($timestamp)
        {
            if (self::hasQingyan() && method_exists('Qingyan_Model', 'timeAgo')) {
                try {
                    return Qingyan_Model::timeAgo($timestamp);
                } catch (Throwable $e) {
                    // 落到下面
                }
            }

            return date('Y-m-d H:i', intval($timestamp));
        }

        /**
         * 插件资源地址（带 mtime 破缓存）
         *
         * @param string $file
         * @return string
         */
        public static function assetUrl($file)
        {
            $file = ltrim((string) $file, '/');
            $url  = rtrim(Helper::options()->pluginUrl, '/') . '/SearchPlus/assets/' . $file;
            $path = __TYPECHO_ROOT_DIR__ . '/usr/plugins/SearchPlus/assets/' . rawurldecode($file);

            if (is_file($path)) {
                $url .= '?v=' . filemtime($path);
            }

            return $url;
        }

        /**
         * 跨版本取表单元素类名
         *
         * @param string $type
         * @return string
         */
        protected static function elementClass($type)
        {
            $candidates = array(
                'Typecho\\Widget\\Helper\\Form\\Element\\' . $type,
                'Typecho_Widget_Helper_Form_Element_' . $type,
            );

            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return class_exists('Typecho_Widget_Helper_Form_Element_Text')
                ? 'Typecho_Widget_Helper_Form_Element_Text'
                : 'Typecho\\Widget\\Helper\\Form\\Element\\Text';
        }
    }

    /**
     * 最终对外类名（Typecho 按「目录_Plugin」解析插件入口）
     */
    if (!class_exists('SearchPlus_Plugin', false)) {
        class SearchPlus_Plugin extends SearchPlus_Plugin_Base
        {
        }
    }
}
