<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Engine.php';

/**
 * 搜索增强 - 跳板
 *
 * 路由 `/search`（**精确路径**，与核心的 `/search/关键词/` 不冲突，用的是同一个
 * `/search` 前缀，所以主题表单 action 写 `/search/` 就落到这里）。
 *
 * 它只做一件事：把主题搜索框提交上来的**原文**做「过滤器安全编码」，
 * 然后 302 到核心的搜索路由 `/search/<词元>/`。
 *
 * 【为什么要绕这一下】核心会把 URL 里的关键词过滤一遍（空格删掉、符号丢弃、
 * 超过 128 字符截断），原文直接放进去就被改坏了。编成只含 `[0-9A-Za-z]` 的词元
 * 之后再交给核心，它怎么过滤都动不了；到了结果页，我们的钩子再解码回原文。
 * 编码放在这里（服务端）做，所以**不依赖 JS**。
 *
 * @package SearchPlus
 * @version 1.0.1
 */
class SearchPlus_Search extends Typecho_Widget
{
    /**
     * Widget 初始化
     *
     * @return void
     */
    public function execute()
    {
    }

    /**
     * 兜底动作
     *
     * @return void
     */
    public function action()
    {
        $this->dispatch();
    }

    /**
     * 路由入口：编码 → 跳到核心的搜索页
     *
     * @return void
     */
    public function dispatch()
    {
        $options = Helper::options();
        $raw     = $this->readKeyword();

        if ('' === $raw) {
            // 空关键词不 404，回首页就好
            $this->response->redirect((string) $options->siteUrl);
            return;
        }

        list($keyword, $truncated) = SearchPlus_Engine::clamp($raw);
        $token = SearchPlus_Engine::encode($keyword);

        // 用核心的路由定义拼地址（开没开伪静态都对）
        $url = Typecho_Router::url('search', array('keywords' => $token), (string) $options->index);

        if ('' === $url || '#' === $url) {
            // 理论上不会发生（核心一定有 search 路由），兜底手工拼一次
            $url = rtrim((string) $options->index, '/') . '/search/' . rawurlencode($token) . '/';
        }

        if ($truncated) {
            // 关键词被截断了，让结果页带一句话说明（见 Plugin::renderExtra）
            $url .= (false === strpos($url, '?') ? '?' : '&') . 'sp_cut=1';
        }

        $this->response->redirect($url);
    }

    /**
     * 读原始关键词
     *
     * 主题搜索框用的是 `s`（与 Typecho 原生一致）；也接受 `q` / `keywords`，
     * 方便别的入口直接拼地址。
     *
     * @return string
     */
    protected function readKeyword()
    {
        $raw = '';

        foreach (array('s', 'q', 'keywords') as $key) {
            try {
                $value = (string) $this->request->get($key, '');
            } catch (Throwable $e) {
                $value = '';
            }

            $value = trim($value);

            if ('' !== $value) {
                $raw = $value;
                break;
            }
        }

        if ('' === $raw) {
            return '';
        }

        // 只做「去标签 + 压空白」，**不做**任何过滤（过滤正是我们要绕开的东西）
        $raw = preg_replace('/\s+/u', ' ', strip_tags($raw));
        $raw = is_string($raw) ? trim($raw) : '';

        return $raw;
    }
}
