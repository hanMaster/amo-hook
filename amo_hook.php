<?php
require_once __DIR__ . '/amocrm.phar';

function log_to_file($text) {
    $fp = fopen(__DIR__.'/amo_hook.log', 'a');
    fwrite($fp, date("Y-m-d H:i:s")."\t".$text.PHP_EOL);
    fclose($fp);
}

function get_cf($id, $obj) {
    $key = array_search($id, array_column($obj['custom_fields'], 'id'));
    if ($key !== FALSE) {
        return array_column($obj['custom_fields'][$key]['values'], 'value')[0];
    }
}



function process_lead($amo, $lead) {
    if ($lead['status_id'] == 65830426) {
        if (get_cf(1578533, $lead)) {
            $links = $amo->links->apiList([
                'from' => 'leads',
                'from_id' => $lead['id'],
                'to' => 'contacts',
            ]);
            if (count($links) > 0) {
                $result = [ 'users' => [] ];
                foreach ($links as $link) {
                    $contact = $amo->contact->apiList(['id' => $link['to_id']])[0];
                    if (get_cf(1632282, $contact)) {
                        array_push($result['users'], [
                            'Name' => get_cf(1578455, $contact),
                            'Surname' => get_cf(1578453, $contact),
                            'Patronymic' => get_cf(1578479, $contact),
                            'Phone' => get_cf(1396595, $contact),
                            'Email' => get_cf(1396597, $contact),
                            'Clientid' => intval(get_cf(1578533, $lead)),
                        ]);
                    }
                }

                // $conatct_id = array_filter($links, function($el) {
                //     return $el['main_contact'] == 1;
                // })[0];
                // $contact = $amo->contact->apiList([ 'id' => $conatct_id])[0];
                // log_to_file('Найден контакт из сделки: '.print_r($contact, true));

                // $result['users'] =[[
                //     'Name' => get_cf(1578455, $contact),
                //     'Surname' => get_cf(1578453, $contact),
                //     'Patronymic' => get_cf(1578479, $contact),
                //     'Phone' => get_cf(1396595, $contact),
                //     'Email' => get_cf(1396597, $contact),
                //     'Clientid' => intval(get_cf(1578533, $lead)),
                //   ]];

                $res_json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                log_to_file('Сформирован запрос: '.print_r($res_json, true));
                $opts = [ 'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/json',
                    'content' => $res_json
                ]];
                $context  = stream_context_create($opts);
                $result = file_get_contents('sync_url', false, $context);
                log_to_file('Получен ответ: ' . $result);


            } else {
                log_to_file('В сделке нет контактов');
            }
        } else {
            log_to_file('В сделке не заполнен ID помещения');
        }
    } else {
        log_to_file('Сделка из неправильного этапа');
    }
}

function process_contact($amo, $contact) {

}

$data = $_REQUEST;
log_to_file('Сработал WEBHOOK: '.print_r($data, true));

$amo = new \AmoCRM\Client('domain', 'login', 'apikey');

if (array_key_exists('leads', $data)) {
    foreach (['update', 'add'] as $action) {
        if (array_key_exists($action, $data['leads'])) {
            foreach ($data['leads'][$action] as $lead) {
                process_lead($amo, $lead);
            }
        }
    }
}

if (array_key_exists('contacts', $data)) {
    foreach (['update', 'add'] as $action) {
        if (array_key_exists($action, $data['contacts'])) {
            foreach ($data['contacts'][$action] as $contact) {
                process_contact($amo, $contact);
            }
        }
    }
}