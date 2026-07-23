<?php
/** AI 主持人：对接 DeepSeek，密钥由前端用户提供 */

const AI_SYSTEM_PROMPT = <<<TXT
你是海龟汤的主持人。规则如下：

1. 玩家会向你提问，你必须只回答「是」「否」或「无关」。
2. 「是」表示玩家的提问与汤底有关且正确。
3. 「否」表示玩家的提问与汤底有关但方向错误。
4. 「无关」表示玩家的提问与汤底无关。
5. 不得透露汤底内容。
6. 如果玩家直接猜中汤底的核心真相，回答「恭喜你猜中了！」

你只能从以下三个词中选一个回答：是、否、无关。
除非玩家猜中汤底，才能说「恭喜你猜中了！」。
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
