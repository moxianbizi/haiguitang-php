<?php
/**
 * 解析海龟汤 Markdown 文件（颜色版格式）
 *
 * 支持的段落标记：
 *   - surface      汤面 / 残响（汤面）/ 残响 / 汤面规则
 *   - base         汤底 / 回音（汤底）/ 回音 / 怪谈解析
 *   - host_manual  主持人手册
 *   - extra        收容物 / 残响碎片 / 幻灵角色视角 / 通关条件 / 隐藏规则 / 玩家获胜条件 / 胜利条件 / 提问次数 / 背景设定 / 附录 / 备注
 *
 * 图片路径转换：./海龟汤图片/xxx.jpeg → /soups-img/xxx.jpeg
 */
function parse_md(string $filename, string $content): array {
    // 统一换行
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", rtrim($content));

    // 标题：首个 # 行，或第一行纯文本（颜色版格式无 # 前缀）
    $title = preg_replace('/\.md$/', '', $filename);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (str_starts_with($t, '# ')) {
            $title = trim(substr($t, 2));
        } else {
            // 颜色版第一行就是标题（如 "S3E16《白雪公主规则怪谈》"）
            $title = $t;
        }
        // 去掉书名号
        $title = preg_replace('/^《(.+)》$/', '$1', $title);
        // 去掉首尾的 ** 加粗标记（如 "**S3E42《教室》**" → "S3E42《教室》"）
        $title = preg_replace('/^\*\*(.+)\*\*$/', '$1', $title);
        break;
    }

    // 段落标记识别规则（顺序敏感：先匹配更具体的标记）
    // 颜色版格式：标记后可能直接跟内容，也可能用"（）"括号说明
    // 注意：通关条件/残响难度 紧跟汤面，作为 surface 的一部分，不单独识别
    $markers = [
        'surface'     => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:汤面规则|残响\s*[（(]\s*汤面\s*[)）]|汤面(?=[：:（(\s]|$)|残响(?=[：:（(\s]|$))(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'base'        => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:回音\s*[（(]\s*汤底\s*[)）]|怪谈解析|故事梗概|汤底|回音(?=[：:（(\s]|$))(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'host_manual' => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:主持人手册|主持人须知|玩法说明)(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'extra'       => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:残响碎片|幻灵角色视角|收容物|隐藏规则|玩家获胜条件|胜利条件|提问次数|背景设定|附录|备注|规则解析)(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
    ];

    // 预处理：把「汤面+汤底」合并标记拆成两行
    $lines = array_map(function($line) {
        $t = trim($line);
        if (preg_match('/^(#{0,2}\s*)汤面\s*[+＋&与和及]\s*汤底(.*)$/u', $t, $m)) {
            $prefix = $m[1] ?? '';
            $rest = $m[2] ?? '';
            return [$prefix . '汤面', $prefix . '汤底' . ltrim($rest)];
        }
        return [$line];
    }, $lines);
    $flat = [];
    foreach ($lines as $arr) foreach ($arr as $l) $flat[] = $l;
    $lines = $flat;

    // 按行扫描
    $sections = ['surface' => [], 'base' => [], 'host_manual' => [], 'extra' => []];
    $current = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // 跳过标题行
        if (str_starts_with($trimmed, '# ') || $trimmed === $title) continue;
        if ($trimmed === '') {
            if ($current !== null) $sections[$current][] = '';
            continue;
        }

        // 尝试匹配段落起始标记
        $matched = false;
        // 先剥离行首的 <span ...> 标签（颜色版可能用蓝色 span 包裹标记）
        $stripped = preg_replace('/^<span[^>]*>\s*/u', '', $trimmed);
        foreach ($markers as $key => $pattern) {
            if (preg_match($pattern, $stripped)) {
                $current = $key;
                // 标记行本身也可能带内容（如「汤面 我吃饱饭就死了」）
                $rest = preg_replace($pattern, '', $stripped);
                $rest = ltrim($rest, '：:）)* 　');
                // 若剥离了 span 标签，且行尾有 </span>，保留 span 结构用于颜色渲染
                if ($rest !== '' && $stripped !== $trimmed) {
                    // 行首有 span 标签，把 span 重新加上（保持颜色）
                    $rest = preg_replace('/^<span[^>]*>/u', '', $trimmed) ;
                    // 去掉标记部分
                    $rest = preg_replace($pattern, '', $rest, 1);
                    $rest = ltrim($rest, '：:）)* 　');
                }
                if ($rest !== '') $sections[$key][] = $rest;
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        // 未匹配标记的行：归属当前段落
        if ($current !== null) {
            $sections[$current][] = $line;
        } else {
            // 标题下方、首个标记前的内容，归到 extra（如"> 选自..."引用块）
            $sections['extra'][] = $line;
        }
    }

    // 清理首尾空行
    $clean = function (array $arr): string {
        return trim(implode("\n", $arr));
    };

    $surface    = $clean($sections['surface']);
    $base       = $clean($sections['base']);
    $hostManual = $clean($sections['host_manual']);
    $extra      = $clean($sections['extra']);

    // 兜底0：表格型汤（如 S3E42《教室》整篇就是一个 markdown 表格，
    // 表头行含"汤面"，数据行含"汤底"）。优先于通用兜底处理，
    // 避免被通用正则切成残缺片段。
    if ($surface === '' && $base === '' && $extra !== '' && preg_match('/^\s*\|/m', $extra)) {
        // 提取"汤面"行：| 汤面XXX | cell | cell |
        if (preg_match('/^\s*\|[^|\n]*汤面[^|\n]*\|(.+?)\|\s*$/mu', $extra, $ms)) {
            $surface = trim($ms[1], "| \t\n");
        }
        // 提取"汤底"行：| 汤底 | cell | cell |
        if (preg_match('/^\s*\|[^|\n]*汤底[^|\n]*\|(.+?)\|\s*$/mu', $extra, $mb)) {
            $base = trim($mb[1], "| \t\n");
        }
        // 提取"主持人手册"行（如有）
        if (preg_match('/^\s*\|[^|\n]*主持人手册[^|\n]*\|(.+?)\|\s*$/mu', $extra, $mh)) {
            $hostManual = trim($mh[1], "| \t\n");
        }
        // 若成功提取出 surface 或 base，清空 extra（避免重复展示整张表）
        if ($surface !== '' || $base !== '') {
            $extra = '';
        }
    }

    // 兜底1：若标记都没匹配到（且不是表格型），回退到老的「汤面...汤底...」正则
    if ($surface === '' && $base === '') {
        $body = implode("\n", array_filter($lines, fn($l) => !str_starts_with(trim($l), '#')));
        if (preg_match('/汤面(.+?)汤底(.+)/s', $body, $m)) {
            $surface = trim($m[1]);
            $base    = trim($m[2]);
        }
    }

    // 兜底1.5：surface 为空但 base 有内容（如 S3E68 "规则（本期无汤面）..."），
    // 把 extra 当作 surface（规则类汤的"规则"就是汤面）
    if ($surface === '' && $base !== '' && $extra !== '') {
        $surface = $extra;
        $extra = '';
    }

    // 兜底1.6：「汤面+汤底」合并格式（如 S3E60），split 后内容进了 base 但 surface 为空，
    // 把 base 复制到 surface（合并格式的汤面本身就是完整故事，汤底即同内容）
    if ($surface === '' && $base !== '' && $extra === '') {
        $surface = $base;
    }

    // 兜底2：surface 有内容但 base 空，且 extra 中有内容
    if ($surface !== '' && $base === '' && $extra !== '') {
        if (preg_match('/^(.+?)(\n\s*收容物\s*.*)$/s', $extra, $m)) {
            $base  = trim($m[1]);
            $extra = trim($m[2]);
        } else {
            $base  = $extra;
            $extra = '';
        }
    }

    // 若 base 里混入了主持人手册，切分出来
    if ($hostManual === '' && preg_match('/^(.+?)主持人手册(.+)$/s', $base, $m)) {
        $base       = trim($m[1]);
        $hostManual = trim($m[2]);
    }

    // 图片路径转换：./海龟汤图片/ → /soups-img/
    // （放在所有兜底之后，确保 surface/base/host_manual/extra 中的图片路径都被转换）
    $imgConvert = function (string $s): string {
        if ($s === '') return $s;
        return str_replace('./海龟汤图片/', '/soups-img/', $s);
    };
    $surface    = $imgConvert($surface);
    $base       = $imgConvert($base);
    $hostManual = $imgConvert($hostManual);
    $extra      = $imgConvert($extra);

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
