<?php
// ファイル名: admin/toc_extract_tool_ai.php
// INDEXINDEX 目次抽出TOOL

session_start();

require_once __DIR__ . '/../app/config.php';

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;
$errors = [];
$messages = [];
$raw_text = '';
$rows = [];
$uploaded_image = $_SESSION['toc_extract_image'] ?? null;

if (empty($_SESSION['toc_extract_csrf_token'])) {
    $_SESSION['toc_extract_csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token_toc_extract() {
    return $_SESSION['toc_extract_csrf_token'] ?? '';
}

function validate_csrf_toc_extract() {
    return isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['toc_extract_csrf_token'] ?? '', $_POST['csrf_token']);
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function mb_trim($s) {
    return preg_replace('/^[\s　]+|[\s　]+$/u', '', (string)$s);
}

function normalize_alnum_space($s) {
    // 英数字・スペースを半角化。カナは変換しない。
    return mb_convert_kana((string)$s, 'as', 'UTF-8');
}

function normalize_text($s) {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    $s = str_replace("\xc2\xa0", ' ', $s);
    return normalize_alnum_space($s);
}

function normalize_episode_no($s) {
    $s = normalize_alnum_space($s);
    $s = mb_trim($s);

    // 話数は数字だけにする。FILE1154、第79話、Stage.30、#149、2/ なども 1154 / 79 / 30 / 149 / 2 にする。
    if (preg_match('/[0-9]+/u', $s, $m)) {
        return $m[0];
    }

    if (preg_match('/[一二三四五六七八九十百千万]+/u', $s, $m)) {
        return (string)japanese_numeral_to_int($m[0]);
    }

    return '';
}

function strip_work_title_brackets($title) {
    $title = mb_trim($title);
    return preg_replace('/^[\[［]\s*(.*?)\s*[\]］]$/u', '$1', $title);
}

function japanese_numeral_to_int($s) {
    $digits = [
        '一' => 1,
        '二' => 2,
        '三' => 3,
        '四' => 4,
        '五' => 5,
        '六' => 6,
        '七' => 7,
        '八' => 8,
        '九' => 9,
    ];
    $units = [
        '十' => 10,
        '百' => 100,
        '千' => 1000,
        '万' => 10000,
    ];

    $total = 0;
    $num = 0;
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($chars as $ch) {
        if (isset($digits[$ch])) {
            $num = $digits[$ch];
            continue;
        }

        if (isset($units[$ch])) {
            $total += ($num > 0 ? $num : 1) * $units[$ch];
            $num = 0;
        }
    }

    return $total + $num;
}

function ai_episode_normalize_digits($s) {
    return mb_convert_kana((string)$s, 'n', 'UTF-8');
}

function ai_episode_kanji_to_int($s) {
    $s = str_replace(['〇', '零'], '〇', (string)$s);
    if ($s === '') {
        return 0;
    }

    $digits = [
        '〇' => 0,
        '一' => 1,
        '二' => 2,
        '三' => 3,
        '四' => 4,
        '五' => 5,
        '六' => 6,
        '七' => 7,
        '八' => 8,
        '九' => 9,
    ];
    $units = [
        '十' => 10,
        '百' => 100,
    ];

    if (preg_match('/^[〇一二三四五六七八九]+$/u', $s)) {
        $number = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $number .= (string)($digits[$ch] ?? '');
        }
        return (int)$number;
    }

    $total = 0;
    $num = 0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (isset($digits[$ch])) {
            $num = $digits[$ch];
            continue;
        }

        if (isset($units[$ch])) {
            $total += ($num > 0 ? $num : 1) * $units[$ch];
            $num = 0;
        }
    }

    return $total + $num;
}

function detect_ai_episode_marker($texts) {
    $candidates = [];

    foreach ($texts as $text) {
        $text = (string)$text;
        if ($text === '') {
            continue;
        }

        $patterns = [
            ['pattern' => '/(FILE\s*\.?\s*([0-9０-９]+))/iu', 'max' => 9999],
            ['pattern' => '/(home\s*\.\s*([0-9０-９]+))/iu', 'max' => 999],
            ['pattern' => '/(Stage\s*\.\s*([0-9０-９]+))/iu', 'max' => 999],
            ['pattern' => '/(#\s*([0-9０-９]+))/u', 'max' => 999],
            ['pattern' => '/(できるまで\s*([0-9０-９]+))/u', 'max' => 999],
            ['pattern' => '/(第?\s*([0-9０-９]+|[〇零一二三四五六七八九十百]+)\s*(?:話|夜|章|番目))/u', 'max' => 999],
            ['pattern' => '/(其\s*[のノ]\s*([0-9０-９]+|[〇零一二三四五六七八九十百]+))/u', 'max' => 999],
        ];

        foreach ($patterns as $patternDef) {
            if (!preg_match_all($patternDef['pattern'], $text, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $numText = ai_episode_normalize_digits(mb_trim($m[2]));
                $number = preg_match('/^[0-9]+$/u', $numText) ? (int)$numText : ai_episode_kanji_to_int($numText);
                if ($number > 0 && $number <= $patternDef['max']) {
                    $original = preg_replace('/\s+/u', '', $m[1]);
                    $candidates[$original] = [
                        'number' => $number,
                        'text' => $original,
                    ];
                }
            }
        }
    }

    $candidateNumbers = array_values(array_unique(array_column($candidates, 'number')));

    if (count($candidateNumbers) === 1) {
        $candidate = array_values($candidates)[0];
        return [$candidate['number'], $candidate['text']];
    }

    return [null, ''];
}

function append_note_unique($note, $addition) {
    $note = normalize_alnum_space(mb_trim($note));
    $addition = mb_trim($addition);

    if ($addition === '') {
        return $note;
    }

    if ($note !== '' && mb_strpos(normalize_alnum_space($note), normalize_alnum_space($addition), 0, 'UTF-8') !== false) {
        return $note;
    }

    return ($note === '') ? $addition : $note . ' ／ ' . $addition;
}

function cleanup_ai_episode_title($episodeTitle, $episodeMarker) {
    $episodeTitle = normalize_alnum_space(mb_trim($episodeTitle));
    $episodeMarker = normalize_alnum_space(mb_trim($episodeMarker));

    if ($episodeTitle === '' || $episodeMarker === '' || mb_strpos($episodeTitle, $episodeMarker, 0, 'UTF-8') === false) {
        return $episodeTitle;
    }

    $episodeTitle = str_replace($episodeMarker, '', $episodeTitle);
    $episodeTitle = preg_replace('/^[\s　\[\]［］()（）【】◆◇・:：\/／|｜-]+|[\s　\[\]［］【】]+$/u', '', $episodeTitle);

    return mb_trim($episodeTitle);
}

function load_work_titles($pdo) {
    $sql = "SELECT id, title FROM works WHERE title IS NOT NULL AND title <> '' ORDER BY CHAR_LENGTH(title) DESC, title ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_work_by_db($line, $work_titles) {
    $best = null;
    $lineNormalized = normalize_alnum_space($line);

    foreach ($work_titles as $w) {
        $title = normalize_alnum_space((string)$w['title']);
        if ($title === '') {
            continue;
        }

        $pos = mb_strpos($lineNormalized, $title, 0, 'UTF-8');
        if ($pos === false) {
            continue;
        }

        if ($best === null || $pos < $best['pos'] || ($pos === $best['pos'] && mb_strlen($title, 'UTF-8') > mb_strlen($best['title'], 'UTF-8'))) {
            $best = [
                'id' => (int)$w['id'],
                'title' => $title,
                'pos' => $pos,
            ];
        }
    }

    return $best;
}

function first_episode_pos($text) {
    $text = normalize_alnum_space($text);

    $patterns = [
        '/FILE\.?\s*[0-9]+/u',
        '/[□\[［(（]\s*第[〈《\[]?[0-9一二三四五六七八九十百千万]+[〉》\]]?\s*(話|夜|章|回|直|匹目|筆|刷|食|席|講|課題)/u',
        '/第[〈《\[]?[0-9一二三四五六七八九十百千万]+[〉》\]]?\s*(話|夜|章|回|直|匹目|筆|刷|食|席|講|課題)/u',
        '/[0-9]+\s*話/u',
        '/[◇◆]\s*[0-9一二三四五六七八九十百千万]+\s*話\s*[◇◆]/u',
        '/其ノ[0-9一二三四五六七八九十]+/u',
        '/其の[0-9一二三四五六七八九十]+/u',
        '/其之[0-9一二三四五六七八九十]+/u',
        '/エピソード\([0-9一二三四五六七八九十百千万]+\)/u',
        '/番外編/u',
        '/[〔\[]\s*[0-9一二三四五六七八九十百千万]+\s*句目/u',
        '/Stage\.\s*[0-9]+/u',
        '/[#＃][0-9]+/u',
        '/No\.\s*[0-9]+/iu',
        '/LOVE\.\s*[0-9]+/iu',
        '/[0-9]+\s*日[目め]/u',
        '/[\[［]\s*[0-9一二三四五六七八九十百千万]+\s*[、,]/u',
        '/できるまで[0-9]+/u',
        '/[0-9]+\s*[／\/]/u',
    ];

    $min = null;
    foreach ($patterns as $p) {
        if (preg_match($p, $text, $m, PREG_OFFSET_CAPTURE)) {
            $bytePos = $m[0][1];
            $charPos = mb_strlen(substr($text, 0, $bytePos), 'UTF-8');
            if ($min === null || $charPos < $min) {
                $min = $charPos;
            }
        }
    }
    return $min;
}

function split_episode($episode_text) {
    $episode_text = normalize_alnum_space(mb_trim($episode_text));
    $startsWithSquareBracket = preg_match('/^[\[［]/u', $episode_text);
    $episode_text = preg_replace('/^[□\[［(（]\s*/u', '', $episode_text);
    if ($startsWithSquareBracket) {
        $episode_text = preg_replace('/\s*[\]］]$/u', '', $episode_text);
    }
    if ($episode_text === '') {
        return ['', ''];
    }

    $patterns = [
        '/^(FILE\.?\s*[0-9]+)[\s　]*(.*)$/u',
        '/^(第[〈《\[]?[0-9一二三四五六七八九十百千万]+[〉》\]]?\s*(?:話|夜|章|回|直|匹目|筆|刷|食|席|講|課題))[\s　：:!！｜|\/／◇◎□●―――\[\]］)）]*(.*)$/u',
        '/^([0-9]+\s*話)[\s　：:｜|◇◎―――\[\]］)）]*(.*)$/u',
        '/^[◇◆]\s*([0-9一二三四五六七八九十百千万]+\s*話)\s*[◇◆][\s　：:｜|◇◎●―――\[\]］)）]*(.*)$/u',
        '/^(其ノ[0-9一二三四五六七八九十]+|其の[0-9一二三四五六七八九十]+|其之[0-9一二三四五六七八九十]+)[\s　：:｜|◇◎]*(.*)$/u',
        '/^(エピソード\([0-9一二三四五六七八九十百千万]+\))[\s　：:｜|◇◎●―――\[\]］)）]*(.*)$/u',
        '/^(番外編)[\s　：:｜|◇◎●―――\[\]］)）]*(.*)$/u',
        '/^[〔\[]\s*([0-9一二三四五六七八九十百千万]+\s*句目)[\s　：:｜|◇◎]*(.*?)[〕\]]?$/u',
        '/^(Stage\.\s*[0-9]+)[\s　]*(.*)$/u',
        '/^([#＃][0-9]+)[\s　]*(.*)$/u',
        '/^(No\.\s*[0-9]+)[\s　]*(.*)$/iu',
        '/^(LOVE\.\s*[0-9]+)[\s　]*(.*)$/iu',
        '/^([0-9]+\s*日[目め])[\s　：:!！｜|\/／◇◎□●★―――\[\]］)）]*(.*)$/u',
        '/^([0-9一二三四五六七八九十百千万]+)[\s　]*[、,][\s　]*(.*)$/u',
        '/^(できるまで[0-9]+)[\s　]*(.*)$/u',
        '/^([0-9]+\s*[／\/])[\s　]*(.*)$/u',
    ];

    if (preg_match('/^エピソード\([0-9一二三四五六七八九十百千万]+\)[\s　]*(第[〈《\[]?[0-9一二三四五六七八九十百千万]+[〉》\]]?\s*話.*)$/u', $episode_text, $m)) {
        return split_episode($m[1]);
    }

    foreach ($patterns as $p) {
        if (preg_match($p, $episode_text, $m)) {
            return [
                normalize_episode_no($m[1]),
                mb_trim($m[2] ?? '')
            ];
        }
    }

    return ['', $episode_text];
}

function is_creator_role_token($token) {
    return (preg_match('/^(原作|作画|漫画|構成|脚本|協力|監修|原案|シナリオ|ストーリー協力|取材・企画協力|story\s*director)[・:：\/].+/iu', $token) ? true : false);
}

function first_creator_role_index($tokens) {
    foreach ($tokens as $i => $token) {
        if (is_creator_role_token($token) && !preg_match('/^story\s*director/i', $token)) {
            return $i;
        }
    }
    return null;
}

function count_creator_role_tokens($tokens, $start) {
    $count = 0;
    for ($i = $start; $i < count($tokens); $i++) {
        if (is_creator_role_token($tokens[$i])) {
            $count++;
        }
    }
    return $count;
}

function role_creator_start_index($tokens, $roleIndex) {
    if ($roleIndex <= 0 || !preg_match('/[ぁ-んァ-ヶ一-龠]/u', $tokens[$roleIndex - 1])) {
        return $roleIndex;
    }

    if (preg_match('/^(原作|漫画|作画)[・:：\/]/u', $tokens[$roleIndex])) {
        return $roleIndex;
    }

    return $roleIndex - 1;
}

function creator_role_step($tokens, $index) {
    if (is_creator_role_token($tokens[$index])) {
        return 1;
    }

    if ($index > 0 && preg_match('/^story$/iu', $tokens[$index - 1]) && preg_match('/^director[・:：\/].+/iu', $tokens[$index])) {
        return 2;
    }

    return 0;
}

function split_title_creator_from_tail($text) {
    $text = normalize_alnum_space(mb_trim($text));
    if ($text === '') {
        return ['', ''];
    }

    $tokens = preg_split('/[\s　]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) < 2) {
        return [$text, ''];
    }

    $firstRoleIndex = first_creator_role_index($tokens);
    if (
        $firstRoleIndex !== null
        && $firstRoleIndex > 0
        && (
            !preg_match('/[ぁ-んァ-ヶ一-龠]/u', $tokens[$firstRoleIndex - 1])
            || count_creator_role_tokens($tokens, $firstRoleIndex) >= 2
        )
    ) {
        $creatorStart = role_creator_start_index($tokens, $firstRoleIndex);
        return [
            strip_work_title_brackets(implode(' ', array_slice($tokens, 0, $creatorStart))),
            implode(' ', array_slice($tokens, $creatorStart)),
        ];
    }

    $creatorStart = count($tokens) - 1;
    while ($creatorStart > 0) {
        $step = creator_role_step($tokens, $creatorStart);
        if ($step === 0) {
            break;
        }
        $creatorStart -= $step;
    }

    return [
        strip_work_title_brackets(implode(' ', array_slice($tokens, 0, $creatorStart))),
        implode(' ', array_slice($tokens, $creatorStart)),
    ];
}

function split_title_creator($text, $work_titles) {
    $text = normalize_alnum_space(mb_trim($text));

    if (preg_match('/^[\[［]\s*(.+?)\s*[\]］]\s*(.*)$/u', $text, $m)) {
        return [
            'work_id' => 0,
            'work_title' => strip_work_title_brackets($m[1]),
            'creator_text' => mb_trim($m[2]),
            'promo' => '',
            'confidence' => '低',
        ];
    }

    $heuristic = split_title_creator_from_tail($text);
    $found = find_work_by_db($text, $work_titles);

    if (!$found) {
        return [
            'work_id' => 0,
            'work_title' => $heuristic[0],
            'creator_text' => $heuristic[1],
            'promo' => '',
            'confidence' => '低',
        ];
    }

    $dbTitle = $found['title'];
    $dbTitleLen = mb_strlen($dbTitle, 'UTF-8');
    $heuristicTitleLen = mb_strlen($heuristic[0], 'UTF-8');
    $dbTitleStart = $found['pos'];
    $dbTitleEnd = $dbTitleStart + $dbTitleLen;
    $charBeforeDbTitle = ($dbTitleStart > 0) ? mb_substr($text, $dbTitleStart - 1, 1, 'UTF-8') : '';
    $charAfterDbTitle = mb_substr($text, $dbTitleEnd, 1, 'UTF-8');
    $dbTitleIsBracketed = (
        ($charBeforeDbTitle === '[' && $charAfterDbTitle === ']')
        || ($charBeforeDbTitle === '［' && $charAfterDbTitle === '］')
    );
    $dbLooksShort = (
        $heuristic[0] !== ''
        && $heuristicTitleLen > $dbTitleLen
        && mb_strpos($heuristic[0], $dbTitle, 0, 'UTF-8') !== false
    );
    $dbLooksLikeCreator = (
        $heuristic[1] !== ''
        && $found['pos'] >= $heuristicTitleLen
    );

    if ($dbLooksShort || $dbLooksLikeCreator) {
        return [
            'work_id' => 0,
            'work_title' => $heuristic[0],
            'creator_text' => $heuristic[1],
            'promo' => '',
            'confidence' => '低',
        ];
    }

    return [
        'work_id' => $found['id'],
        'work_title' => strip_work_title_brackets($dbTitle),
        'creator_text' => mb_trim(mb_substr($text, $dbTitleEnd + ($dbTitleIsBracketed ? 1 : 0), null, 'UTF-8')),
        'promo' => mb_trim(mb_substr($text, 0, $dbTitleStart - ($dbTitleIsBracketed ? 1 : 0), 'UTF-8')),
        'confidence' => '高',
    ];
}

function repair_late_episode_split($result, $work_titles) {
    if ($result['creator_text'] !== '' && first_episode_pos($result['creator_text']) === 0) {
        $titleCreator = split_title_creator($result['work_title'], $work_titles);
        [$episodeNo, $episodeTitle] = split_episode($result['creator_text']);

        $result['work_id'] = $titleCreator['work_id'];
        $result['work_title'] = $titleCreator['work_title'];
        $result['promo'] = $titleCreator['promo'];
        $result['creator_text'] = $titleCreator['creator_text'];
        $result['creators'] = parse_creators($titleCreator['creator_text']);
        if ($episodeNo !== '') {
            $result['episode_no'] = $episodeNo;
            $result['episode_title'] = $episodeTitle;
        }
    }

    $workTitleEpPos = first_episode_pos($result['work_title']);
    if ($workTitleEpPos !== null) {
        $oldCreatorText = $result['creator_text'];
        $titleCreatorText = mb_trim(mb_substr($result['work_title'], 0, $workTitleEpPos, 'UTF-8'));
        $episodeText = mb_trim(mb_substr($result['work_title'], $workTitleEpPos, null, 'UTF-8'));
        $titleCreator = split_title_creator($titleCreatorText, $work_titles);
        [$episodeNo, $episodeTitle] = split_episode($episodeText);

        $result['work_id'] = $titleCreator['work_id'];
        $result['work_title'] = $titleCreator['work_title'];
        $result['promo'] = $titleCreator['promo'];
        $result['creator_text'] = $titleCreator['creator_text'];
        $result['creators'] = parse_creators($titleCreator['creator_text']);
        if ($episodeNo !== '') {
            $result['episode_no'] = $episodeNo;
            $result['episode_title'] = ($episodeTitle !== '') ? $episodeTitle : $oldCreatorText;
        }
    }

    $result['work_title'] = strip_work_title_brackets($result['work_title']);
    $result['note'] = $result['raw'];

    return $result;
}

function parse_creators($creator_text) {
    $creator_text = normalize_alnum_space(mb_trim($creator_text));
    if ($creator_text === '') {
        return [];
    }

    $items = [];

    if (preg_match_all('/(原作|作画|漫画|構成|脚本|協力|監修)：([^：]+?)(?=(?:\s*(?:原作|作画|漫画|構成|脚本|協力|監修)：)|$)/u', $creator_text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $role = mb_trim($m[1]);
            $name = mb_trim($m[2]);
            if ($name !== '') {
                $items[] = ['role' => $role, 'name' => $name];
            }
        }
        return $items;
    }

    $items[] = ['role' => '', 'name' => $creator_text];
    return $items;
}

function detect_flags_from_text($text) {
    $text = normalize_alnum_space($text);

    return [
        'is_one_shot' => (preg_match('/読切|読み切り|短編/u', $text) ? 1 : 0),
        'is_guest_publication' => (preg_match('/出張掲載/u', $text) ? 1 : 0),
        'is_new_series' => (preg_match('/新連載|新シリーズ開幕/u', $text) ? 1 : 0),
        'is_final_episode' => (preg_match('/最終回|完結/u', $text) ? 1 : 0),
    ];
}


function get_openai_api_key() {
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        return OPENAI_API_KEY;
    }

    $key = getenv('OPENAI_API_KEY');
    return $key ? $key : '';
}

function get_openai_model() {
    if (defined('OPENAI_MODEL') && OPENAI_MODEL !== '') {
        return OPENAI_MODEL;
    }

    $model = getenv('OPENAI_MODEL');
    return $model ? $model : 'gpt-4.1-mini';
}

function extract_response_text($response) {
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    if (!empty($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $output) {
            if (empty($output['content']) || !is_array($output['content'])) {
                continue;
            }
            foreach ($output['content'] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
    }

    return '';
}

function creators_to_text($creators) {
    if (!is_array($creators)) {
        return '';
    }

    $parts = [];
    foreach ($creators as $creator) {
        $name = normalize_alnum_space(mb_trim($creator['name'] ?? ''));
        $role = normalize_alnum_space(mb_trim($creator['role'] ?? ''));

        if ($name === '') {
            continue;
        }

        if ($role === '' || $role === '作者' || $role === 'author') {
            $parts[] = $name;
        } else {
            $parts[] = $role . '：' . $name;
        }
    }

    return implode(' ', $parts);
}

function normalize_creators_json($json) {
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $creator) {
        if (!is_array($creator)) {
            continue;
        }

        $name = normalize_alnum_space(mb_trim($creator['name'] ?? ''));
        $role = normalize_alnum_space(mb_trim($creator['role'] ?? ''));

        if ($role === '作者') {
            $role = 'author';
        }

        if ($name !== '') {
            $items[] = ['name' => $name, 'role' => $role];
        }
    }

    return $items;
}

function normalize_creators_from_post($value) {
    if (!is_array($value)) {
        return [];
    }

    $items = [];
    foreach ($value as $creator) {
        if (!is_array($creator)) {
            continue;
        }

        $name = normalize_alnum_space(mb_trim($creator['name'] ?? ''));
        $role = normalize_alnum_space(mb_trim($creator['role'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($role === '' || $role === '作者') {
            $role = 'author';
        }

        $items[] = ['name' => $name, 'role' => $role];
    }

    return $items;
}

function load_issue_for_toc_extract($pdo, $issue_id) {
    if ($issue_id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            mi.id,
            mi.magazine_id,
            mi.issue_label,
            mi.release_date,
            mi.issue_no,
            m.name AS magazine_name,
            p.name AS publisher_name
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE mi.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $issue_id]);
    $issue = $stmt->fetch();

    return $issue ?: null;
}

function load_issues_for_toc_extract($pdo) {
    $stmt = $pdo->query("
        SELECT
            mi.id,
            mi.issue_label,
            mi.release_date,
            mi.issue_no,
            m.name AS magazine_name,
            p.name AS publisher_name
        FROM magazine_issues mi
        JOIN magazines m ON mi.magazine_id = m.id
        JOIN publishers p ON m.publisher_id = p.id
        WHERE mi.status IN ('approved', 'in_progress', 'verified')
        ORDER BY
            CASE
                WHEN mi.publication_date IS NOT NULL
                     AND mi.publication_date <> ''
                     AND mi.publication_date <> '0000-00-00'
                THEN mi.publication_date
                WHEN mi.release_date IS NOT NULL
                     AND mi.release_date <> ''
                     AND mi.release_date <> '0000-00-00'
                THEN mi.release_date
                ELSE '9999-12-31'
            END DESC,
            mi.id DESC
    ");

    return $stmt->fetchAll();
}

function toc_extract_magazine_settings($issue_or_magazine_id) {
    $magazine_id = is_array($issue_or_magazine_id) ? (int)($issue_or_magazine_id['magazine_id'] ?? 0) : (int)$issue_or_magazine_id;
    $magazine_name = is_array($issue_or_magazine_id) ? (string)($issue_or_magazine_id['magazine_name'] ?? '') : '';
    $common = [
        'reading_direction' => 'natural',
        'column_order' => 'auto',
        'text_direction' => 'auto',
        'title_position' => 'auto',
        'creator_position' => 'auto',
        'page_number_position' => 'auto',
        'note_position' => 'auto',
        'exclude_keywords' => ['プレゼント', '広告', '投稿募集', 'お知らせ', 'インタビュー', '次号予告', '読者コーナー'],
        'special_symbols' => ['新連載', '最終回', '読切', '出張掲載', '巻頭カラー', '巻中カラー', 'センターカラー'],
        'additional_instruction' => '',
    ];

    $margaretFormat = [
        'column_order' => 'right_to_left',
        'text_direction' => 'vertical',
        'additional_instruction' => implode("\n", [
            'マーガレット[月2回刊]の目次では、画像が見開きになっていることがあります。右ページの大きな目次だけで止めず、左ページ上部の「Contents」パネルも漫画作品の目次として必ず確認してください。',
            '左ページ上部の「Contents」パネルでは、各行の右端に黒い吹き出し状・ギザギザ状の開始ページ番号があり、作品名と作者名が横並びで載ることがあります。この形式も通常の作品行として抽出してください。',
            '左ページ側には300番台以降の後半ページ作品がまとまることがあります。ページ番号が大きい、右ページとデザインが違う、文字が小さい、という理由だけで除外しないでください。',
            '左ページ下部にはカラーグラビア、まんがセミナー、お知らせ、コーナー、アンケートなども混在します。これらは漫画作品ではないため登録対象にしないでください。',
            '例: 「ふたりで恋をする理由」や「矢神くんは、今日もイジワル。」に巻頭カラー以外のカラー表記がある場合、note/color_type は「センターカラー」にしてください。',
            '左側抽出の参考例: 309「春は短し恋せよ男子。」椎葉ナナ、334「円谷さん家」あだち、337「僕だって、キスしたい。」細堀ゆかり、363「この男、不可視議につき。」須賀千夏、389「つまさきだちのアリス」吉田夢美、418「うしろにS子さん」森ゆきえ、427「乙女の放課後」安理由香。',
            '参考例と完全一致する必要はありません。実際の画像で読める左上Contentsパネルの作品を優先してください。',
        ]),
    ];

    $byMagazine = [
        // magazine_id が確認できたら、ここにマーガレット形式の実IDを追加する。
        // 例: 123 => ['column_order' => 'right_to_left', 'text_direction' => 'vertical']
        'margaret_format' => $margaretFormat,
    ];

    if (isset($byMagazine[(int)$magazine_id])) {
        return array_merge($common, $byMagazine[(int)$magazine_id]);
    }

    if ($magazine_name !== '' && mb_strpos($magazine_name, 'マーガレット', 0, 'UTF-8') !== false) {
        return array_merge($common, $margaretFormat);
    }

    return $common;
}

function log_toc_extract_api_error($issue_id, $type, $status = null, $summary = '', $request_id = '') {
    $line = sprintf(
        "[%s] toc_extract_tool_ai issue_id=%d type=%s http_status=%s request_id=%s summary=%s\n",
        date('c'),
        (int)$issue_id,
        preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$type),
        ($status === null ? '' : (string)(int)$status),
        preg_replace('/[^\w\-\.]/', '', (string)$request_id),
        str_replace(["\r", "\n"], ' ', mb_substr((string)$summary, 0, 300, 'UTF-8'))
    );

    $logFile = __DIR__ . '/../work/toc_extract_ai_errors.log';
    if (is_dir(dirname($logFile)) && is_writable(dirname($logFile))) {
        error_log($line, 3, $logFile);
        return;
    }

    error_log($line);
}

function upload_toc_extract_image($pdo, $issue_id) {
    if ($issue_id <= 0) {
        throw new RuntimeException('雑誌号を選択してください。');
    }

    if (!isset($_FILES['toc_image']) || $_FILES['toc_image']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('画像が選択されていません。');
    }

    $file = $_FILES['toc_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('画像アップロードでエラーが発生しました。');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('画像サイズは5MB以下にしてください。');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('非対応ファイルです。JPG / JPEG / PNG / WEBP の画像を選択してください。');
    }

    $uploadDir = __DIR__ . '/../uploads/source_images/';
    $realBase = realpath(__DIR__ . '/..');
    if ($realBase === false) {
        throw new RuntimeException('画像保存先を確認できませんでした。');
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('画像保存先を作成できませんでした。');
    }

    $realUploadDir = realpath($uploadDir);
    if ($realUploadDir === false || strpos($realUploadDir, $realBase) !== 0) {
        throw new RuntimeException('画像保存先が不正です。');
    }

    $ext = $allowed[$mime];
    $fileName = 'issue_' . (int)$issue_id . '_toc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $savePath = $realUploadDir . DIRECTORY_SEPARATOR . $fileName;
    $dbPath = 'uploads/source_images/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        throw new RuntimeException('画像保存に失敗しました。');
    }

    $stmt = $pdo->prepare("
        INSERT INTO source_images (
            issue_id,
            image_path,
            note
        ) VALUES (
            :issue_id,
            :image_path,
            :note
        )
    ");
    $stmt->execute([
        ':issue_id' => (int)$issue_id,
        ':image_path' => $dbPath,
        ':note' => '目次AI抽出用画像',
    ]);

    return [
        'source_image_id' => (int)$pdo->lastInsertId(),
        'db_path' => $dbPath,
        'file_path' => $savePath,
        'mime' => $mime,
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
}

function resolve_toc_extract_image_path($image) {
    if (!is_array($image) || empty($image['db_path'])) {
        return '';
    }

    $path = __DIR__ . '/../' . ltrim((string)$image['db_path'], '/\\');
    $realBase = realpath(__DIR__ . '/..');
    $realPath = realpath($path);

    if ($realBase === false || $realPath === false || strpos($realPath, $realBase) !== 0) {
        return '';
    }

    return $realPath;
}

function append_note_parts($note, $parts) {
    foreach ($parts as $part) {
        $note = append_note_unique($note, $part);
    }

    return $note;
}

function remove_unneeded_note_tokens($note) {
    $note = normalize_alnum_space(mb_trim($note));
    if ($note === '') {
        return '';
    }

    $parts = preg_split('/\s*(?:\/|／|、|,|・)\s*/u', $note);
    $kept = [];
    foreach ($parts as $part) {
        $part = mb_trim($part);
        if ($part === '' || preg_match('/^(モノクロ|白黒|通常カラーなし|color|カラー)$/iu', $part)) {
            continue;
        }
        if (preg_match('/^(読切|読み切り|読切り)$/u', $part)) {
            continue;
        }
        if (preg_match('/カラー|color/iu', $part) && !preg_match('/^(巻頭カラー|センターカラー)$/u', $part)) {
            continue;
        }
        $kept[] = $part;
    }

    return implode(' / ', $kept);
}

function text_has_one_shot_marker($text) {
    return preg_match('/読切|読み切り|読切り/u', (string)$text) ? true : false;
}

function normalize_color_note($texts) {
    $joined = normalize_alnum_space(implode(' ', array_map('strval', $texts)));
    if ($joined === '' || preg_match('/モノクロ|白黒|通常カラーなし/iu', $joined)) {
        return '';
    }

    if (preg_match('/巻頭\s*カラー|巻頭C|表紙\s*&?\s*巻頭カラー/iu', $joined)) {
        return '巻頭カラー';
    }

    if (preg_match('/センター\s*カラー|巻中\s*カラー|カラー扉|カラー[0-9０-９]*P|[Cc]olor|カラー/u', $joined)) {
        return 'センターカラー';
    }

    return '';
}

function call_ai_toc_extract($raw_text, $work_titles = []) {
    $apiKey = get_openai_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY が未設定です。サーバー環境変数、または app/config.php の定数で設定してください。');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHPのcURL拡張が有効ではありません。');
    }

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['rows'],
        'properties' => [
            'rows' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'work_title',
                        'creators',
                        'episode_no',
                        'episode_title',
                        'note',
                        'is_one_shot',
                        'is_guest_publication',
                        'is_new_series',
                        'is_final_episode',
                        'raw'
                    ],
                    'properties' => [
                        'work_title' => ['type' => 'string'],
                        'creators' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'role'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'role' => ['type' => 'string']
                                ]
                            ]
                        ],
                        'episode_no' => ['type' => 'string'],
                        'episode_title' => ['type' => 'string'],
                        'note' => ['type' => 'string'],
                        'is_one_shot' => ['type' => 'integer'],
                        'is_guest_publication' => ['type' => 'integer'],
                        'is_new_series' => ['type' => 'integer'],
                        'is_final_episode' => ['type' => 'integer'],
                        'raw' => ['type' => 'string']
                    ]
                ]
            ]
        ]
    ];

    $payload = [
        'model' => get_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => 'あなたは漫画雑誌の目次データをINDEXINDEX登録用に構造化する抽出エンジンです。説明文は不要です。入力行のうち先頭が「・」の行だけを対象にしてください。作品名、作者、役割、話数、話タイトル、備考、読切/出張掲載/新連載/最終回フラグを抽出してください。話数は数字だけに正規化してください。判断できない値は空文字にしてください。通常の作者 role は「author」にしてください。原作、作画、脚本、監修、原案、story director などは role にそのまま入れてください。備考に「モノクロ」「白黒」「color」「カラー」のような曖昧またはカラーでないことだけを示す表記は入れないでください。読切/読み切りは備考ではなく is_one_shot に入れてください。'
            ],
            [
                'role' => 'user',
                'content' => $raw_text
            ]
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'toc_extract_rows',
                'strict' => true,
                'schema' => $schema
            ]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('OpenAI APIへの接続に失敗しました: ' . $curlError);
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
        throw new RuntimeException('OpenAI APIの応答JSONを解析できませんでした。HTTP status: ' . $status);
    }

    if ($status < 200 || $status >= 300) {
        $message = $response['error']['message'] ?? $body;
        throw new RuntimeException('OpenAI APIエラー: ' . $message);
    }

    $text = extract_response_text($response);
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
        throw new RuntimeException('AI抽出結果のJSON形式が想定と違います。');
    }

    $rows = [];
    $order = 1;
    foreach ($decoded['rows'] as $r) {
        $workTitle = normalize_alnum_space(mb_trim($r['work_title'] ?? ''));
        $creatorText = creators_to_text($r['creators'] ?? []);
        $creators = $r['creators'] ?? [];

        if ($workTitle !== '' && $creatorText === '') {
            $titleCreator = split_title_creator($workTitle, $work_titles);
            if (!empty($titleCreator['creator_text'])) {
                $workTitle = $titleCreator['work_title'];
                $creatorText = $titleCreator['creator_text'];
                $creators = parse_creators($creatorText);
            }
        }

        $episodeNo = normalize_episode_no($r['episode_no'] ?? '');
        $episodeTitle = normalize_alnum_space(mb_trim($r['episode_title'] ?? ''));
        $raw = normalize_alnum_space(mb_trim($r['raw'] ?? ''));
        $note = remove_unneeded_note_tokens($r['note'] ?? '');
        $isOneShot = !empty($r['is_one_shot'])
            || text_has_one_shot_marker($note)
            || text_has_one_shot_marker($raw);
        [$detectedEpisodeNo, $detectedEpisodeText] = detect_ai_episode_marker([
            $r['episode_no'] ?? '',
            $r['episode_title'] ?? '',
            $r['note'] ?? '',
            $r['raw'] ?? '',
            $r['work_title'] ?? '',
        ]);

        if ($detectedEpisodeNo !== null) {
            if ($episodeNo === '') {
                $episodeNo = (string)$detectedEpisodeNo;
            }
            $note = append_note_unique($note, $detectedEpisodeText);
            $episodeTitle = cleanup_ai_episode_title($episodeTitle, $detectedEpisodeText);
        }

        if ($workTitle === '' && $creatorText === '' && $episodeNo === '' && $episodeTitle === '') {
            continue;
        }

        $rows[] = [
            'display_order' => $order++,
            'work_id' => 0,
            'work_title' => $workTitle,
            'creator_text' => $creatorText,
            'creators' => $creators,
            'episode_no' => $episodeNo,
            'episode_title' => $episodeTitle,
            'note' => $note,
            'is_one_shot' => $isOneShot ? 1 : 0,
            'is_guest_publication' => !empty($r['is_guest_publication']) ? 1 : 0,
            'is_new_series' => !empty($r['is_new_series']) ? 1 : 0,
            'is_final_episode' => !empty($r['is_final_episode']) ? 1 : 0,
            'raw' => $raw,
            'promo' => '',
            'confidence' => 'AI',
        ];
    }

    return $rows;
}

function toc_extract_ai_schema() {
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['rows'],
        'properties' => [
            'rows' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'display_order',
                        'start_page',
                        'work_title',
                        'creators',
                        'episode_no',
                        'episode_title',
                        'note',
                        'content_type',
                        'publication_type',
                        'is_one_shot',
                        'is_guest_publication',
                        'is_new_series',
                        'is_final_episode',
                        'color_type',
                        'page_count',
                        'confidence',
                        'raw'
                    ],
                    'properties' => [
                        'display_order' => ['type' => 'integer'],
                        'start_page' => ['type' => 'string'],
                        'work_title' => ['type' => 'string'],
                        'creators' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'role'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'role' => ['type' => 'string']
                                ]
                            ]
                        ],
                        'episode_no' => ['type' => 'string'],
                        'episode_title' => ['type' => 'string'],
                        'note' => ['type' => 'string'],
                        'content_type' => ['type' => 'string'],
                        'publication_type' => ['type' => 'string'],
                        'is_one_shot' => ['type' => 'integer'],
                        'is_guest_publication' => ['type' => 'integer'],
                        'is_new_series' => ['type' => 'integer'],
                        'is_final_episode' => ['type' => 'integer'],
                        'color_type' => ['type' => 'string'],
                        'page_count' => ['type' => 'string'],
                        'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'raw' => ['type' => 'string']
                    ]
                ]
            ]
        ]
    ];
}

function normalize_ai_toc_rows($decodedRows) {
    $rows = [];
    $order = 1;

    foreach ($decodedRows as $r) {
        if (!is_array($r)) {
            continue;
        }

        $workTitle = normalize_alnum_space(mb_trim($r['work_title'] ?? ''));
        $creators = normalize_creators_from_post($r['creators'] ?? []);
        $creatorText = creators_to_text($creators);
        $episodeNo = normalize_episode_no($r['episode_no'] ?? '');
        $episodeTitle = normalize_alnum_space(mb_trim($r['episode_title'] ?? ''));
        $note = remove_unneeded_note_tokens($r['note'] ?? '');
        $colorType = normalize_alnum_space(mb_trim($r['color_type'] ?? ''));
        $pageCount = normalize_alnum_space(mb_trim($r['page_count'] ?? ''));
        $publicationType = normalize_alnum_space(mb_trim($r['publication_type'] ?? ''));
        $raw = normalize_alnum_space(mb_trim($r['raw'] ?? ''));
        $confidence = in_array(($r['confidence'] ?? ''), ['high', 'medium', 'low'], true) ? $r['confidence'] : 'medium';
        $isOneShot = !empty($r['is_one_shot'])
            || text_has_one_shot_marker($note)
            || text_has_one_shot_marker($publicationType)
            || text_has_one_shot_marker($raw);

        if ($workTitle === '' && $creatorText === '' && $episodeNo === '' && $episodeTitle === '') {
            continue;
        }

        $noteParts = [];
        $colorNote = normalize_color_note([$colorType, $note, $raw]);
        if ($colorNote !== '') {
            $noteParts[] = $colorNote;
        }
        if (!empty($r['is_guest_publication']) && mb_strpos($note, '出張掲載', 0, 'UTF-8') === false) {
            $noteParts[] = '出張掲載';
        }
        if (!empty($r['is_new_series']) && mb_strpos($note, '新連載', 0, 'UTF-8') === false) {
            $noteParts[] = '新連載';
        }
        if (!empty($r['is_final_episode']) && mb_strpos($note, '最終回', 0, 'UTF-8') === false) {
            $noteParts[] = '最終回';
        }
        if ($pageCount !== '' && mb_strpos($note, $pageCount, 0, 'UTF-8') === false) {
            $noteParts[] = preg_match('/P$/iu', $pageCount) ? $pageCount : $pageCount . 'P';
        }
        $note = remove_unneeded_note_tokens(append_note_parts($note, $noteParts));

        $displayOrder = (int)($r['display_order'] ?? 0);
        if ($displayOrder <= 0) {
            $displayOrder = $order;
        }

        $rows[] = [
            'display_order' => $displayOrder,
            'page_start' => normalize_episode_no($r['start_page'] ?? ''),
            'work_id' => 0,
            'work_title' => $workTitle,
            'creator_text' => $creatorText,
            'creators' => $creators,
            'episode_no' => $episodeNo,
            'episode_title' => $episodeTitle,
            'note' => $note,
            'content_type' => 'manga',
            'publication_type' => $publicationType,
            'is_one_shot' => $isOneShot ? 1 : 0,
            'is_guest_publication' => !empty($r['is_guest_publication']) ? 1 : 0,
            'is_new_series' => !empty($r['is_new_series']) ? 1 : 0,
            'is_final_episode' => !empty($r['is_final_episode']) ? 1 : 0,
            'raw' => $raw,
            'promo' => '',
            'confidence' => $confidence,
            'include' => 1,
        ];
        $order++;
    }

    usort($rows, function ($a, $b) {
        return ((int)$a['display_order']) <=> ((int)$b['display_order']);
    });

    return array_values($rows);
}

function call_ai_toc_image_extract($image, $issue, $work_titles = []) {
    $apiKey = get_openai_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY が未設定です。サーバー環境変数、または app/config.php の定数で設定してください。');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHPのcURL拡張が有効ではありません。');
    }

    $imagePath = resolve_toc_extract_image_path($image);
    if ($imagePath === '' || !is_file($imagePath)) {
        throw new RuntimeException('解析対象の画像が見つかりません。もう一度アップロードしてください。');
    }

    $mime = $image['mime'] ?? mime_content_type($imagePath);
    $base64 = base64_encode(file_get_contents($imagePath));
    if ($base64 === '') {
        throw new RuntimeException('画像データを読み込めませんでした。');
    }

    $settings = toc_extract_magazine_settings($issue);
    $instruction = implode("\n", [
        '画像は漫画雑誌の目次です。JSON以外の説明文を返さないでください。',
        '画像全体を確認し、右側だけ・上部だけなど一部で止めず、左右両側、全列、全段にある漫画作品を漏れなく抽出してください。',
        '見開き画像では、左右のページを別々に確認してから統合してください。片側の大きなタイトルやロゴがあるページだけを目次全体と判断しないでください。',
        '「Contents」「もくじ」「目次」と書かれた小さなパネル内の作品行も登録対象です。ページ番号、作品名、作者名が並ぶ行を優先して抽出してください。',
        '画像内の文字を推測で補完しすぎず、判読できない項目は空文字にしてください。',
        '漫画作品と作者を対応付け、複数作者は creators 配列で保持してください。',
        '原作、作画、漫画、構成、シナリオ、監修、原案などの役割を role に保持し、役割表記がない作者は「author」にしてください。',
        '新連載、最終回、読切、出張掲載を識別してください。',
        '読切/読み切りの表記がある作品は is_one_shot=1 にし、note には読切/読み切りを入れないでください。',
        '巻頭カラーの文字がある場合だけ note/color_type は「巻頭カラー」にしてください。巻頭カラー以外のカラー表記、カラー扉、カラー付き表記、巻中カラー、センターカラーは「センターカラー」に正規化してください。',
        '備考に「モノクロ」「白黒」「color」「カラー」のような曖昧またはカラーでないことだけを示す表記は入れないでください。',
        '掲載ページ数は note にも分かる形で保持してください。',
        '作品紹介文やあらすじをタイトルへ混入させないでください。',
        'プレゼント、広告、投稿募集、お知らせ、インタビュー、次号予告、読者コーナーなどは漫画作品として登録しないでください。',
        'ページ番号と掲載ページ数を混同しないでください。start_page は開始ページ、page_count は掲載ページ数です。',
        '縦書き・横書き・複数段組に対応し、目次の自然な読み順を display_order にしてください。',
        'confidence は high / medium / low のいずれかにしてください。',
        '共通除外キーワード: ' . implode('、', $settings['exclude_keywords']),
        '読み取り方向: ' . $settings['reading_direction'],
        '段組順: ' . $settings['column_order'],
        '文字方向: ' . $settings['text_direction'],
        $settings['additional_instruction'],
    ]);

    $issueText = '';
    if ($issue) {
        $issueText = '対象雑誌号: ' . ($issue['magazine_name'] ?? '') . ' ' . ($issue['issue_label'] ?? '') . ' ' . ($issue['issue_no'] ?? '') . ' ' . ($issue['release_date'] ?? '');
    }

    $payload = [
        'model' => get_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => $instruction,
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $issueText],
                    ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . $base64],
                ],
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'toc_extract_rows',
                'strict' => true,
                'schema' => toc_extract_ai_schema(),
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    $requestId = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$requestId) {
            if (stripos($header, 'x-request-id:') === 0) {
                $requestId = trim(substr($header, strlen('x-request-id:')));
            }
            return strlen($header);
        },
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 90,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        log_toc_extract_api_error((int)($issue['id'] ?? 0), 'connection_failed', null, $curlError);
        throw new RuntimeException((stripos($curlError, 'timed out') !== false) ? 'APIタイムアウトが発生しました。時間をおいて再試行してください。' : 'API通信に失敗しました。時間をおいて再試行してください。');
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
        log_toc_extract_api_error((int)($issue['id'] ?? 0), 'api_response_json_failed', $status, mb_substr($body, 0, 300, 'UTF-8'), $requestId);
        throw new RuntimeException('AI応答の解析に失敗しました。管理者ログを確認してください。');
    }

    if ($requestId === '') {
        $requestId = (string)($response['id'] ?? '');
    }
    if ($status < 200 || $status >= 300) {
        $message = $response['error']['message'] ?? 'OpenAI API error';
        log_toc_extract_api_error((int)($issue['id'] ?? 0), 'api_error', $status, $message, $requestId);
        throw new RuntimeException('API通信に失敗しました。HTTPステータス: ' . $status);
    }

    $text = extract_response_text($response);
    if ($text === '') {
        log_toc_extract_api_error((int)($issue['id'] ?? 0), 'empty_response', $status, 'empty output_text', $requestId);
        throw new RuntimeException('AI応答が空でした。画像を確認して再試行してください。');
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
        log_toc_extract_api_error((int)($issue['id'] ?? 0), 'json_parse_failed', $status, json_last_error_msg(), $requestId);
        throw new RuntimeException('JSON解析に失敗しました。管理者ログを確認してください。');
    }

    return normalize_ai_toc_rows($decoded['rows']);
}

function parse_line($line, $work_titles) {
    $original = normalize_alnum_space($line);
    $line = preg_replace('/^\s*・\s*/u', '', $original);
    $line = mb_trim($line);

    $flags = detect_flags_from_text($line);

    $result = [
        'raw' => $original,
        'promo' => '',
        'work_id' => 0,
        'work_title' => '',
        'creator_text' => '',
        'creators' => [],
        'episode_no' => '',
        'episode_title' => '',
        'note' => '',
        'is_one_shot' => $flags['is_one_shot'],
        'is_guest_publication' => $flags['is_guest_publication'],
        'is_new_series' => $flags['is_new_series'],
        'is_final_episode' => $flags['is_final_episode'],
        'confidence' => '低',
    ];

    if ($line === '') {
        return $result;
    }

    $epPos = first_episode_pos($line);
    if ($epPos !== null) {
        $titleCreatorText = mb_trim(mb_substr($line, 0, $epPos, 'UTF-8'));
        $episodeText = mb_trim(mb_substr($line, $epPos, null, 'UTF-8'));
    } else {
        $titleCreatorText = mb_trim($line);
        $episodeText = '';
    }

    $titleCreator = split_title_creator($titleCreatorText, $work_titles);
    [$episodeNo, $episodeTitle] = split_episode($episodeText);

    $result['work_id'] = $titleCreator['work_id'];
    $result['work_title'] = $titleCreator['work_title'];
    $result['promo'] = $titleCreator['promo'];
    $result['creator_text'] = $titleCreator['creator_text'];
    $result['creators'] = parse_creators($titleCreator['creator_text']);
    $result['episode_no'] = $episodeNo;
    $result['episode_title'] = $episodeTitle;
    $result['confidence'] = $titleCreator['confidence'];

    return repair_late_episode_split($result, $work_titles);
}

$work_titles = [];
try {
    $work_titles = load_work_titles($pdo);
} catch (Throwable $e) {
    $errors[] = '作品マスタの読み込みに失敗しました: ' . $e->getMessage();
}

$issues = [];
$selected_issue = null;
try {
    $issues = load_issues_for_toc_extract($pdo);
    $selected_issue = load_issue_for_toc_extract($pdo, $issue_id);
} catch (Throwable $e) {
    $errors[] = '雑誌号情報の読み込みに失敗しました。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_toc_extract()) {
        $errors[] = 'セッション期限切れです。画面を再読み込みしてやり直してください。';
    }

    $raw_text = normalize_text($_POST['raw_text'] ?? '');
    $postedIssueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : $issue_id;
    if ($postedIssueId > 0) {
        $issue_id = $postedIssueId;
        $selected_issue = load_issue_for_toc_extract($pdo, $issue_id);
    }

    if (!$errors && isset($_POST['extract'])) {
        $lines = explode("\n", $raw_text);
        $order = 1;
        foreach ($lines as $line) {
            $line = mb_trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^・/u', $line)) {
                continue;
            }
            $r = parse_line($line, $work_titles);
            $r['display_order'] = $order++;
            $rows[] = $r;
        }

        if (!$rows) {
            $errors[] = '「・ 」で始まる目次行が見つかりませんでした。';
        }
    }

    if (!$errors && isset($_POST['ai_extract'])) {
        try {
            $rows = call_ai_toc_extract($raw_text, $work_titles);
            if (!$rows) {
                $errors[] = 'AI抽出結果が0件でした。先頭が「・ 」の目次行を貼り付けてください。';
            }
        } catch (Throwable $e) {
            $errors[] = 'AI抽出に失敗しました: ' . $e->getMessage();
        }
    }

    if (!$errors && isset($_POST['ai_image_extract'])) {
        try {
            if (!$selected_issue) {
                throw new RuntimeException('雑誌号を選択してください。');
            }

            if (isset($_FILES['toc_image']) && $_FILES['toc_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded_image = upload_toc_extract_image($pdo, $issue_id);
                $_SESSION['toc_extract_image'] = $uploaded_image;
                $messages[] = '目次画像を保存しました。';
            } elseif (is_array($uploaded_image)) {
                $imageIssueId = (int)($_SESSION['toc_extract_image_issue_id'] ?? $issue_id);
                if ($imageIssueId !== $issue_id) {
                    throw new RuntimeException('保存済み画像の雑誌号が一致しません。もう一度アップロードしてください。');
                }
            } else {
                throw new RuntimeException('画像が選択されていません。');
            }

            $_SESSION['toc_extract_image_issue_id'] = $issue_id;
            $rows = call_ai_toc_image_extract($uploaded_image, $selected_issue, $work_titles);
            if (!$rows) {
                $errors[] = '抽出結果が0件でした。画像を確認して再試行してください。';
            }
        } catch (Throwable $e) {
            $errors[] = 'AI画像抽出に失敗しました: ' . $e->getMessage();
            log_toc_extract_api_error($issue_id, 'screen_error', null, $e->getMessage());
        }
    }

    if (!$errors && isset($_POST['send_to_bulk'])) {
        $postedRows = $_POST['rows'] ?? [];
        $sessionRows = [];

        foreach ($postedRows as $i => $r) {
            if (empty($r['include'])) {
                continue;
            }

            $creators = normalize_creators_from_post($r['creators'] ?? []);
            if (empty($creators)) {
                $creators = normalize_creators_json($r['creators_json'] ?? '');
            }
            $creatorText = normalize_alnum_space(mb_trim($r['creator_text'] ?? ''));
            if ($creatorText === '' && !empty($creators)) {
                $creatorText = creators_to_text($creators);
            }
            $noteInput = $r['note'] ?? '';
            $rawInput = $r['raw'] ?? '';
            $publicationTypeInput = normalize_alnum_space(mb_trim($r['publication_type'] ?? ''));
            $isOneShot = !empty($r['is_one_shot'])
                || ($r['toc_content_kind'] ?? '') === 'one_shot'
                || text_has_one_shot_marker($noteInput)
                || text_has_one_shot_marker($rawInput)
                || text_has_one_shot_marker($publicationTypeInput);

            $sessionRows[] = [
                'display_order' => (int)($r['display_order'] ?? ($i + 1)),
                'page_start' => normalize_episode_no($r['page_start'] ?? ''),
                'work_title' => normalize_alnum_space(mb_trim($r['work_title'] ?? '')),
                'creator_text' => $creatorText,
                'creators' => $creators,
                'episode_no' => normalize_episode_no($r['episode_no'] ?? ''),
                'episode_title' => normalize_alnum_space(mb_trim($r['episode_title'] ?? '')),
                'note' => remove_unneeded_note_tokens($noteInput),
                'content_type' => normalize_alnum_space(mb_trim($r['content_type'] ?? 'manga')),
                'publication_type' => $isOneShot ? '読切' : $publicationTypeInput,
                'is_one_shot' => $isOneShot ? 1 : 0,
                'is_guest_publication' => (!empty($r['is_guest_publication']) || ($r['publication_type'] ?? '') === '出張掲載') ? 1 : 0,
                'is_new_series' => isset($r['is_new_series']) ? 1 : 0,
                'is_final_episode' => isset($r['is_final_episode']) ? 1 : 0,
                'raw' => normalize_alnum_space(mb_trim($rawInput)),
            ];
        }

        if (empty($sessionRows)) {
            $errors[] = '登録対象の行がありません。';
            $rows = [];
        }

        if (!$errors) {
            $_SESSION['toc_extract_rows'] = array_values($sessionRows);
            $_SESSION['toc_extract_issue_id'] = $issue_id;

            if ($issue_id > 0) {
                header('Location: content_bulk_add.php?issue_id=' . $issue_id . '&from_toc_extract=1');
                exit;
            }

            $errors[] = 'issue_id が未指定のため、目次登録（一括）へ移動できません。URL例: toc_extract_tool_ai.php?issue_id=123';
        }

        if ($errors) {
            $rows = $sessionRows;
        }
    }
}

function bulk_text_from_rows($rows) {
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = implode("\t", [
            $r['display_order'] ?? '',
            $r['work_title'] ?? '',
            $r['creator_text'] ?? '',
            $r['episode_no'] ?? '',
            $r['episode_title'] ?? '',
            $r['note'] ?? '',
        ]);
    }
    return implode("\n", $lines);
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>目次抽出TOOL - INDEXINDEX</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            padding: 24px;
            background: #f4f6f8;
            color: #222;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .container {
            max-width: 1320px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }
        h1 { margin: 0 0 16px; font-size: 24px; }
        .nav { margin-bottom: 16px; }
        .nav a { color: #2563eb; text-decoration: none; margin-right: 12px; font-weight: 700; }
        .message { padding: 12px 14px; margin: 12px 0; border-radius: 8px; background: #eef6ff; line-height: 1.7; }
        .error { padding: 12px 14px; margin: 12px 0; border-radius: 8px; background: #fff0f0; color: #b00020; line-height: 1.7; }
        textarea {
            width: 100%;
            min-height: 260px;
            box-sizing: border-box;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 14px;
            line-height: 1.7;
            padding: 12px;
            border: 1px solid #ccd3dd;
            border-radius: 8px;
        }
        button {
            appearance: none;
            border: 0;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            padding: 10px 16px;
            font-weight: 700;
            cursor: pointer;
        }
        button.secondary { background: #2563eb; }
        button.light { background: #eef2f7; color: #222; border: 1px solid #cbd5e1; }
        button.danger { background: #b42318; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 14px 0 22px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; min-width: 1480px; }
        th, td { border: 1px solid #d7dde6; padding: 7px; vertical-align: top; }
        th { background: #f0f3f7; white-space: nowrap; }
        input[type="text"], input[type="number"], select, input[type="file"] {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 8px;
            border: 1px solid #ccd3dd;
            border-radius: 6px;
            font-size: 13px;
        }
        .check { text-align: center; white-space: nowrap; }
        .w-order { width: 58px; }
        .w-page { width: 82px; }
        .w-episode { width: 72px; }
        .w-confidence { width: 78px; text-align: center; }
        .small { font-size: 12px; color: #667085; line-height: 1.6; }
        .raw { max-width: 240px; color: #667085; font-size: 12px; line-height: 1.5; }
        .copybox { min-height: 140px; }
        .table-wrap { overflow-x: auto; }
        .form-row { margin-bottom: 14px; }
        label { display: block; font-weight: 700; margin-bottom: 6px; }
        .selected-issue { padding: 12px; margin: 12px 0 16px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 6px; line-height: 1.7; }
        .image-preview { margin: 12px 0 18px; }
        .image-preview img { max-width: 360px; max-height: 260px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
        .creator-editor { display: grid; gap: 6px; min-width: 220px; }
        .creator-row { display: grid; grid-template-columns: 1fr 86px 32px; gap: 5px; align-items: center; }
        .creator-row button { padding: 6px 8px; }
        .confidence { display: inline-block; padding: 3px 7px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .confidence-high, .confidence-ai, .confidence-高 { background: #ecfdf3; color: #027a48; }
        .confidence-medium, .confidence-中 { background: #fffaeb; color: #b54708; }
        .confidence-low, .confidence-低 { background: #fff1f3; color: #c01048; }
        tr.confidence-medium input, tr.confidence-medium select { border-color: #fdb022; }
        tr.confidence-low input, tr.confidence-low select { border-color: #f97066; background: #fff8f8; }
        .row-actions { display: flex; gap: 6px; }
        @media (max-width: 800px) {
            body { padding: 8px; }
            .container { padding: 12px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>目次抽出TOOL</h1>

    <div class="nav">
        <a href="index.php">管理トップ</a>
        <a href="issues.php">雑誌号一覧</a>
        <?php if ($issue_id > 0): ?>
            <a href="content_bulk_add.php?issue_id=<?= h($issue_id) ?>">目次登録（一括）</a>
        <?php endif; ?>
    </div>

    <div class="message">
        雑誌の目次画像をAIで解析し、作品名・作者・開始ページ・備考などを抽出します。抽出結果は必ず確認・修正してから一括登録してください。<br>
        テキスト貼り付けによる通常抽出とAI抽出も、従来どおり利用できます。
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="message"><?= h($m) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $e): ?>
        <div class="error"><?= h($e) ?></div>
    <?php endforeach; ?>

    <?php if ($selected_issue): ?>
        <div class="selected-issue">
            <strong>選択中：</strong>
            <?= h($selected_issue['magazine_name']) ?>
            <?= h($selected_issue['issue_label']) ?>
            / <?= h($selected_issue['publisher_name']) ?>
            <?php if (!empty($selected_issue['issue_no'])): ?>/ <?= h($selected_issue['issue_no']) ?>号<?php endif; ?>
            <?php if (!empty($selected_issue['release_date'])): ?>/ <?= h($selected_issue['release_date']) ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token_toc_extract()) ?>">

        <div class="form-row">
            <label>雑誌号</label>
            <select name="issue_id">
                <option value="">選択してください</option>
                <?php foreach ($issues as $issue): ?>
                    <option value="<?= h($issue['id']) ?>" <?= ((int)$issue_id === (int)$issue['id']) ? 'selected' : '' ?>>
                        <?= h($issue['magazine_name']) ?>
                        <?= h($issue['issue_label']) ?>
                        / <?= h($issue['publisher_name']) ?>
                        <?php if (!empty($issue['issue_no'])): ?>/ <?= h($issue['issue_no']) ?>号<?php endif; ?>
                        <?php if (!empty($issue['release_date'])): ?>/ <?= h($issue['release_date']) ?><?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>目次画像</label>
            <input type="file" name="toc_image" accept="image/jpeg,image/png,image/webp">
            <div class="small">JPG / JPEG / PNG / WEBP、1ファイル5MB以下。保存済み画像がある場合は、未選択のままAI再解析できます。</div>
        </div>

        <?php if (is_array($uploaded_image) && !empty($uploaded_image['db_path'])): ?>
            <div class="image-preview">
                <div class="small">解析対象画像</div>
                <a href="../<?= h($uploaded_image['db_path']) ?>" target="_blank" rel="noopener">
                    <img src="../<?= h($uploaded_image['db_path']) ?>" alt="解析対象の目次画像">
                </a>
            </div>
        <?php endif; ?>

        <textarea name="raw_text" placeholder="ここに目次リストを貼り付けます"><?= h($raw_text) ?></textarea>
        <div class="actions">
            <button type="submit" name="extract" value="1">通常抽出する</button>
            <button type="submit" class="secondary" name="ai_extract" value="1">AI抽出する</button>
            <button type="submit" class="secondary" name="ai_image_extract" value="1">目次画像からAI抽出</button>
        </div>
    </form>

    <?php if ($rows): ?>
        <form method="post" class="js-toc-result-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token_toc_extract()) ?>">
            <input type="hidden" name="issue_id" value="<?= h($issue_id) ?>">
            <input type="hidden" name="raw_text" value="<?= h($raw_text) ?>">

            <h2>抽出結果</h2>
            <p class="small">
                登録前にここで修正してください。信頼度 medium は黄色、low は赤系で表示します。
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th class="check">対象</th>
                        <th class="w-order">順</th>
                        <th class="w-page">開始P</th>
                        <th>作品名</th>
                        <th>作者 / 役割</th>
                        <th class="w-episode">話数</th>
                        <th>話タイトル</th>
                        <th>備考</th>
                        <th>連載・読切</th>
                        <th>通常・出張</th>
                        <th class="check">新連載</th>
                        <th class="check">最終回</th>
                        <th class="w-confidence">精度</th>
                        <th>元行</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody id="tocRows">
                    <?php foreach ($rows as $i => $r): ?>
                        <?php $creators_json = json_encode($r['creators'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                        <?php $confidence_class = 'confidence-' . h($r['confidence'] ?? 'medium'); ?>
                        <tr class="<?= h($confidence_class) ?>">
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][include]" value="1" <?= array_key_exists('include', $r) ? (!empty($r['include']) ? 'checked' : '') : 'checked' ?>></td>
                            <td><input type="number" name="rows[<?= $i ?>][display_order]" value="<?= h($r['display_order'] ?? ($i + 1)) ?>"></td>
                            <td><input type="number" name="rows[<?= $i ?>][page_start]" value="<?= h($r['page_start'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][work_title]" value="<?= h($r['work_title'] ?? '') ?>"></td>
                            <td>
                                <input type="text" name="rows[<?= $i ?>][creator_text]" value="<?= h($r['creator_text'] ?? '') ?>" placeholder="作者テキスト">
                                <input type="hidden" name="rows[<?= $i ?>][creators_json]" value="<?= h($creators_json ?: '[]') ?>">
                                <div class="creator-editor">
                                    <?php $creatorRows = !empty($r['creators']) ? $r['creators'] : [['name' => '', 'role' => 'author']]; ?>
                                    <?php foreach ($creatorRows as $ci => $creator): ?>
                                        <div class="creator-row">
                                            <input type="text" name="rows[<?= $i ?>][creators][<?= $ci ?>][name]" value="<?= h($creator['name'] ?? '') ?>" placeholder="作者名">
                                            <input type="text" name="rows[<?= $i ?>][creators][<?= $ci ?>][role]" value="<?= h(($creator['role'] ?? '') === 'author' ? '' : ($creator['role'] ?? '')) ?>" placeholder="役割">
                                            <button type="button" class="light js-remove-creator">×</button>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="button" class="light js-add-creator">作者追加</button>
                                </div>
                            </td>
                            <td><input type="text" name="rows[<?= $i ?>][episode_no]" value="<?= h($r['episode_no'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][episode_title]" value="<?= h($r['episode_title'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][note]" value="<?= h($r['note'] ?? '') ?>"></td>
                            <td>
                                <select name="rows[<?= $i ?>][toc_content_kind]">
                                    <option value="serial" <?= empty($r['is_one_shot']) ? 'selected' : '' ?>>連載</option>
                                    <option value="one_shot" <?= !empty($r['is_one_shot']) ? 'selected' : '' ?>>読切</option>
                                </select>
                                <input type="hidden" name="rows[<?= $i ?>][content_type]" value="<?= h($r['content_type'] ?? 'manga') ?>">
                                <input type="hidden" name="rows[<?= $i ?>][is_one_shot]" value="<?= !empty($r['is_one_shot']) ? '1' : '' ?>">
                            </td>
                            <td>
                                <select name="rows[<?= $i ?>][publication_type]">
                                    <option value="通常掲載" <?= (($r['publication_type'] ?? '') === '通常掲載' || empty($r['is_guest_publication'])) ? 'selected' : '' ?>>通常掲載</option>
                                    <option value="出張掲載" <?= !empty($r['is_guest_publication']) ? 'selected' : '' ?>>出張掲載</option>
                                </select>
                                <input type="hidden" name="rows[<?= $i ?>][is_guest_publication]" value="<?= !empty($r['is_guest_publication']) ? '1' : '' ?>">
                            </td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_new_series]" value="1" <?= !empty($r['is_new_series']) ? 'checked' : '' ?>></td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_final_episode]" value="1" <?= !empty($r['is_final_episode']) ? 'checked' : '' ?>></td>
                            <td><span class="confidence <?= h($confidence_class) ?>"><?= h($r['confidence'] ?? '') ?></span></td>
                            <td class="raw">
                                <?= h($r['raw'] ?? '') ?>
                                <input type="hidden" name="rows[<?= $i ?>][raw]" value="<?= h($r['raw'] ?? '') ?>">
                            </td>
                            <td class="row-actions">
                                <button type="button" class="light js-sort-row">並替</button>
                                <button type="button" class="danger js-delete-row">削除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <button type="button" class="light" id="addRowButton">行追加</button>
                <button type="submit" class="secondary" name="send_to_bulk" value="1">目次登録（一括）に渡す</button>
            </div>

            <h2>コピー用TSV</h2>
            <p class="small">順番 / 作品名 / 作者 / 話数 / 話タイトル / 備考 のタブ区切りです。</p>
            <textarea class="copybox" readonly><?= h(bulk_text_from_rows($rows)) ?></textarea>
        </form>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('tocRows');
    const addRowButton = document.getElementById('addRowButton');

    function renumberNames() {
        if (!tbody) return;
        tbody.querySelectorAll('tr').forEach(function (tr, rowIndex) {
            tr.querySelectorAll('input, select').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(/rows\[[0-9]+\]/, 'rows[' + rowIndex + ']');
            });
        });
    }

    function nextCreatorIndex(editor) {
        let max = -1;
        editor.querySelectorAll('input[name*="[creators]"]').forEach(function (field) {
            const match = field.name.match(/\[creators\]\[([0-9]+)\]/);
            if (match) max = Math.max(max, parseInt(match[1], 10));
        });
        return max + 1;
    }

    function addCreator(editor, rowIndex, name, role) {
        const idx = nextCreatorIndex(editor);
        const row = document.createElement('div');
        row.className = 'creator-row';
        row.innerHTML =
            '<input type="text" name="rows[' + rowIndex + '][creators][' + idx + '][name]" placeholder="作者名">' +
            '<input type="text" name="rows[' + rowIndex + '][creators][' + idx + '][role]" placeholder="役割">' +
            '<button type="button" class="light js-remove-creator">×</button>';
        row.querySelector('input[name$="[name]"]').value = name || '';
        row.querySelector('input[name$="[role]"]').value = role || '';
        editor.insertBefore(row, editor.querySelector('.js-add-creator'));
    }

    if (tbody) {
        tbody.addEventListener('click', function (event) {
            const target = event.target;
            const tr = target.closest('tr');
            if (!tr) return;

            if (target.classList.contains('js-delete-row')) {
                tr.remove();
                renumberNames();
                return;
            }

            if (target.classList.contains('js-sort-row')) {
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    const av = parseInt((a.querySelector('input[name$="[display_order]"]') || {}).value || '0', 10);
                    const bv = parseInt((b.querySelector('input[name$="[display_order]"]') || {}).value || '0', 10);
                    return av - bv;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
                renumberNames();
                return;
            }

            if (target.classList.contains('js-add-creator')) {
                const rowIndex = Array.from(tbody.querySelectorAll('tr')).indexOf(tr);
                addCreator(target.closest('.creator-editor'), rowIndex, '', '');
                return;
            }

            if (target.classList.contains('js-remove-creator')) {
                const editor = target.closest('.creator-editor');
                if (editor && editor.querySelectorAll('.creator-row').length > 1) {
                    target.closest('.creator-row').remove();
                }
            }
        });

        tbody.addEventListener('change', function (event) {
            const field = event.target;
            const tr = field.closest('tr');
            if (!tr) return;
            if (field.name && field.name.endsWith('[toc_content_kind]')) {
                const hidden = tr.querySelector('input[name$="[is_one_shot]"]');
                if (hidden) hidden.value = field.value === 'one_shot' ? '1' : '';
            }
            if (field.name && field.name.endsWith('[publication_type]')) {
                const hidden = tr.querySelector('input[name$="[is_guest_publication]"]');
                if (hidden) hidden.value = field.value === '出張掲載' ? '1' : '';
            }
        });
    }

    if (addRowButton && tbody) {
        addRowButton.addEventListener('click', function () {
            const rowIndex = tbody.querySelectorAll('tr').length;
            const tr = document.createElement('tr');
            tr.className = 'confidence-medium';
            tr.innerHTML =
                '<td class="check"><input type="checkbox" name="rows[' + rowIndex + '][include]" value="1" checked></td>' +
                '<td><input type="number" name="rows[' + rowIndex + '][display_order]" value="' + (rowIndex + 1) + '"></td>' +
                '<td><input type="number" name="rows[' + rowIndex + '][page_start]" value=""></td>' +
                '<td><input type="text" name="rows[' + rowIndex + '][work_title]" value=""></td>' +
                '<td><input type="text" name="rows[' + rowIndex + '][creator_text]" value="" placeholder="作者テキスト"><input type="hidden" name="rows[' + rowIndex + '][creators_json]" value="[]"><div class="creator-editor"><button type="button" class="light js-add-creator">作者追加</button></div></td>' +
                '<td><input type="text" name="rows[' + rowIndex + '][episode_no]" value=""></td>' +
                '<td><input type="text" name="rows[' + rowIndex + '][episode_title]" value=""></td>' +
                '<td><input type="text" name="rows[' + rowIndex + '][note]" value=""></td>' +
                '<td><select name="rows[' + rowIndex + '][toc_content_kind]"><option value="serial">連載</option><option value="one_shot">読切</option></select><input type="hidden" name="rows[' + rowIndex + '][content_type]" value="manga"><input type="hidden" name="rows[' + rowIndex + '][is_one_shot]" value=""></td>' +
                '<td><select name="rows[' + rowIndex + '][publication_type]"><option value="通常掲載">通常掲載</option><option value="出張掲載">出張掲載</option></select><input type="hidden" name="rows[' + rowIndex + '][is_guest_publication]" value=""></td>' +
                '<td class="check"><input type="checkbox" name="rows[' + rowIndex + '][is_new_series]" value="1"></td>' +
                '<td class="check"><input type="checkbox" name="rows[' + rowIndex + '][is_final_episode]" value="1"></td>' +
                '<td><span class="confidence confidence-medium">medium</span></td>' +
                '<td class="raw"><input type="hidden" name="rows[' + rowIndex + '][raw]" value=""></td>' +
                '<td class="row-actions"><button type="button" class="light js-sort-row">並替</button><button type="button" class="danger js-delete-row">削除</button></td>';
            tbody.appendChild(tr);
            addCreator(tr.querySelector('.creator-editor'), rowIndex, '', '');
        });
    }
});
</script>
</body>
</html>
