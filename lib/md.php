<?php
/**
 * 解析海龟汤 Markdown 文件
 *
 * 支持多种段落标记，按类型独立返回：
 *   - surface      汤面（玩家可见的谜题）
 *   - base         汤底（真相/答案）
 *   - host_manual  主持人手册（含伪人/隐藏主持人玩法等特殊指令）
 *   - extra        其他内容（隐藏规则、怪谈解析、故事梗概、收容物、残响碎片、幻灵角色视角等）
 *
 * 识别的段落起始标记（不区分大小写，支持「## 标记」「# 标记」「标记：」「标记（注释）」等形式）：
 *   汤面类：汤面 / 汤面规则 / 残响（汤面）/ 残响
 *   汤底类：汤底 / 回音（汤底）/ 回音 / 怪谈解析
 *   主持人手册类：主持人手册
 *   其他类：隐藏规则 / 故事梗概 / 收容物 / 残响碎片 / 幻灵角色视角 / 玩家获胜条件 / 玩法 等
 */
function parse_md(string $filename, string $content): array {
    // 统一换行
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", rtrim($content));

    // 标题：首个 # 行
    $title = preg_replace('/\.md$/', '', $filename);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '# ')) {
            $title = trim(substr(trim($line), 2));
            // 去掉书名号和季集前缀，提取纯标题
            $title = preg_replace('/^《(.+)》$/', '$1', $title);
            break;
        }
    }

    // 段落标记识别规则：key => 正则（匹配行首标记）
    // 顺序敏感：先匹配更具体的标记
    // 注意：
    //   - 「汤面」「汤底」「怪谈解析」「故事梗概」等允许后面直接跟内容（如「汤面我吃饱饭就死了」），
    //     因此不带尾部锚定。
    //   - 「残响」「回音」作为短词，必须用 lookahead (?=[：:（(]|$) 限定尾部，
    //     否则会把「残响碎片」「残响难度」误判为 surface、把「回音（汤底）」外的内容误吞。
    //   - 「回音（汤底）」允许括号前后有空格，兼容「回音 （汤底）」「回音(汤底)」等写法。
    $markers = [
        'surface'     => '/^(?:#{0,2}\s*)?(?:汤面规则|残响\s*[（(]\s*汤面\s*[)）]|汤面|残响(?=[：:（(]|$))/u',
        'base'        => '/^(?:#{0,2}\s*)?(?:回音\s*[（(]\s*汤底\s*[)）]|怪谈解析|故事梗概|汤底|回音(?=[：:（(]|$))/u',
        'host_manual' => '/^(?:#{0,2}\s*)?(?:主持人手册|主持人须知|玩法说明|残响碎片|幻灵角色视角|残响难度|通关条件|隐藏规则|玩家获胜条件|胜利条件|提问次数)(?:[：:（(]|$)?/u',
        'extra'       => '/^(?:#{0,2}\s*)?(?:收容物|背景设定|附录|备注)(?:[：:（(]|$)?/u',
    ];

    // 预处理：把「汤面+汤底」「汤面+汤底XXX」这种合并标记拆成两行
    // 仅当行首匹配时拆分，避免误伤正文中的「汤面+汤底」表述
    $lines = array_map(function($line) {
        $t = trim($line);
        if (preg_match('/^(#{0,2}\s*)汤面\s*[+＋&与和及]\s*汤底(.*)$/u', $t, $m)) {
            $prefix = isset($m[1]) ? $m[1] : '';
            $rest = isset($m[2]) ? $m[2] : '';
            // 拆成：汤面 / 汤底<rest> 两行（rest 通常为空或紧跟内容）
            return [$prefix . '汤面', $prefix . '汤底' . ltrim($rest)];
        }
        return [$line];
    }, $lines);
    // 展平嵌套数组
    $flat = [];
    foreach ($lines as $arr) foreach ($arr as $l) $flat[] = $l;
    $lines = $flat;

    // 按"行"扫描，根据当前段落归属累积内容
    $sections = ['surface' => [], 'base' => [], 'host_manual' => [], 'extra' => []];
    $current = null; // 当前所属段落 key

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // 跳过一级标题行（已用作 title）
        if (str_starts_with($trimmed, '# ')) continue;

        // 尝试匹配段落起始标记
        $matched = false;
        foreach ($markers as $key => $pattern) {
            if (preg_match($pattern, $trimmed)) {
                $current = $key;
                // 标记行本身也可能带内容（如「汤面我吃饱饭就死了」），把标记后的内容保留
                $rest = preg_replace($pattern, '', $trimmed);
                $rest = ltrim($rest, '：:（(）)');
                if ($rest !== '') $sections[$key][] = $rest;
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        // 未匹配任何标记的行：归属当前段落，否则暂存到 extra
        if ($current !== null) {
            $sections[$current][] = $line;
        } else {
            // 标题下方、首个标记前的内容（通常是空行或元信息），归到 extra
            $sections['extra'][] = $line;
        }
    }

    // 清理：去除每段首尾空行
    $clean = function (array $arr): string {
        $s = trim(implode("\n", $arr));
        return $s;
    };

    $surface    = $clean($sections['surface']);
    $base       = $clean($sections['base']);
    $hostManual = $clean($sections['host_manual']);
    $extra      = $clean($sections['extra']);

    // 兜底1：若标记都没匹配到，回退到老的「汤面...汤底...」正则
    if ($surface === '' && $base === '') {
        $body = implode("\n", array_filter($lines, fn($l) => !str_starts_with(trim($l), '#')));
        if (preg_match('/汤面(.+?)汤底(.+)/s', $body, $m)) {
            $surface = trim($m[1]);
            $base    = trim($m[2]);
        }
    }

    // 兜底2：surface 有内容但 base 空，且 extra 中有非收容物内容 →
    // 视为作者省略了「汤底」标记，把 extra 当作 base（收容物仍归 extra）
    if ($surface !== '' && $base === '' && $extra !== '') {
        // 尝试从 extra 中切出收容物段落，剩余归 base
        if (preg_match('/^(.+?)(\n\s*收容物\s*.*)$/s', $extra, $m)) {
            $base  = trim($m[1]);
            $extra = trim($m[2]);
        } else {
            // extra 中不含收容物，整体当作 base
            $base  = $extra;
            $extra = '';
        }
    }

    // 若 base 里混入了主持人手册，切分出来
    if ($hostManual === '' && preg_match('/^(.+?)主持人手册(.+)$/s', $base, $m)) {
        $base       = trim($m[1]);
        $hostManual = trim($m[2]);
    }

    // season/episode 从文件名推断
    $season = '';
    $episode = '';
    if (preg_match('/^(S\d+)(E\d+)/', $filename, $m2)) {
        $season  = $m2[1];
        $episode = $m2[2];
    }
    if (!$season) {
        if (str_contains($filename, '灵之残响')) $season = '灵之残响';
        elseif (str_contains($filename, '规则怪谈')) $season = '规则怪谈';
    }

    return [
        'filename'     => $filename,
        'season'       => $season,
        'episode'      => $episode,
        'title'        => $title,
        'surface'      => $surface,
        'base'         => $base,
        'host_manual'  => $hostManual,
        'extra'        => $extra,
    ];
}
