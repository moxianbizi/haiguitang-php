<?php
/** 解析海龟汤 Markdown 文件，支持行内「汤面/汤底」和 ## 标记两种格式 */
function parse_md(string $filename, string $content): array {
    $lines = explode("\n", rtrim($content));
    $title = preg_replace('/\.md$/', '', $filename);
    $season = '';
    $episode = '';
    $surface = '';
    $base = '';

    // 标题：首个 # 行
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '# ')) {
            $title = trim(substr(trim($line), 2));
            break;
        }
    }

    // 去掉 # 标题行
    $body_lines = array_filter($lines, fn($l) => !str_starts_with(trim($l), '#'));
    $body = implode("\n", $body_lines);

    // 优先：行内「汤面...汤底...」正则切分
    if (preg_match('/汤面(.+?)汤底(.+)/s', $body, $m)) {
        $surface = trim($m[1]);
        $base = trim($m[2]);
    } else {
        // 兼容 ## 汤面 / ## 汤底 标记
        $section = null;
        foreach ($lines as $line) {
            $s = trim($line);
            if ($s === '## 汤面' || $s === '# 汤面') $section = 'surface';
            elseif ($s === '## 汤底' || $s === '# 汤底') $section = 'base';
            elseif ($section === 'surface') $surface .= $line . "\n";
            elseif ($section === 'base') $base .= $line . "\n";
        }
        $surface = trim($surface);
        $base = trim($base);
    }

    // season/episode 从文件名推断
    if (preg_match('/^(S\d+)(E\d+)/', $filename, $m2)) {
        $season = $m2[1];
        $episode = $m2[2];
    }
    if (!$season) {
        if (str_contains($filename, '灵之残响')) $season = '灵之残响';
        elseif (str_contains($filename, '规则怪谈')) $season = '规则怪谈';
    }

    return [
        'filename' => $filename,
        'season' => $season,
        'episode' => $episode,
        'title' => $title,
        'surface' => $surface,
        'base' => $base,
    ];
}
