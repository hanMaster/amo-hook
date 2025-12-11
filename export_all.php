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

function get_cf($id, $obj)
{
    $key = array_search($id, array_column($obj['custom_fields'], 'id'));
    if ($key !== FALSE) {
        return array_column($obj['custom_fields'][$key]['values'], 'value')[0];
    }
}

try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    echo '.env file not found';
    return;
}

$amo = new Client(
    $_ENV['AMO_DOMAIN'],
    $_ENV['AMO_LOGIN'],
    $_ENV['AMO_APIKEY']
);

$result = ['users' => []];
$offset = 0;

echo date("Y-m-d H:i:s") . ' Started'  . PHP_EOL;

do {
    $leads = $amo->lead->apiList(['status' => [80709866], 'limit_rows' => 500, 'limit_offset' => $offset]);
    foreach ($leads as $lead) {
         print_r('process lead: '. $lead['id'] . PHP_EOL);
//         die;
        if ($lead['main_contact_id'] && get_cf(1578533, $lead)) {
            $links = $amo->links->apiList([
                'from' => 'leads',
                'from_id' => $lead['id'],
                'to' => 'contacts']);
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
                    print_r('user found: '. $contact['id'] . PHP_EOL);
                }
            }
        }
    }
    $offset += 500;
} while (count($leads) == 500);


file_put_contents('export.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo date("Y-m-d H:i:s") . ' Finished';