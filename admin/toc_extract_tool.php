<?php
// ファイル名: admin/toc_extract_tool.php
// INDEXINDEX 目次抽出TOOL

session_start();

require_once __DIR__ . '/../app/config.php';

$issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;
$errors = [];
$raw_text = '';
$rows = [];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_text = normalize_text($_POST['raw_text'] ?? '');

    if (isset($_POST['extract'])) {
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

    if (isset($_POST['send_to_bulk'])) {
        $postedRows = $_POST['rows'] ?? [];
        $sessionRows = [];

        foreach ($postedRows as $i => $r) {
            $sessionRows[] = [
                'display_order' => (int)($r['display_order'] ?? ($i + 1)),
                'work_title' => normalize_alnum_space(mb_trim($r['work_title'] ?? '')),
                'creator_text' => normalize_alnum_space(mb_trim($r['creator_text'] ?? '')),
                'episode_no' => normalize_episode_no($r['episode_no'] ?? ''),
                'episode_title' => normalize_alnum_space(mb_trim($r['episode_title'] ?? '')),
                'note' => normalize_alnum_space(mb_trim($r['note'] ?? '')),
                'is_one_shot' => isset($r['is_one_shot']) ? 1 : 0,
                'is_guest_publication' => isset($r['is_guest_publication']) ? 1 : 0,
                'is_new_series' => isset($r['is_new_series']) ? 1 : 0,
                'is_final_episode' => isset($r['is_final_episode']) ? 1 : 0,
                'raw' => normalize_alnum_space(mb_trim($r['raw'] ?? '')),
            ];
        }

        $_SESSION['toc_extract_rows'] = $sessionRows;
        $_SESSION['toc_extract_issue_id'] = $issue_id;

        if ($issue_id > 0) {
            header('Location: content_bulk_add.php?issue_id=' . $issue_id . '&from_toc_extract=1');
            exit;
        }

        $errors[] = 'issue_id が未指定のため、目次登録（一括）へ移動できません。URL例: toc_extract_tool.php?issue_id=123';
        $rows = $sessionRows;
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
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 14px 0 22px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; min-width: 1250px; }
        th, td { border: 1px solid #d7dde6; padding: 7px; vertical-align: top; }
        th { background: #f0f3f7; white-space: nowrap; }
        input[type="text"], input[type="number"] {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 8px;
            border: 1px solid #ccd3dd;
            border-radius: 6px;
            font-size: 13px;
        }
        .check { text-align: center; white-space: nowrap; }
        .w-order { width: 58px; }
        .w-episode { width: 72px; }
        .w-confidence { width: 54px; text-align: center; }
        .small { font-size: 12px; color: #667085; line-height: 1.6; }
        .raw { max-width: 240px; color: #667085; font-size: 12px; line-height: 1.5; }
        .copybox { min-height: 140px; }
        .table-wrap { overflow-x: auto; }
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
        先頭が「・ 」の行を1件として抽出します。<br>
        英数字は半角化し、話数は数字だけで抽出します。作品名は登録済みの作品マスタを優先して判定します。
    </div>

    <?php foreach ($errors as $e): ?>
        <div class="error"><?= h($e) ?></div>
    <?php endforeach; ?>

    <form method="post">
        <textarea name="raw_text" placeholder="ここに目次リストを貼り付けます"><?= h($raw_text) ?></textarea>
        <div class="actions">
            <button type="submit" name="extract" value="1">抽出する</button>
        </div>
    </form>

    <?php if ($rows): ?>
        <form method="post">
            <input type="hidden" name="raw_text" value="<?= h($raw_text) ?>">

            <h2>抽出結果</h2>
            <p class="small">
                登録前にここで修正してください。精度「低」は作品名がDBから見つからなかった行です。
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th class="w-order">順</th>
                        <th>作品名</th>
                        <th>作者</th>
                        <th class="w-episode">話数</th>
                        <th>話タイトル</th>
                        <th>備考</th>
                        <th class="check">読切</th>
                        <th class="check">出張</th>
                        <th class="check">新連載</th>
                        <th class="check">最終回</th>
                        <th class="w-confidence">精度</th>
                        <th>元行</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td><input type="number" name="rows[<?= $i ?>][display_order]" value="<?= h($r['display_order'] ?? ($i + 1)) ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][work_title]" value="<?= h($r['work_title'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][creator_text]" value="<?= h($r['creator_text'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][episode_no]" value="<?= h($r['episode_no'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][episode_title]" value="<?= h($r['episode_title'] ?? '') ?>"></td>
                            <td><input type="text" name="rows[<?= $i ?>][note]" value="<?= h($r['note'] ?? '') ?>"></td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_one_shot]" value="1" <?= !empty($r['is_one_shot']) ? 'checked' : '' ?>></td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_guest_publication]" value="1" <?= !empty($r['is_guest_publication']) ? 'checked' : '' ?>></td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_new_series]" value="1" <?= !empty($r['is_new_series']) ? 'checked' : '' ?>></td>
                            <td class="check"><input type="checkbox" name="rows[<?= $i ?>][is_final_episode]" value="1" <?= !empty($r['is_final_episode']) ? 'checked' : '' ?>></td>
                            <td><?= h($r['confidence'] ?? '') ?></td>
                            <td class="raw">
                                <?= h($r['raw'] ?? '') ?>
                                <input type="hidden" name="rows[<?= $i ?>][raw]" value="<?= h($r['raw'] ?? '') ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <button type="submit" class="secondary" name="send_to_bulk" value="1">目次登録（一括）に渡す</button>
            </div>

            <h2>コピー用TSV</h2>
            <p class="small">順番 / 作品名 / 作者 / 話数 / 話タイトル / 備考 のタブ区切りです。</p>
            <textarea class="copybox" readonly><?= h(bulk_text_from_rows($rows)) ?></textarea>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
