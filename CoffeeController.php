<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Team;

class CoffeeController extends Controller
{
    public function index(Request $request){
        if ($request->token != env('SLACK_VERIFICATION_TOKEN')) {
            return 'token invalid';
        }

        $oauth_token = Team::where('team_id', $request->team_id)->first()->token;

        //TODO: fix different "@" notifications for channel, here & everyone
        if ($this->_check_for_needles($request->text, ['channel', 'here', 'everyone']) && $request->channel_name != 'privategroup') {
            // het is een public channel


            $channel_data = json_decode($this->get_channel_members($request->channel_id, $oauth_token));
            $channel_users = $channel_data->channel->members;

            $this->send_group_members($channel_users, $request->user_id, $request->channel_id, $oauth_token);
//            print_r('banaan'); die();

        } elseif($this->_check_for_needles($request->text, ['channel', 'here', 'everyone']) && $request->channel_name == 'privategroup') {
            // het is een private channel
            $channel_data = json_decode($this->get_private_channel_members($request->channel_id, $oauth_token));
            $channel_users = $channel_data->channel->members;

            $this->send_group_members($channel_users, $request->user_id, $request->channel_id, $oauth_token);
        } else{
            // het is een user
            preg_match("/([A-Z])\w+/", $request->text, $matches);
            // krijg de user id uit de text
            if ($matches) {
                $this->send_personal_notification($matches[0], $request->user_id, $request->channel_id, $oauth_token);
            } else{
                return "Vul een @user of @channel in.";
            }
        }
        return "Wat aardig dat je koffie wil halen! Je krijgt zo te horen wie koffie wil.";
    }

    public function choose(Request $request){
        $request_data = json_decode($request->payload);
        $user_id = $request_data->user->id;
        $channel = $request_data->channel->id;

        $oauth_token = Team::where('team_id', $request_data->team->id)->first()->token;
        
        $action_data = explode( ',' , $request_data->actions[0]->value );
        $sender_id = $action_data[1];

        if ($action_data[0] === 'no') {
            $text = 'Awww, oké.';
        } else{
            $text = 'Goeie keus!';
        }

        $this->send_coffee_update($sender_id, $user_id, $action_data[0], $channel, $oauth_token);

        echo $text;
    }

    public function send_coffee_update($user_id, $coffee_chooser, $choice, $channel, $oauth_token){
        $data = new \stdClass();
        $data->token = env('SLACK_CLIENT_SECRET');
        $data->channel = $channel;
        $data->user = $user_id;

        if ($choice == 'no') {
            $data->text = "<@" . $coffee_chooser . "> hoeft geen bakje.";
        } else{
            $data->text = "<@" . $coffee_chooser . "> wil wel een bakje "."$choice".".";
        }

        $json = json_encode($data);
        return $this->send_slack_api_request('https://slack.com/api/chat.postEphemeral', $json, true, 'application/json', $oauth_token);
    }

    public function get_private_channel_members($channel_id, $oauth_token){
        $data = new \stdClass();
        $data->token = $oauth_token;
        $data->channel = $channel_id;

        $urlencoded = http_build_query($data);
        return $this->send_slack_api_request('https://slack.com/api/conversations.info', $urlencoded, false, 'application/x-www-form-urlencoded', $oauth_token);
    }

    public function get_channel_members($channel_id, $oauth_token){
        $data = new \stdClass();
        $data->token = $oauth_token;
        $data->channel = $channel_id;

        $urlencoded = http_build_query($data);
        return $this->send_slack_api_request('https://slack.com/api/channels.info', $urlencoded, false, 'application/x-www-form-urlencoded', $oauth_token);
    }

    public function send_group_members($channel_users, $user_id, $channel_id, $oauth_token){
        foreach ($channel_users as $user ) {
            // ga door alle channel users heen behalve degene die het stuurde
            if (strpos($user, $user_id) !== false) {
                continue;
            } else{
                $this->send_personal_notification($user, $user_id, $channel_id, $oauth_token);
            }
        }
    }

    public function send_personal_notification($user_id, $sender, $channel, $oauth_token){
        $coffee_buttons = $this->generate_coffee_option_buttons($sender);

        $no_button = new \stdClass();
        $no_button->name = 'choice';
        $no_button->text = 'Nee';
        $no_button->type = 'button';
        $no_button->value = 'no,' . $sender;

        $attachment = new \stdClass();
        $attachment->title = 'Wil je koffie?';
        $attachment->callback_id = 'coffee_choice';
        $attachment->color = '#3AA3E3';
        foreach ($coffee_buttons as $button) {
            $attachment->actions[] = $button;
        }
        $attachment->actions[] = $no_button;

        $data = new \stdClass();
        $data->attachments[] = $attachment;
        $data->token = env('SLACK_CLIENT_SECRET');
        $data->channel = $channel;
        $data->user = $user_id;

        $json = json_encode($data);

        $result = $this->send_slack_api_request('https://slack.com/api/chat.postEphemeral', $json, true, 'application/json', $oauth_token);
        return $result;
    }

    public function send_slack_api_request($url, $data, $post, $content_type, $oauth_token){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, $post );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: ' . $content_type,
            'Authorization: Bearer ' . $oauth_token
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $result = trim(curl_exec($ch)); // Prepare response
        curl_close($ch); // Close

        return $result;
    }

    public function auth(Request $request){
        if ($request->code) {
            $data = new \stdClass();
            $data->client_id = env('SLACK_CLIENT_ID');
            $data->client_secret = env('SLACK_CLIENT_SECRET');
            $data->code = $request->code;
            $data->redirect_uri = 'https://rubendroogh.nl/coffee/authorize';

            $urlencoded = http_build_query($data);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, 'https://slack.com/api/oauth.access');
            curl_setopt($ch, CURLOPT_POST, true );
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: ' . 'application/x-www-form-urlencoded',
                'Authorization: Basic ' . env('SLACK_CLIENT_SECRET')
            ));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $urlencoded);

            $result = trim(curl_exec($ch));
            curl_close($ch);
            
            $data = json_decode($result);
            $existing_team = Team::where('team_id', $data->team_id);

            if(empty($existing_team)) {
                Team::create([
                    'team_id'=> $data->team_id,
                    'token'=> $data->access_token
                ]);
            } else {
                Team::where('team_id', $data->team_id)->update([
                    'token' => $data->access_token
                ]);
            }


            return redirect()->route('coffee')->with('message', 'Bedankt voor het toevoegen van Coffeeboi! ☕');

        } elseif($request->error == 'access_denied'){
            return redirect()->route('coffee')->with('message', 'Oké, misschien een volgende keer! ☕');
        } else{
            return 'no code provided';
        }
    }

    /** -----------------------------------------------------------
     * Check_for_needles
     * - loops over all needles and checks if they are present
     * - if present, the function returns true
     * - if NOT present, the function returns false
     *
     * @param $haystack array
     * @param $needles array
     * @return boolean
     */
    private function _check_for_needles($haystack, $needles) {
        foreach ($needles as $needle) {
            if(strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** -----------------------------------------------------------
     * Generate_coffee_option_buttons
     * - genereates buttons for each coffee in the $coffees array
     *
     * @param $sender
     * @return array
     */
    private function generate_coffee_option_buttons($sender) {
        $coffees = ['zwart', 'zwart met suiker', 'cappuchino', 'cappuchino met suiker'];

        $coffee_buttons = [];
        foreach ($coffees as $coffee) {
            $coffee_buttons[$coffee.'_button'] = new \stdClass();
            $coffee_buttons[$coffee.'_button']->name  = 'choice';
            $coffee_buttons[$coffee.'_button']->text  = ucfirst($coffee);
            $coffee_buttons[$coffee.'_button']->type  = 'button';
            $coffee_buttons[$coffee.'_button']->value = $coffee.','.$sender;
        }

        return $coffee_buttons;
    }
}
