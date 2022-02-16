<?php

$article=array();
$owner_id=array();

// pretty sure this is mapping of specific users that will be import dependent
// owner_id (and zid) are only found here, so not sure this is even needed
$owner_id[22]=3;
$owner_id[6]=1;
$owner_id[8700]=13;
$owner_id[8839]=12;
$owner_id[26303]=14;
$owner_id[39537]=15;
$owner_id[43609]=16;
$owner_id[75779]=17;
$owner_id[87834]=18;
$owner_id[89282]=19;
$owner_id[116898]=4;

$zid=0;

//
// This is the rt server connection, going to attempt to run this on on charm
//$connection=mysqli_connect('192.168.75.30','fgaspar','*******','rt');
$connection=mysqli_connect('127.0.0.1','root','YDH','rt5');
if(! $connection){
	error_log(mysqli_error(),0);
}
mysqli_set_charset($connection,'utf-8');
	$sql="select Tickets.id,if(isnull(Users.EmailAddress),Users.Name,Users.EmailAddress) as Requestor,zm_tid,users.id as user_id from Tickets left join Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' left join GroupMembers on Groups.id=GroupMembers.GroupId left join Users on GroupMembers.MemberId=Users.id left join rt_zammad on Tickets.id=rt_zammad.rt_tid left join zammad.users on Users.EmailAddress=users.email where Status in('resolved','new','open') and Tickets.id>30000 group by Tickets.id order by Tickets.id";
	$result1=mysqli_query($connection,$sql);
	while($transaction=mysqli_fetch_assoc($result1)){
		if(! is_null($transaction['Requestor'])){
			$zm_tid=$transaction['zm_tid'];
			$user_id=$transaction['user_id'];
			$requestor="guess:" . $transaction['Requestor'];
			$data=array();
			$url="";
			$jdata="";
			$curl_action="PUT";
			$url="tickets/$zm_tid";
			$data['id']=$zm_tid;
			$data['customer_id']=$user_id;
			$jdata=json_encode($data);
			//exec("/usr/bin/timedatectl set-time '$created'");
			$base_url="http://zammad.geog.ucsb.edu/api/v1";
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]="$base_url/$url";
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			$options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
			$headers[]="Content-Type: application/json";
			error_log($jdata,0);
			$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;
			curl_setopt_array($hmrc,$options);

			$data=curl_exec($hmrc);
			if(! $data){
				echo "\nError: " . curl_error($hmrc) . "\n";
				error_log(curl_error($hmrc),0);
			}else{
				$res=json_decode($data,true);
				error_log("RESULT: $data",0);
			}
			curl_close($hmrc);
		}
	}
?>
