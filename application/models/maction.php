<?php
Class MAction extends CI_Model{
	function __construct()
    {
        // Call the Model constructor
        parent::__construct();
        $this->load->model("mluck");
        $this->load->model("mwoodcutting");
        $this->load->model("mfishing");
        $this->load->model("mmining");
        $this->load->model("mcooking");
    }

    public function get_name_by_id($id){
    	$action = new Action($id);

    	return $action->name;
    }
   	public function get_timer($id){
   		$action = new Action($id);
   		$data = array();

   		$data = $action->to_array();
   	}
	public function get_by_location($location_id){
		$location = new Location($location_id);
		$action = $location->action;
		$data = array();

		foreach($action
				->include_join_fields()
				->get() as $_action){
			$data[] = $_action->to_array();
		}

		return $data;
	}

	public function do_action($player_id, $action_id){
		$action = new Action($action_id);
		$player = new Player($player_id);

		
		// Check if item is needed
		if($action->item_used_1_id > 0){
			// Check item in inventory, and if enough items
			if(!$this->mitem->in_inventory($player_id, $action->item_used_1_id, $action->item_used_1_amount)){
				return "material";
			}	
		}
		
		// Check if item is needed
		if($action->item_used_2_id > 0){
			// Check item in inventory, and if enough items
			if(!$this->mitem->in_inventory($player_id, $action->item_used_2_id, $action->item_used_2_amount)){
				return "material";
			}	
		}

		// Check if have required lvl.	
		if($this->mplayer->check_required_level($player_id,$action->skill_id, $action->level_required)){
			// Check if item is equiped
			if($this->mitem->check_subtype_equiped($player_id, $action->item_subtype_required_id)){
				$player->action_id = $action_id;
				$player->save();

				$timer = $this->calculate_timer($player_id, $action_id);

				return $timer;
			} else {
				return "item";
			}
		} else {
			return "level";
		}
	}

	public function calculate_timer($player_id, $action_id){
		$action = new Action($action_id);

		if($action->skill_id == "3"){
			$timer = $this->mluck->calculate_timer($player_id, $action_id);
		}
		if($action->skill_id == "4"){
			$timer = $this->mwoodcutting->calculate_timer($player_id, $action_id);
		}
		if($action->skill_id == "5"){
			$timer = $this->mfishing->calculate_timer($player_id,$action_id);
		}
		if($action->skill_id == "6"){
			$timer = $this->mmining->calculate_timer($player_id,$action_id);	
		}
		if($action->skill_id == "7"){
			$timer = $this->mcooking->calculate_timer($player_id,$action_id);	
		}
		return $timer;
	}

	public function complete($player_id, $action_id){
		$data = array();

		if($this->mplayer->check_action_end($player_id)){
			$player = new Player($player_id);
			$player->action_id = 0;
			$player->save();

			$action = new Action($action_id);
			$reward = new Action_Reward();
			$reward->where("action_id",$action_id)->get();

			if(isset($action->item_used_1_id)){
				$use_item_1 = $this->mitem->delete_item_from_inventory($player_id, $action->item_used_1_id, $action->item_used_1_amount);
			}
			if(isset($action->item_used_2_id)){
				$use_item_2 = $this->mitem->delete_item_from_inventory($player_id, $action->item_used_2_id, $action->item_used_2_amount);
			}	

			if($action->skill_id == "7"){
				$data = $this->mcooking->calculate_reward_change($player_id, $reward->id);
			} else {
				$data["items"] = $this->calculate_reward_change($player_id, $reward->id);
				$data["exp"] = $this->calculate_experience($player_id,$reward->id);
			}

			$data["currency"] = $this->calculate_currency($player_id, $reward->id);
			
		}

		return $data;
	}

	public function calculate_reward_change($player_id, $reward_id){
		$reward = new Action_Reward($reward_id);
		$player = new Player($player_id);
		$action = new Action($reward->action_id);

		if($action->skill_id == 7){
			$cooking = $player->skill->where("id","7")->include_join_fields()->get();
			$chance_increase = $cooking->join_level - $action->level_required;
		} else {
			$luck = $player->skill->where("id", "3")->include_join_fields()->get();
			$chance_increase = explode(".", $luck->join_level * 0.25)[0];
		}
	

		$data = array();

		if($reward->item_1_id > 0 && $reward->item_1_chance > 0){
			$rand = rand(0,100);
			if($rand <= ($reward->item_1_chance + $chance_increase)){
				$amount = explode("::", $reward->item_1_amount);
				$rand = rand(0, count($amount));

				$rand--;
				if($rand == -1){$rand = 0;}

				$item_amount = $amount[$rand];
				
				$give = $this->mitem->give_item($player_id, $reward->item_1_id, $item_amount);

				$item = new Item($reward->item_1_id);
				$data["item_1"] = "You have obtained " . $item_amount . " " . $item->name . "."; 
			}	
		}
		if($reward->item_2_id > 0 && $reward->item_2_chance > 0){
			$rand = rand(0,100);
			if($rand <= ($reward->item_2_chance + $chance_increase)){
				$amount = explode("::", $reward->item_2_amount);
				$rand = rand(0, count($amount));

				$rand--;
				if($rand == -1){$rand = 0;}

				$item_amount = $amount[$rand];
				
				$give = $this->mitem->give_item($player_id, $reward->item_2_id, $item_amount);

				$item = new Item($reward->item_2_id);
				$data["item_2"] = "You have obtained " . $item_amount . " " . $item->name . "."; 
			}	
		}

		return $data;
	}

	public function calculate_experience($player_id, $reward_id){
		$reward = new Action_Reward($reward_id);
		$action = new Action($reward->action_id);
		$exp = 0;

		$rand = rand(0,100);
		
		if($rand <= $reward->exp_chance){
			$player = new Player($player_id);
			$skill = $player->skill->where("id", $action->skill_id)->include_join_fields()->get();

			$current_exp = $skill->join_exp;
			$new_exp = $current_exp + $reward->exp;
			$exp = $reward->exp;

			$player->set_join_field($skill, "exp", $new_exp);
		}

		return $exp;
	}

	public function calculate_currency($player_id, $reward_id){
		$reward = new Action_Reward($reward_id);
		$currency = 0;

		$rand = rand(0,100);
		
		if($rand <= $reward->currency_chance){
			$player = new Player($player_id);

			$current = $player->currency;
			$new = $current + $reward->currency;

			$currency = $reward->currency;

			$player->currency = $new;
			$player->save();
		}

		return $currency;
	}

	public function get_users_working_here($player_id, $action_id){
		$player = new Player();
		$data = array();

		$current_time = strtotime(date("Y-m-d"));
		foreach($player->where("action_id", $action_id)->where("action_end >", $current_time)->get() as $_player){
			if($_player->id != $player_id){
				$data[] = $_player->username;
			}
		}

		return $data;
	}
}
?>