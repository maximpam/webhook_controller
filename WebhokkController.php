<?php

namespace App\Controllers;

use App\API;
use App\DBConnection;
use App\InstagramApi;
use App\Request;
use MongoDB\Driver\Server;
use Sendpulse\RestApi\ApiClient;
use Sendpulse\RestApi\Storage\FileStorage;

class WebHookController extends BaseController
{
    public function __construct($request)
    {
        $this->index($request);
    }

    public function index(Request $request)
    {
        match(true){
            $request->getData()['hub_challenge'] => $this->hub_challenge($request),
            default => $this->bot_cases()
        };
    }
    private function hub_challenge($request){
        echo $request->getData()['hub_challenge'];
    }



    private function bot_cases(){
        $json = file_get_contents('php://input');
        file_put_contents('webhook.log', $json.PHP_EOL, FILE_APPEND);

        //send data to webhook handler
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://webhook.site/b487cc18-6e55-4710-aac2-509db60da37a',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>$json,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;

        //

        $json = json_decode($json);
        $income_text = $json->entry[0]->messaging[0]->message->text;
        $income_text = strtolower($income_text);
        $instagram = new InstagramApi();
        beginning:
        $user_id = $json->entry[0]->messaging[0]->sender->id;

        $connection = new DBConnection();
        $sql =  'SELECT * FROM users WHERE id = :id AND is_agent = :is_agent';
        $is_agent = $connection::$pdo->prepare($sql);
        $is_agent->execute([
            "id"=> $user_id,
            "is_agent" => 1
        ]);

        match(true){
            $json->entry[0]->messaging[0]->postback->payload == "AGENT_STOP" => $this->supportStop($instagram, $json),
            $is_agent->rowCount()===1 => $this->checkSupport($instagram, $json),
            $json->entry[0]->messaging[0]->message->is_echo => $this->is_echo(),
            $json->entry[0]->messaging[0]->message->is_deleted => $this->is_echo(),
            isset($json->entry[0]->messaging[0]->referral)=> $this->referral($json),
	        $json->entry[0]->messaging[0]->message->attachments[0]->type == "image" => $this->is_echo(),
            $json->entry[0]->messaging[0]->postback->payload == "CARE_HELP",
                str_contains($income_text,"agent"),
                str_contains( $income_text, "help"),
                str_contains( $income_text, "support") => $this->support($instagram, $json),
            $json->entry[0]->messaging[0]->message->attachments[0]->type == 'story_mention' => $this->stories($instagram, $json),
            $json->entry[0]->messaging[0]->postback->paylaod == "greeting" => $this->greeting($instagram, $json),
            default => $this->simple($instagram, $json)
        };


    }

    private function is_echo(){
        echo "this is echo"; die;
    }

    private function referral($json){
        $ref=json_decode(base64_decode($json->entry[0]->messaging[0]->referral->ref));
        $user_id = $json->entry[0]->messaging[0]->sender->id;
        $connection = new DBConnection();
        $sql =  'SELECT * FROM users WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id
        ]);

        $ads_id = $ref->a ?? 0;
        $ref_user = $ref->u ?? 0;

        if($query->rowCount()===0){
            $sql = 'INSERT INTO users (id, ads_id, ref_user) VALUES (:id, :ads_id, :ref_user)';
            //send data to Telegram chanel
            $url = 'https://api.telegram.org/КЕЕЕЕ/sendMessage?chat_id=-1001857698694&text='.$user_id.'%20-%20ad_id='.$ads_id.'%20-%20user_ref='.$ref_user;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET'
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            echo $response;
            //
        } else {
            $sql = 'UPDATE users SET ads_id=:ads_id, ref_user=:ref_user WHERE id=:id';
        }
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id,
            "ads_id"=>$ads_id,
            "ref_user"=>$ref_user
        ]);


        echo "this is referral"; die;
    }

    private function support($instagram, $json){
        $user_id = $json->entry[0]->messaging[0]->sender->id;
        $timestamp = time();
        $connection = new DBConnection();
        $sql =  'UPDATE users SET is_agent = 1, support_start = :timestamp WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "timestamp" => $timestamp,
            "id"=> $user_id
        ]);

        $template = [
            "recipient"=>[
                "id"=>$user_id
            ],
            "message"=>[
                "attachment"=>[
                    "type"=>"template",
                    "payload"=>[
                        "template_type"=>"generic",
                        "elements"=>[
                            [
                                "title"=>"Привіт, перемикаємо вас у чат службу підтримки...",
                                "subtitle"=>" Через 10хв після останього повідомлення - чат автоматично перемкнеться в попередній режим, якщо бажаєте повернутись в попередній режим вже - оберіть пункт “Завершити”",
                                "buttons"=>[
                                    [
                                        "type"=>"postback",
                                        "title"=>"Завершити",
                                        "payload"=>"AGENT_STOP"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        var_dump($instagram->sendMessage($template, true));

        $template = [
            "recipient"=>[
                "id"=>$user_id
            ],
            "message"=>[
                "attachment"=>[
                    "type"=>"template",
                    "payload"=>[
                        "template_type"=>"generic",
                        "elements"=>[
                            [
                                "title"=>"Служба підтримки Pimble вітає вас!",
                                "subtitle"=>"Напишіть ваше зверення після цього повідомлення, ми опрацюємо його в найкоротші терміни",
                            ]
                        ]
                    ]
                ]
            ]
        ];

        var_dump($instagram->sendMessage($template, true));
        echo 'message sent';die;
    }

    private function supportStop($instagram, $json){
        $user_id = $json->entry[0]->messaging[0]->sender->id;

        $connection = new DBConnection();
        $sql =  'UPDATE users SET is_agent = 0 WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id
        ]);

        $template = [
            "recipient"=>[
                "id"=>$user_id
            ],
            "message"=>[
                "attachment"=>[
                    "type"=>"template",
                    "payload"=>[
                        "template_type"=>"generic",
                        "elements"=>[
                            [
                                "title"=>"Дякуємо, бот далі працює в штатному режимі",
                            ]
                        ]
                    ]
                ]
            ]
        ];

        var_dump($instagram->sendMessage($template, true));
        echo 'message sent';die;
    }


    private function stories($instagram, $json){

        //check if user already in DB if not create new row START

        $message_id = $json->entry[0]->messaging[0]->message->mid;
        if(empty($message_id)){
            $message_id = $json->entry[0]->messaging[0]->postback->mid;

        }

        $response = $instagram->getMessage($message_id);
        $username = $response->from->username;
        $user_id = $response->from->id;
        $connection = new DBConnection();
        $sql =  'SELECT * FROM users WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id
        ]);

        if($query->rowCount()===0){
            $sql = 'INSERT INTO users (id, username, last_message_id) VALUES (:id, :username, :last_message_id)';
            $message_text = "Привіт, вітаємо у світі Pimble! Наш тест допоможе більше дізнатись про свої сильні та слабкі сторони. Для максимально точного результату, ми додали багато коротких запитань з варіантами відповідей, тому проходження тесту не займає більше 3хв";
        } else {
            $sql = 'UPDATE users SET username=:username, last_message_id=:last_message_id WHERE id=:id';
            $message_text = "Бажаєте пройти тест?";
        }
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id,
            "username"=>$username,
            "last_message_id"=>$message_id
        ]);

        // END

        $user_id = $json->entry[0]->messaging[0]->sender->id;
        $template = [
            "recipient"=>[
                "id"=>$user_id
            ],
            "message"=>[
                "attachment"=>[
                    "type"=>"template",
                    "payload"=>[
                        "template_type"=>"generic",
                        "elements"=>[
                            [
                                "title"=>"Вітаємо у світі Pimble!",
				 "subtitle"=>"Наш тест допоможе більше дізнатись про свої сильні та слабкі сторони, проходження тесту не займає більше 3-ох хвилин",
                                "buttons"=>[
                                    [
                                        "type"=>"web_url",
                                        "url"=>"https://pimble.club/?user_id=$user_id",
                                        "title"=>"Пройти тест"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        var_dump($instagram->sendMessage($template, true));
        $log_message = 'User_id: '.$user_id. '; Username: '.$username.'; Text: '.$message_text.'; DateTime: '.date('Y-m-d H:i:s');
        file_put_contents('income_message.log', $log_message.PHP_EOL, FILE_APPEND);
        echo 'message sent';die;
    }

    private function greeting($instagram, $json){


        $message_id = $json->entry[0]->messaging[0]->postback->mid;
        $this->defaultMessage($instagram, $json, $message_id);
    }

    private function simple($instagram, $json){


        $message_id = $json->entry[0]->messaging[0]->message->mid;
        if(empty($message_id)){
            $message_id = $json->entry[0]->messaging[0]->postback->mid;
        }
        $this->defaultMessage($instagram, $json, $message_id);
    }

    private function defaultMessage($instagram, $json, $message_id){
        $response = $instagram->getMessage($message_id);
        $username = $response->from->username;
        $user_id = $response->from->id;
        $connection = new DBConnection();
        $sql =  'SELECT * FROM users WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id
        ]);

        if($query->rowCount()===0){
            $sql = 'INSERT INTO users (id, username, last_message_id) VALUES (:id, :username, :last_message_id)';
            $message_text = "Привіт, вітаємо у світі Pimble! Наш тест допоможе більше дізнатись про свої сильні та слабкі сторони. Для максимально точного результату, ми додали багато коротких запитань з варіантами відповідей, тому проходження тесту не займає більше 3хв";
            //send data to Telegram chanel
            $url = 'https://api.telegram.org/ЕЕЕЕЕЕЕ:ЕЕЕЕЕ/sendMessage?chat_id=-1001857698694&text='.$user_id.'%20-%20Send_text='.$response->message;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET'
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            echo $response;
            //
        } else {
            $sql = 'UPDATE users SET username=:username, last_message_id=:last_message_id WHERE id=:id';
            $message_text = "Бажаєте пройти тест?";
        }
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id,
            "username"=>$username,
            "last_message_id"=>$message_id
        ]);

        $template = [
            "recipient"=>[
                "id"=>$user_id
            ],
            "message"=>[
                "attachment"=>[
                    "type"=>"template",
                    "payload"=>[
                        "template_type"=>"generic",
                        "elements"=>[
                            [
                                "title"=> "Вітаємо у світі Pimble!",
                                "subtitle"=>"Наш тест допоможе більше дізнатись про свої сильні та слабкі сторони, проходження тесту не займає більше 3-ох хвилин",
                                "buttons"=>[
                                    [
                                        "type"=>"web_url",
                                        "url"=>"https://pimble.club/?user_id=$user_id",
                                        "title"=>"Пройти тест"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        var_dump($instagram->sendMessage($template, true));
        $log_message = 'User_id: '.$user_id. '; Username: '.$username.'; Text: '.$message_text.'; DateTime: '.date('Y-m-d H:i:s');
        file_put_contents('income_message.log', $log_message.PHP_EOL, FILE_APPEND);
        echo 'message sent';
    }

    private function checkSupport($instagram, $json){
        $user_id = $json->entry[0]->messaging[0]->sender->id;
        $connection = new DBConnection();
        $sql =  'SELECT * FROM users WHERE id = :id';
        $query = $connection::$pdo->prepare($sql);
        $query->execute([
            "id"=> $user_id
        ]);
        $result = $query->fetch();
        $start_timestamp = $result["support_start"];
        $current_timestamp = time();

        $difference =$current_timestamp - $start_timestamp;

        if ($difference > 600){

            $connection = new DBConnection();
            $sql =  'UPDATE users SET is_agent = 0 WHERE id = :id';
            $query = $connection::$pdo->prepare($sql);
            $query->execute([
                "id"=> $user_id
            ]);
            $this->bot_cases();

        } else {
            $this->is_echo();
        }
    }

    }
