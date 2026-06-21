<?php
add_filter('cron_schedules', function($s){$s['pne_min']=['interval'=>60,'display'=>'1min'];return $s;});
add_action('init', function(){ if(!wp_next_scheduled('pne_send')) wp_schedule_event(time(),'pne_min','pne_send'); });

function pne_get_recipients(){
 $target=$_POST['target']??'all'; $list=[];
 if($target==='all') $list=wp_list_pluck(get_users(),'user_email');
 elseif($target==='role') $list=wp_list_pluck(get_users(['role'=>$_POST['role']]),'user_email');
 elseif($target==='import') $list=array_map('trim',explode("
",$_POST['import_list']));
 $list=array_filter($list,'is_email');
 return array_unique($list);
}

add_action('pne_send', function(){
 global $wpdb;
 $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}pne_queue WHERE status='pending' LIMIT 15");
 foreach($rows as $r){
  add_filter('wp_mail_from', fn()=>$r->from_email);
  add_filter('wp_mail_from_name', fn()=>$r->from_name);

  $msg=$wpdb->get_var("SELECT message FROM {$wpdb->prefix}pne_campaigns WHERE id=".$r->campaign_id);
  $subject=$wpdb->get_var("SELECT subject FROM {$wpdb->prefix}pne_campaigns WHERE id=".$r->campaign_id);

  $ok=wp_mail($r->email,$subject,$msg,['Content-Type:text/html']);

  remove_all_filters('wp_mail_from'); remove_all_filters('wp_mail_from_name');

  if($ok){
   $wpdb->update("{$wpdb->prefix}pne_queue",['status'=>'sent','sent_at'=>current_time('mysql')],['id'=>$r->id]);
  }else{
   $wpdb->update("{$wpdb->prefix}pne_queue",['status'=>'error','attempts'=>$r->attempts+1,'last_error'=>'SMTP error'],['id'=>$r->id]);
  }
 }
});

add_action('admin_menu', function(){add_menu_page('PNE','PNE','manage_options','pne','pne_ui');});

function pne_ui(){
 global $wpdb;
 if(isset($_POST['send'])){
  $subject=sanitize_text_field($_POST['subject']);
  $msg=$_POST['message'];
  $from=sanitize_email($_POST['from_email']);
  $name=sanitize_text_field($_POST['from_name']);

  $wpdb->insert("{$wpdb->prefix}pne_campaigns",['subject'=>$subject,'message'=>$msg,'created_at'=>current_time('mysql'),'status'=>'running']);
  $cid=$wpdb->insert_id;

  $list=pne_get_recipients();
  foreach($list as $e){
   $wpdb->insert("{$wpdb->prefix}pne_queue",['campaign_id'=>$cid,'email'=>$e,'from_email'=>$from,'from_name'=>$name]);
  }
 }

 $sent=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status='sent'");
 $pending=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status='pending'");
 $error=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status='error'");
?>
<style>.box{background:#fff;padding:15px;margin-top:15px;border-radius:6px}</style>
<h1>PNE V5.8</h1>

<div class='box'>
<h3>Stats</h3>
<canvas id='chart'></canvas>
<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
<script>
new Chart(document.getElementById('chart'),{
 type:'doughnut',
 data:{labels:['Sent','Pending','Error'],datasets:[{data:[<?php echo $sent;?>,<?php echo $pending;?>,<?php echo $error;?>]}]}
});
</script>
</div>

<form method='post'>
<div class='box'>
<h3>Campaign</h3>
<input name='subject' placeholder='Subject' style='width:100%'><br><br>
<textarea id='html' name='message' style='width:100%;height:200px'></textarea><br>
<button type='button' onclick='preview()'>Preview</button>
<div id='p'></div>
</div>

<div class='box'>
<h3>Sender</h3>
<input name='from_email' placeholder='email'><br>
<input name='from_name' placeholder='name'>
</div>

<div class='box'>
<h3>Recipients</h3>
<label><input type='radio' name='target' value='all' checked>All</label><br>
<label><input type='radio' name='target' value='role'>Role</label>
<select name='role'><option value='subscriber'>subscriber</option></select><br>
<label><input type='radio' name='target' value='import'>Raw list</label>
<textarea name='import_list'></textarea>
</div>

<button name='send'>Send</button>
</form>

<script>
function preview(){document.getElementById('p').innerHTML=document.getElementById('html').value;}
</script>
<?php }
