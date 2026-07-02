<?php
require_once __DIR__ . '/amocrm.phar';

function log_to_file($text) {
    echo $text.PHP_EOL;
    $fp = fopen(__DIR__.'/createlead.log', 'a');
    fwrite($fp, date("Y-m-d H:i:s")."\t".$text.PHP_EOL);
    fclose($fp);
}

function find_contact($amo, $data){
    if ($data['phone']){
        $amo_contacts = $amo->contact->apiList(['query' => $data['phone']]);
        if (count($amo_contacts) > 0){
            $contact = $amo_contacts[0];
            log_to_file('Найден контакт: '.print_r($contact, true));
            log_to_file('Найден контакт по номеру телефона:'.PHP_EOL.print_r($contact, true));
            return $contact;
        } 
    }
    if ($data['email']){
        $amo_contacts = $amo->contact->apiList(['query' => $data['email']]);
        if (count($amo_contacts) > 0){
            $contact = $amo_contacts[0];
            log_to_file('Найден контакт: '.print_r($contact, true));
            log_to_file('Найден контакт по email:'.PHP_EOL.print_r($contact, true));
            return $contact;
        } 
    }    
}

function add_task($amo, $lead){
    $task = $amo->task;
    $task['element_id'] = $lead['id'];
    $task['element_type'] = \AmoCRM\Models\Note::TYPE_LEAD;
    // $task['date_create'] = '-2 DAYS';
    $task['task_type'] = 1;
    $task['text'] = "Связаться с клиентом";
    $task['responsible_user_id'] = $lead['responsible_user_id'];
    $task['complete_till'] = '+1 DAY';
    $id = $task->apiAdd();
    log_to_file('Создана задача: '.print_r($id, true));
}

function create_note($amo, $lead_id, $data) {
    $note = $amo->note;
    $note['element_id'] = $lead_id;
    $note['element_type'] = 2; 
    $note['note_type'] = 4;
    $note['text'] = $data['comment'];
    $note->apiAdd();       
}

function create_lead_and_note($amo, $contact_id, $data){
    $lead = $amo->lead;
    $lead['name'] = 'Заявка с мобильного приложения';
    $lead['tags'] = 'Мобильное приложение';
    $lead['status_id'] = 67867994; 
    //$lead->addCustomField(777777, 'test');
    $lead_id = $lead->apiAdd();
    log_to_file("lead id = #{$lead_id}");
    
    $link = $amo->links;
    $link['from'] = 'leads';
    $link['from_id'] = $lead_id;
    $link['to'] = 'contacts';
    $link['to_id'] = $contact_id;
    $link->apiLink();

    create_note($amo, $lead_id, $data);    
}

$payload = file_get_contents('php://input');
//$payload = '{"name": "Иван Иванов","phone": "79876543210","email": "example@post.com","comment": "Какая-то информация"}';
$data = json_decode($payload, true);
log_to_file('Сработал WEBHOOK: '.print_r($data, true));

$amo = new \AmoCRM\Client('dnscity', 'extertal@dnsgroup.ru', 'ab788eb2543b1402feee0d6e3923d65a30008b2e');

$contact = find_contact($amo, $data);

if ($contact) {
    if ( $contact['linked_leads_id'] && count($contact['linked_leads_id'])>0 ) {
        $leads = $amo->lead->apiList(['id'=>$contact['linked_leads_id']]);
        $open_leads = array_filter($leads, function($el) {
            return $el['status_id'] != 142 && $el['status_id'] != 143;
        });
        log_to_file('Открытые сделки:'.PHP_EOL.print_r($data, true));
        if (count($open_leads)==0){
            log_to_file('Нет активных сделок');
            create_lead_and_note($amo, $contact['id'], $data);
        } else {
            // switch ($open_leads[0]['stage_id']) {
            //     case 63003106:
            //     case 63975250:
            //     case 63835346:
            //     case 62508722:
            //     case 62108326:
            //     case 62108330:
            //     case 62508726:
            //     case 62508730:
            //     case 62508734:
            //     case 62508738:
            //     case 62508742:
            //     case 62508746:
            //     case 62508750:
            //     case 62572134:
            //         log_to_file('Перемещаем в неразобраное');
            //         $open_leads[0]['stage_id'] = 62108322;
            //         $open_leads[0]->apiUpdate($open_leads[0]['id'], 'now');                
            //         break;
    
            //     case 62508754:
            //     case 63003110:
            //     case 62508758:
            //     case 62508762:
            //     case 62508766:
            //     case 62508770:
            //     case 62508774:
            //     case 62508778:
            //     case 62508782:
            //     case 62508786:
            //     case 62508790:
            //     case 62508794:
            //         log_to_file('Ставим задачу');
            //         add_task($amo, $open_leads[0]);
            //         break;            
            //     default:
            //         break;
            // }        
            // create_note($amo, $open_leads[0]['id'], $data);    
        }    
    } else {
        log_to_file('Нет сделок, создаем');
        create_lead_and_note($amo, $contact['id'], $data);
    }        
} else {
    log_to_file('Ничего не найдено, создаем новый контакт');
    $contact = $amo->contact;
    $contact['name'] = $data['name'];
    $contact->addCustomField(1396595, $data['phone'], 'WORK');
    $contact->addCustomField(1396597, $data['email'], 'WORK');
    $contact_id = $contact->apiAdd();
    log_to_file('Создан контакт: '.print_r($contact_id, true));    
    create_lead_and_note($amo, $contact_id, $data);
}