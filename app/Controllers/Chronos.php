<?php 
  namespace App\Controllers;

  use CodeIgniter\Controller;
  
  use CodeIgniter\HTTP\IncomingRequest;
  $request = service('request');

  use Faker\Factory;
  use App\Models\User_model;

  // Create a shared instance of the model
  $userModel = model('App\Models\UserModel');

  

  class Chronos extends BaseController
  {  
    public function index()
    {
      return view('welcome_message');
    }

    function get_time_jumps($current, $steps) {
      $time_jumps = array();
      // accept time spit out past and future steps in days
      foreach($steps as $days) {
        $label = ($days == 0)? 'now' : (($days < 0 )? abs($days).'_days_ago': abs($days).'_days_later');
        $skip_to = strtotime($days." days");
        $time_jumps[$label] = $skip_to;
      }      
      return $time_jumps;
    }

    function get_formats($timing) {
      helper('date');
      // split into timestamp, readable, and worded
      $info = new \stdClass();
      $info->timestamp = $timing;
      $info->readable = date('Y/m/d H:i', $timing);
      $info->dow = date('l', $timing);
      return $info;
    }
    
    function get_timing() {
      $time_steps = new \stdClass();
      $now = time();
      // steps from past to present
      $steps = array(-14, -7, -3, 0, 3, 7, 14);
      // 3 days ago, 1 week ago, 2 weeks ago, now , 3 days from now, 1 week from now, 2 weeks from now
      // get time jumps
      $timings = $this->get_time_jumps($now, $steps);
      // formats, unix timestamp, human readable, worded
      // get formats
      foreach ($timings as $key => $timing) {
        // get time formats and info
        $time_steps->{$key} = $this->get_formats($timing);        
      }
      // $time_steps->now = $now;
      return $time_steps;
    }

    function get_applications() {
      $this->User_model = new User_model();
      $result = array();
      $sql = "SELECT U.user_id ,U.email ,U.phone ,U.language ,U.verification_code ,U.started ,U.last_activity ,U.completed ,U.visit_count FROM users AS U WHERE U.started > 1646114400";
  
      try{
        $q = $this->db->query($sql);
        if($q !== FALSE && $q->num_rows() > 0){
          $result = $q->result_array();
        }
        return $result;
      } catch (Exeption $e) {
        log_message('error ',$e->getMessage());
        return;
      }
    }

    function name_gen(){
      // $generated = '';
      // require_once '../vendor/autoload.php';
      // $faker = Faker/Factory::create();

      // foreach(range(1,100) as $x) {
      //   $generated .= ($faker->word. '<br>');
      // }
      // return $generated;


      $users = $this->get_applications();
      return $reminder_list;
    }

    public function view($page = 'home')
    {
      if (! is_file(APPPATH . 'Views/chronos/' . $page . '.php')) {
        // Whoops, we don't have a page for that!
        throw new \CodeIgniter\Exceptions\PageNotFoundException($page);
      }

      // print_r($request);

      $data['title'] = ucfirst($page); // Capitalize the first letter
      // var_dump($this->get_timing());
      $data['timing'] = $this->get_timing();
      $data['result'] = '';

      echo view('templates/header', $data);
      echo view('chronos/' . $page, $data);
      echo view('templates/footer', $data);
    }

    public function insertByajax() {
      # to check all inputs are comming.
      // $timestamp
      if ($request->isAJAX()){
        $timestamp = $request->getVar('timestamp');
        print_r($timestamp);
        $result = date("Y-m-d h:i:sa", date($timestamp));
        // $ret ={'stats': 'We hit insert by ajax, now you just need to extract your data'});
        echo json_encode('We hit insert by ajax, now you just need to extract your data');
      }
      // $postData = $this->input->post();
      // // $postData = $this->input->post('timestamp');
      // $timestamp = $postData->timestamp;
      // // convert to date/time return readable string
      // $result = date("Y-m-d h:i:sa", date($timestamp));
      // $data['result'] = $result;
      // // print_r($result);
      // // echo $result;
      // // echo json_encode($result);
      // echo json_encode("We hit insert by ajax, now you just need to extract your data");
    }

    public function seed($page = 'seeder') {
      if (! is_file(APPPATH . 'Views/chronos/' . $page . '.php')) {
        // Whoops, we don't have a page for that!
        throw new \CodeIgniter\Exceptions\PageNotFoundException($page);
      }

      $data['title'] = ucfirst($page);
      $data['seed'] = $this->name_gen();

      echo view('templates/header', $data);
      echo view('chronos/' . $page, $data);
      echo view('templates/footer', $data);

    }

  }
?>