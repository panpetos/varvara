<?php
header('Content-Type: text/plain; charset=utf-8');
function html_parts(string $html): array {
    return preg_split(
        '~(<script\b.*?</script>|<style\b.*?</style>|<svg\b.*?</svg>|<head\b.*?</head>|<noscript\b.*?</noscript>|<!--.*?-->)~is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    ) ?: [$html];
}

/** Видимые текстовые фрагменты страницы (как в файле, без обрамляющих пробелов), без повторов. */
function fragments(string $html): array {
    $out = [];
    foreach (html_parts($html) as $i => $part) {
        if ($i % 2) continue;
        if (!preg_match_all('~>([^<>]+)<~u', '>' . $part . '<', $m)) continue;
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '' || !preg_match('/\p{L}/u', $raw)) continue;
            if (mb_strlen(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) < 2) continue;
            $out[$raw] = true;
        }
    }
    return array_map('strval', array_keys($out));
}

echo 'php=', PHP_VERSION, ' jit=', ini_get('pcre.jit'), ' backtrack=', ini_get('pcre.backtrack_limit'), ' recursion=', ini_get('pcre.recursion_limit'), ' mbstring=', (int)function_exists('mb_strlen'), "\n";
foreach (['index','kids','privacy'] as $s) {
  $file = __DIR__.'/'.$s.'.html';
  $h = is_file($file) ? file_get_contents($file) : '';
  echo $s, ' is_file=', (int)is_file($file), ' len=', strlen($h);
  $p = preg_split('~(<script\b.*?</script>|<style\b.*?</style>|<svg\b.*?</svg>|<head\b.*?</head>|<noscript\b.*?</noscript>|<!--.*?-->)~is', $h, -1, PREG_SPLIT_DELIM_CAPTURE);
  echo ' split=', $p === false ? 'FALSE' : count($p), ' err=', preg_last_error_msg();
  $fr = fragments($h);
  echo ' frags=', count($fr), ' err=', preg_last_error_msg(), "\n";
}