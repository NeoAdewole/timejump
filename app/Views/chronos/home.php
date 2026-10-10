<h4 class='my-5'>Display Time now and standard past to present times</h4>
<h4 class='my-5'>Start of year - Jan 1 at 00:00 : <?php echo strtotime("2022-1-1") ?></h4>
<h4 class='my-5'>March 1 at 00:00 : <?php echo strtotime("2022-3-1") ?></h4>
<h4 class='my-5'>March 1 at 00:00 : <?php echo date('Y/m/d H:i', strtotime("2022-3-1")) ?></h4>

<?php //(1641016800) ?>

<table class="table">
  <thead>
    <tr class="table-active">
      <th scope="col">Jump</th>
      <th scope="col">Day of Week</th>
      <th scope="col">Readable Date</th>
      <th scope="col">Timestamp</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($timing as $key => $time) { ?>
    <tr class='<?php echo ($key == "now")? "bg-success text-white" : "" ?>'>
      <th><?php echo $key ?></th>
      <td><?php echo $time->dow ?></td>
      <td><?php echo $time->readable ?></td>
      <td><?php echo $time->timestamp ?></td>
    </tr>
    <?php } ?>
  </tbody>
</table>

<div class="">
  <div class="section my-3">
    <h3 class="mb-0">Enter timestamp to view date</h3>
    <span class="small font-italic">Will convert an entered timestamp to date and time</span>
  </div>
  <form action="" method="post" class="converter">
    <div class="form-group row">
      <div class="col-6">
        <label for="date">Timestamp</label>
        <input id="timestamp" class="form-control" type="number" name="timestamp" />
      </div>
      <div class="col-6">
        <label for="date">Date/Time</label>
        <input id="result" class="form-control" type="datetime-local" value='<?php echo $result; ?>'  name="result" />
      </div>
    </div>
    <div class="form-group">
      <button id="convert" type="submit" class="btn btn-primary">Convert</button>
      <button id="revert" type="submit" class="btn btn-secondary">Revert</button>
      <!-- <button type="submit" class="btn btn-danger">Reset</button> -->
    </div>
    </form>

  <div class="section my-3">
    <h3 class="mb-0">Use a custom time as reference</h3>
    <span class="small font-italic">Will change "now" to your selected date and time</span>
  </div>

  <form action="POST" class="jump">
    <div class="form-group row">
      <div class="col">
        <label for="date">Date</label>
        <input class="form-control" type="date" name="date" />
      </div>
      <div class="col">
        <label for="time">Time</label>
        <input class="form-control" type="time" name="time" />
      </div>
    </div>
    <div class="form-group">
      <button type="submit" class="btn btn-primary">Jump</button>
      <button type="submit" class="btn btn-danger">Reset</button>
    </div>
  </form>
</div>

<!-- <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script> -->

<script type="text/javascript">
  $(function() {
    console.log("Well!, I worked!");
    $('#convert').click(function(e) {
      e.preventDefault();
      //get input data as a array
      console.log ("Convert was clicked!" + "<?php echo base_url(); ?>reminders/insertByajax");
      console.log("<?php echo base_url(); ?>/chronos/insertByajax");
      var timestamp = $("#timestamp").val();
      
      var post_data = {
        'timestamp': timestamp
      };
      console.log(post_data);
      res_dt =  $("#result")
      // res_dt = $("#result").html(post_data.timestamp);
      res_dt.val(`${timestamp}`);
      // var result = <?php // date("Y-m-d h:i:sa", date($timestamp))?>;
      var result = new Date(timestamp*1000);
      res_dt.val(result.toLocaleString());

      // $.ajax({
      //   type: "POST",
      //   url: "<?php //echo base_url(); ?>/chronos/insertByajax/",
      //   method: 'post',
      //   data: post_data,
      //   dataType: 'json',
      //   headers: {
      //     "Content-Type": "application/json",
      //     "X-Requested-With": "XMLHttpRequest"
      //   },
      //   success: function(response) {
      //     // return success response to the id='result' position
      //     $(".converter").append("<h5>Should Display results</h5>");
      //     console.log("Success:" + $response);
      //     $("#result").html(response);
      //     alert('request successful');
      //   },
      //   error: function(err){
      //     console.log(err.responseText);
      //   },
      //   fail: function(xhr, textStatus, errorThrown){
      //     console.log(xhr);
      //     console.log("Request failed! \n");
      //     // console.log(errorThrown);
      //     alert('request failed');
      //   }
      // });
    });

    $('#revert').click(function(e) {
      e.preventDefault();
      //get input data as a array
      console.log ("Convert was clicked!" + "<?php echo base_url(); ?>reminders/insertByajax");
      console.log("<?php echo base_url(); ?>/chronos/insertByajax");

      var result = $("#result").val();
      console.log(result);
      var timestamp = new Date(result.replace(' ', 'T'));
      // .getTime();
      console.log(timestamp);
      console.log(timestamp.getTime());

      res_stamp =  $("#timestamp");
      res_stamp.val(timestamp.getTime()/1000);

      // var timestamp = $("#timestamp").val();
      
      // console.log(result);
      // res_dt =  $("#result")
      // // res_dt = $("#result").html(post_data.timestamp);
      // res_dt.val(`${timestamp}`);
      // // var result = <?php // date("Y-m-d h:i:sa", date($timestamp))?>;
      // var timestamp = new Date(timestamp*1000);
      
      // var result = new Date(timestamp*1000);
      // res_dt.val(result.toLocaleString());
    });
  });
</script>