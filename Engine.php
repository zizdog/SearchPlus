<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';

/**
 * 搜索增强 - 引擎
 *
 * 负责两件事：
 *
 * 【一】关键词的「过滤器安全编码」
 *
 * Typecho 会在两处改写搜索关键词（都在核心 `Widget\Archive` 里）：
 *
 *   · `Common::safeUrl()`   —— 开头的 str_replace 就把空格/Tab/换行**删掉**，
 *                              而且它几个按字节处理的正则会在「中文后面跟着 ASCII」
 *                              时把多字节字符弄坏（实测：`信任_20背叛` → `信任_20?__?__`）
 *   · `Common::slugName()`  —— 只保留 `[\w_-]`（书名号、引号、问号…全丢），
 *                              **会吃掉开头的 `_`**，而且**截断到 128 字符**
 *
 * 结果：`信任 背叛` → `信任背叛`（两词粘成一个不存在的词 → 0 结果）、
 *       `《信任》` → `信任`（范围被放大）。核心不让改，我们换条路：
 * 把原文编码成只含 `[0-9A-Za-z]` 的词元放进 URL，过滤器就动不了它，
 * 取回来自己解码，用**原文**去查。词元里没有空格、没有 `-`、没有 `%`，
 * 所以核心拿它拼分页链接时也是原样带过去 —— **翻页不会跑偏**。
 *
 * 编码：`[0-9A-Za-z]` 与（能安全通过过滤器的）中文原样保留；其余每个字节写成两位十六进制。
 *   · 纯中文 / 纯字母数字  → 词元就是它自己（`/search/信任/`），URL 好看；
 *   · 需要转义时           → `sp` + 全 hex（`/search/spe4bfa1e4bbbb20e8838c.../`）。
 * 「能不能保留中文」不靠猜：`isFilterSafe()` 会拿核心那两个过滤器实跑一遍，
 * 通不过就退化成纯 ASCII，保证换台机器也不会坏。
 *
 * 【二】把「查什么」换成我们的条件（多词 AND、顺序无关、符号不过滤）
 *
 * 页面仍然由核心的 Archive + 主题模板渲染，我们只往它的 `$select` 里加条件
 * （见 applyConditions()），所以**主题样式一点没变**。
 *
 * @package SearchPlus
 * @version 1.0.1
 */
class SearchPlus_Engine
{
    /**
     * 词元前缀
     *
     * 【为什么需要】`slugName('_abc')` 会把**开头的下划线吃掉**（实测 `_abc` → `abc`），
     * 所以词元不能以下划线开头；字母前缀顺便让「这是我们编的词元」变得可识别。
     */
    const PREFIX = 'sp';

    /** 词元长度上限：`slugName()` 会把关键词截到 128 字符，我们必须留余量 */
    const MAX_TOKEN = 128;

    /** 原文最多多少**字节**（hex 是 2 字符/字节，加前缀后仍在 128 以内） */
    const MAX_BYTES = 60;

    /** LIKE 匹配时单个词最多多少字 */
    const MAX_WORD = 32;

    /** LIKE 匹配时最多拆几个词 */
    const MAX_WORDS = 6;

    /* ------------------------------------------------------------------ */
    /* 编码 / 解码                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * 原文 → 词元
     *
     * @param string $raw
     * @return string
     */
    public static function encode($raw)
    {
        $raw = (string) $raw;

        if ('' === $raw) {
            return '';
        }

        /*
         * ① 漂亮词元：纯中文 / 纯字母数字（没有任何需要转义的字符）
         *    并且**实测**能原样通过核心的过滤器 —— 那就直接用原文当词元。
         */
        $pretty = self::encodeWith($raw, true, true);

        if ($pretty === $raw && self::isFilterSafe($pretty)) {
            return $pretty;
        }

        /*
         * ② 否则**整串**按字节转义成纯 ASCII（`sp` + hex）：空白、标点、以及
         *    「中文后面跟 ASCII」这些会被过滤器弄坏的情况，全都碰不到它。
         *
         *    注意这里连字母数字也一起转义 —— 不然词元里会混着「原样字符」与
         *    「hex」，解码时无法区分（实测：`a_b` → `spa5fb` 会被误解成字节 a5 fb）。
         */
        return self::PREFIX . self::encodeWith($raw, false, false);
    }

    /**
     * 词元 → 原文
     *
     * 不是我们编的词元（用户手打的老地址、或核心过滤后的普通关键词）原样返回，
     * 所以 `/search/信任/` 这种老链接照常可用。
     *
     * @param string $token
     * @return string
     */
    public static function decode($token)
    {
        $token = (string) $token;

        if ('' === $token) {
            return '';
        }

        if (0 === strpos($token, self::PREFIX)) {
            $hex = substr($token, strlen(self::PREFIX));

            // 偶数长度 + 全是十六进制，才算我们的词元
            if ('' !== $hex && 0 === strlen($hex) % 2 && ctype_xdigit($hex)) {
                $raw = @hex2bin($hex);

                // 还要能还原成合法 UTF-8，免得把用户手打的「spab」之类解成乱码
                if (false !== $raw && self::isUtf8($raw)) {
                    return $raw;
                }
            }
        }

        return $token;
    }

    /**
     * 把过长的关键词截到编码上限以内（按 UTF-8 字符边界截，不会截出半个汉字）
     *
     * @param string $raw
     * @return array array($cut, $truncated)
     */
    public static function clamp($raw)
    {
        $raw = (string) $raw;

        if (strlen($raw) <= self::MAX_BYTES) {
            return array($raw, false);
        }

        $cut = function_exists('mb_strcut')
            ? mb_strcut($raw, 0, self::MAX_BYTES, 'UTF-8')
            : substr($raw, 0, self::MAX_BYTES);

        return array($cut, true);
    }

    /**
     * 运行时自检：词元经过核心那两段过滤器之后还是不是它自己
     *
     * 【为什么必须实跑】`\w` 是否匹配中文、多字节正则会怎么处理，取决于
     * mbstring / Oniguruma 的版本与编译选项，光靠推断不保险。
     * 一样 → 可以安全放进 URL（分页链接也能原样带）；
     * 不一样 → 调用方会退化成纯 ASCII 词元。
     *
     * @param string $token
     * @return bool
     */
    public static function isFilterSafe($token)
    {
        $token = (string) $token;

        if ('' === $token) {
            return true;
        }

        try {
            $filtered = Typecho_Common::filterSearchQuery(Typecho_Common::safeUrl($token));
        } catch (Throwable $e) {
            return false;
        }

        return $filtered === $token;
    }

    /**
     * 编码实现
     *
     * @param string $raw       原文
     * @param bool   $keepAlnum 是否原样保留字母数字
     * @param bool   $keepCjk   是否原样保留中文（让 URL 好看些）
     * @return string
     */
    protected static function encodeWith($raw, $keepAlnum, $keepCjk)
    {
        $out = '';
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $char = $raw[$i];
            $ord  = ord($char);

            // 字母数字：只有「漂亮模式」才原样保留（见上面 encode() 的说明）
            if ($keepAlnum
                && (($ord >= 48 && $ord <= 57) || ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122))) {
                $out .= $char;
                continue;
            }

            // 多字节字符：中文/日文/韩文在 keepCjk 时原样保留，其余按字节转义
            if ($ord >= 0xC0) {
                $seq  = self::utf8Char($raw, $i);
                $size = strlen($seq);

                if ('' !== $seq) {
                    if ($keepCjk && self::isWideChar($seq)) {
                        $out .= $seq;
                    } else {
                        for ($k = 0; $k < $size; $k++) {
                            $out .= sprintf('%02x', ord($seq[$k]));
                        }
                    }

                    $i += $size - 1;
                    continue;
                }
            }

            $out .= sprintf('%02x', $ord);
        }

        return $out;
    }

    /**
     * 取一个完整的 UTF-8 字符（取不到返回空串）
     *
     * @param string $text
     * @param int    $offset
     * @return string
     */
    protected static function utf8Char($text, $offset)
    {
        $ord = ord($text[$offset]);

        if ($ord < 0xC0) {
            return $text[$offset];
        }

        $size = $ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : 2);

        if ($offset + $size > strlen($text)) {
            return '';
        }

        $seq = substr($text, $offset, $size);

        for ($i = 1; $i < $size; $i++) {
            if ((ord($seq[$i]) & 0xC0) !== 0x80) {
                return '';
            }
        }

        return $seq;
    }

    /**
     * 是不是宽字符（CJK / 假名 / 谚文）—— 决定要不要原样留在词元里
     *
     * @param string $char
     * @return bool
     */
    protected static function isWideChar($char)
    {
        if (!function_exists('mb_ord') || !function_exists('mb_check_encoding')) {
            return false;
        }

        if (!mb_check_encoding($char, 'UTF-8')) {
            return false;
        }

        $code = mb_ord($char, 'UTF-8');

        if (false === $code) {
            return false;
        }

        return ($code >= 0x3040 && $code <= 0x30FF)
            || ($code >= 0x3400 && $code <= 0x4DBF)
            || ($code >= 0x4E00 && $code <= 0x9FFF)
            || ($code >= 0xF900 && $code <= 0xFAFF)
            || ($code >= 0xAC00 && $code <= 0xD7AF);
    }

    /**
     * 合法 UTF-8？
     *
     * @param string $text
     * @return bool
     */
    protected static function isUtf8($text)
    {
        if (function_exists('mb_check_encoding')) {
            return (bool) mb_check_encoding($text, 'UTF-8');
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* 查询条件                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 把搜索词拆成关键词（空格 / 全角空格分隔）
     *
     * @param string $keyword
     * @return array
     */
    public static function words($keyword)
    {
        $keyword = trim((string) $keyword);

        if ('' === $keyword) {
            return array();
        }

        $parts = preg_split('/[\s\x{3000}]+/u', $keyword, self::MAX_WORDS + 1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : array();
        $words = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if ('' === $part) {
                continue;
            }

            $words[] = function_exists('mb_substr')
                ? mb_substr($part, 0, self::MAX_WORD, 'UTF-8')
                : substr($part, 0, self::MAX_WORD);
        }

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /**
     * 自己建一条搜索查询
     *
     * 【为什么要自己建】注册了 `search` 钩子之后，核心认为「插件已经把结果压好了」，
     * 于是 `Archive::execute()` 会在 `if ($hasPushed) { return; }` 处**直接返回、根本不查库**
     * （实测：钩子里条件加得对、页面却一条不剩）。所以接管搜索就得连查询一起做：
     * 自己建查询 → `setCountSql()` 给它算总数 → `query()` 压栈 → 主题照常 `$this->next()` 渲染。
     *
     * 基础条件与核心 `Archive::select()` / `searchHandle()` 同口径：
     * 状态（登录用户多一个自己的私密）、`created < now`、密码保护、`type = 'post'`；
     * 匹配部分换成我们的「多词 AND、顺序无关、LIKE 转义」。
     *
     * @param Widget_Archive $archive
     * @param string         $keyword 原文关键词
     * @return Typecho_Db_Query|null
     */
    public static function buildSelect($archive, $keyword)
    {
        try {
            $db = Typecho_Db::get();
        } catch (Throwable $e) {
            return NULL;
        }

        $select = $db->select('table.contents.*')->from('table.contents');

        /*
         * 登录状态（决定私密文章与加密文章的可见性，与核心一致）
         *
         * 【必须用全局取法】`$archive->user` / `$archive->options` 从 widget 外部
         * **取不到**：`Typecho\Widget::__get()` 只认 `___xxx()` 这类 getter，
         * 而 Archive 上并没有，于是静默变成 null —— 实测就因此把
         * `created < ?` 传成了 `created < 0`，一条都搜不出来。
         */
        $logged = false;
        $uid    = 0;

        try {
            $user = Widget_User::alloc();

            if (is_object($user) && method_exists($user, 'hasLogin')) {
                $logged = (bool) $user->hasLogin();
                $uid    = $logged ? intval($user->uid) : 0;
            }
        } catch (Throwable $e) {
            $logged = false;
        }

        if ($logged) {
            $select->where(
                'table.contents.status = ? OR (table.contents.status = ? AND table.contents.authorId = ?)',
                'publish',
                'private',
                $uid
            );
        } else {
            $select->where('table.contents.status = ?', 'publish');
        }

        // 定时发布：还没到时间的文章不出现（站点时区的当前时间）
        $now = time();

        try {
            $siteTime = intval(Helper::options()->time);

            if ($siteTime > 0) {
                $now = $siteTime;
            }
        } catch (Throwable $e) {
            // 用服务器时间兜底
        }

        $select->where('table.contents.created < ?', $now);

        // 密码保护：登录用户能看到自己的，访客只能看无密码的
        if ($logged) {
            $select->where(
                "table.contents.password IS NULL OR table.contents.password = '' OR table.contents.authorId = ?",
                $uid
            );
        } else {
            $select->where("table.contents.password IS NULL OR table.contents.password = ''");
        }

        $words = self::words($keyword);

        if (empty($words)) {
            // 没有可用关键词：给一条永远不成立的条件，免得把全站文章列出来
            $select->where('1 = 0');

            return $select;
        }

        foreach ($words as $word) {
            // 顺序无关：每个词都必须出现（AND），出现在标题或正文都算
            $select->where(
                '(table.contents.title LIKE ? OR table.contents.text LIKE ?)',
                '%' . self::escapeLike($word) . '%',
                '%' . self::escapeLike($word) . '%'
            );
        }

        $select->where('table.contents.type = ?', 'post');

        return $select;
    }

    /**
     * LIKE 通配符转义（核心自己的搜索是不转义的，顺手修掉）
     *
     * @param string $value
     * @return string
     */
    public static function escapeLike($value)
    {
        return str_replace(
            array('\\', '%', '_'),
            array('\\\\', '\\%', '\\_'),
            (string) $value
        );
    }
}
