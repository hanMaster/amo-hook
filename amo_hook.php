<?php

use AmoCRM\Client;

require_once __DIR__ . '/amocrm.phar';

/**
 * @throws Exception
 */
function loadEnv($path)
{
    if (!file_exists($path)) {
        throw new Exception('.env file not found');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // пропускаем комментарии
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

function log_to_file($text)
{
    $fp = fopen(__DIR__ . '/amo_hook.log', 'a');
    fwrite($fp, date("Y-m-d H:i:s") . "\t" . $text . PHP_EOL);
    fclose($fp);
}

function get_cf($id, $obj)
{
    $key = array_search($id, array_column($obj['custom_fields'], 'id'));
    if ($key !== FALSE) {
        return array_column($obj['custom_fields'][$key]['values'], 'value')[0];
    }
}


function process_lead($amo, $lead)
{
    // Проверка этапа воронки (нам нужна этап "Передача ЖК")
    if ($lead['status_id'] !== 80709866) {
        log_to_file('Сделка из неправильного этапа');
        return;
    }

    // Проверка установки ID помещения
    $object_id = get_cf(1578533, $lead);
    if (!isset($object_id)) {
        log_to_file('В сделке не заполнен ID помещения');
        return;
    }

    // Запрос контактов сделки
    $links = $amo->links->apiList([
        'from' => 'leads',
        'from_id' => $lead['id'],
        'to' => 'contacts',
    ]);
    if (count($links) == 0) {
        log_to_file('В сделке нет контактов');
        return;
    }

    // Парсинг контактов сделки, выбираем только собственников
    $result = ['users' => []];
    foreach ($links as $link) {
        $contact = $amo->contact->apiList(['id' => $link['to_id']])[0];
        $is_owner = get_cf(1632282, $contact);
        if (isset($is_owner) && $is_owner) {
            $result['users'][] = [
                'Name' => get_cf(1578455, $contact),
                'Surname' => get_cf(1578453, $contact),
                'Patronymic' => get_cf(1578479, $contact),
                'Phone' => get_cf(1396595, $contact),
                'Email' => get_cf(1396597, $contact),
                'Clientid' => intval(get_cf(1578533, $lead)),
            ];
        }
    }

    if (empty($result['users'])) {
        log_to_file('Нет контактов, удовлетворяющих условиям');
        return;
    }

    $res_json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    log_to_file('Сформирован запрос: ' . print_r($res_json, true));
    $opts = ['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $res_json
    ]];
    $context = stream_context_create($opts);
    $result = file_get_contents($_ENV['SYNC_URL'], false, $context);
    log_to_file('Получен ответ: ' . $result);
}

$data = $_REQUEST;

try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    log_to_file('.env file not found');
    return;
}

//log_to_file('Сработал WEBHOOK: ' . print_r($data, true));
log_to_file('Сработал WEBHOOK');

$amo = new Client(
    $_ENV['AMO_DOMAIN'],
    $_ENV['AMO_LOGIN'],
    $_ENV['AMO_APIKEY']
);

if (array_key_exists('leads', $data)) {
    foreach (['update', 'add'] as $action) {
        if (array_key_exists($action, $data['leads'])) {
            foreach ($data['leads'][$action] as $lead) {
                process_lead($amo, $lead);
            }
        }
    }
}
