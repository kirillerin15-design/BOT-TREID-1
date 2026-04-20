<?php
// AUTO-GENERATED XAU/LLM CRON FILE 
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
$data_file = __DIR__ . '/admin/data/gold-analyzer.json';
$hash_verify = '9bd83a906de0fe4d';
if (!isset($_GET['token']) || $_GET['token'] !== $hash_verify) die("Denied.");
if (!file_exists($data_file)) die("No config found.");
$jd = json_decode(file_get_contents($data_file), true);

function add_g_log(&$jd, $m, $type) { 
    array_unshift($jd['logs'], ['time'=>date('Y-m-d H:i:s'),'status'=>$type,'msg'=>$m]);
    if(count($jd['logs']) > 150) $jd['logs'] = array_slice($jd['logs'], 0, 150);
}
function fetch_g_url($url) {
    $ch = curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_USERAGENT=>"Mozilla/5.0"]);
    $ret=curl_exec($ch); curl_close($ch); return $ret ?: '';
}

$st = microtime(true);
$ctx_chunks = [];

foreach (($jd['sources'] ??[]) as $s) {
    if (!$s['active']) continue;
    try {
        if ($s['type'] === 'rss') {
            $raw = fetch_g_url($s['url']); if (!$raw) throw new Exception("Empty");
            $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($x && isset($x->channel->item)) { foreach($x->channel->item as $i) $ctx_chunks[] = "- ".$i->title.": ".$i->description; }
        } elseif ($s['type'] === 'api') {
            $raw = fetch_g_url($s['url']);
            $ctx_chunks[] = "[API] ".substr($raw, 0, 1000);
        } else {
            $raw = fetch_g_url($s['url']); if (!$raw) continue;
            libxml_use_internal_errors(true); $d=new DOMDocument; $d->loadHTML(mb_convert_encoding($raw, 'HTML-ENTITIES', 'UTF-8'));
            $x=new DOMXPath($d); $p= $x->query($s['selector'] ?: '//p');
            $h_text=''; foreach($p as $node) $h_text .= trim($node->textContent).' ';
            $ctx_chunks[] = "[SITE] ".substr($h_text, 0, 1000);
        }
    } catch(Exception $e) { add_g_log($jd, "Error fetching: ".$s['name']." -> ".$e->getMessage(), "error"); }
}

$f_context = mb_substr(implode("\n---\n", $ctx_chunks), 0, 6000);

if ($jd['use_ai'] && !empty($jd['api_key'])) {
    $po = json_encode(["model"=>"gpt-3.5-turbo","messages"=>[
       ["role"=>"system","content"=>$jd['prompt']], ["role"=>"user","content"=>$f_context]
    ],"temperature"=>0.4]);
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1, CURLOPT_TIMEOUT=>40, 
        CURLOPT_POSTFIELDS=>$po, CURLOPT_HTTPHEADER=>["Authorization: Bearer ".$jd['api_key'], "Content-Type: application/json"]]);
    $resp = curl_exec($ch); $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    
    if ($httpcode == 200) {
        $r=json_decode($resp,true); $t_content = $r['choices'][0]['message']['content'];
        $j=json_decode($t_content, true);
        if (!$j && preg_match('/\{.*\}/s', $t_content, $mat)) $j=json_decode($mat[0],true);
        if ($j) {
            $jd['latest_signal'] = ['type'=>$j['signal_type'] ?? 'none','time'=>time(), 'summ'=>$j['summary'] ?? '', 'imp'=>$j['importance']??0];
            if ((int)$j['importance'] >= (int)$jd['importance'] && !empty($jd['bot_token']) && !empty($jd['chat_id'])) {
                 $msg_fin = urlencode($j['telegram_message'] ?? 'Сигнал без сообщения.');
                 fetch_g_url("https://api.telegram.org/bot{$jd['bot_token']}/sendMessage?chat_id={$jd['chat_id']}&parse_mode=HTML&text={$msg_fin}");
                 add_g_log($jd, "Telegram Sent (Importance: {$j['importance']})", "success");
            } else { add_g_log($jd, "Low imp signal ignored", "info"); }
        } else { add_g_log($jd, "No Valid JSON AI Output", "error"); }
    } else { add_g_log($jd, "LLM Failure HTTP {$httpcode}", "error"); }
}

array_unshift($jd['runs'],['dur'=>round(microtime(true)-$st, 2), 'time'=>date('Y-m-d H:i')]);
if(count($jd['runs']) > 15) $jd['runs']=array_slice($jd['runs'],0,15);
file_put_contents($data_file, json_encode($jd, JSON_PRETTY_PRINT));
echo "CRON OK (".round(microtime(true)-$st, 2)."s). Logs Appended.";