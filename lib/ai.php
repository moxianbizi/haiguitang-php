<?php
/** AI 主持人：对接 DeepSeek，密钥由前端用户提供 */

const AI_SYSTEM_PROMPT = <<<TXT
你是海龟汤的主持人。玩家只能向你提问是非题，你必须只回答「是」「否」「无关」或「恭喜你猜中了！」。

海龟汤的核心特点：汤面会用误导性表述让玩家以为主角是人类，但汤底往往揭示主角是动物、物品或其他角色。你必须严格依据汤底事实回答，绝不能被汤面的表述误导。

判定规则：
- 「是」：根据汤底事实，玩家提问的答案是肯定的。
- 「否」：根据汤底事实，玩家提问的答案是否定的。
- 「无关」：仅当汤底中完全没有涉及该信息，且无法从汤底合理推断时才回答。
  注意：只要能从汤底推断出答案，就不算无关！优先判断是或否。
- 「恭喜你猜中了！」：玩家直接说出了汤底的核心真相。

关键判定原则：
1. 严格按字面含义理解玩家的提问。问"是人吗"就必须判断主角是否为人类，不是人类就回答"否"。
2. 问"有死人吗"就必须判断故事中是否有"人"死亡，不是人就不算死人。
3. 玩家用"我"自称时，"我"指汤底故事中的主角/当事人。
4. 常见误区：汤面写了"我吃饱饭就死了"不代表主角是人，要看汤底真相。
5. 不得透露汤底内容，不得解释原因，只回答上述四个选项之一。
TXT;

class AIError extends Exception {
    public string $aiCode;
    public function __construct(string $message, string $code = 'ai_error') {
        parent::__construct($message);
        $this->aiCode = $code;
    }
}

/**
 * 向 DeepSeek 提问
 * @param string $surface 汤面
 * @param string $base 汤底
 * @param string $question 玩家问题
 * @param string $api_key 用户提供的 DeepSeek Key
 * @return string AI 回答
 */
function ask_ai(string $surface, string $base, string $question, string $api_key): string {
    $api_key = trim($api_key);
    if ($api_key === '') {
        throw new AIError('未提供 DeepSeek API Key，请在页面设置中填写。', 'missing_key');
    }

    $user_content = "汤面（玩家已知）：{$surface}\n汤底（仅你可知，不可透露）：{$base}\n\n玩家提问：{$question}";

    $payload = [
        'model' => Config::$DEEPSEEK_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $user_content],
        ],
        'max_tokens' => 64,
        'temperature' => 0.3,
    ];

    $ch = curl_init(Config::$DEEPSEEK_BASE_URL . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        if (str_contains($err, 'timed out') || str_contains($err, 'Timeout')) {
            throw new AIError('AI 思考超时，请重试。', 'timeout');
        }
        throw new AIError('AI 调用失败：' . $err, 'request_error');
    }

    if ($status === 401) {
        throw new AIError('DeepSeek API Key 无效或已过期，请检查后重新填写。', 'invalid_key');
    }
    if ($status === 402) {
        throw new AIError('DeepSeek 账户余额不足。', 'insufficient_balance');
    }
    if ($status >= 400) {
        $detail = '';
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['error']['message'])) $detail = $j['error']['message'];
        elseif (is_string($resp)) $detail = mb_substr($resp, 0, 120);
        throw new AIError("AI 服务返回错误 ({$status})：{$detail}", 'upstream_error');
    }

    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) {
        throw new AIError('AI 返回内容解析失败。', 'parse_error');
    }
    return trim($j['choices'][0]['message']['content']);
}
