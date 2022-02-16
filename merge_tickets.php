<?php

$article=array();
$owner_id=array();
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
$connection=mysqli_connect('192.168.75.30','fgaspar','*******','rt');
if(! $connection){
	error_log(mysqli_error(),0);
}
mysqli_set_charset($connection,'utf-8');
$sql="select Transactions.Created,Tickets.id,Tickets.EffectiveId,rt_zammad.zm_tid as source,zmt.zm_tid as destination from Transactions left join Tickets on Transactions.ObjectId=Tickets.id and Transactions.ObjectType='RT::Ticket' left join rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid left join rt_zammad zmt on Tickets.EffectiveId=zmt.rt_tid where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and Tickets.id<>Tickets.EffectiveId order by Transactions.id";
//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue,zm_tid from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id left join rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and ObjectId=23 order by Transactions.id";
$result1=mysqli_query($connection,$sql);
while($transaction=mysqli_fetch_assoc($result1)){
	$created=$transaction['Created'];
	$source=$transaction['source'];
	$destination="43" . str_pad($transaction['EffectiveId'],8,'0',STR_PAD_LEFT);

	//$data=array();
	$url="";
	//$jdata="";
	$curl_action="GET";
	$url="ticket_merge/$source/$destination";
	//$data['id']=$destination;
	//$data['number']=$new_value;
	//$data['state_id']=5;
	//$jdata=json_encode($data);

	exec("/usr/bin/timedatectl set-time '$created'");

	$base_url="http://help.nixe.co.uk/api/v1";
	$hmrc=curl_init();
	$headers=array();
	$options=array();
	$options[CURLOPT_URL]="$base_url/$url";
	error_log($options[CURLOPT_URL],0);
	//$options[CURLOPT_POST]=true;
	$options[CURLOPT_RETURNTRANSFER]=true;
	$options[CURLOPT_VERBOSE]=true;
	$options[CURLOPT_HEADER]=false;
	$options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
	$headers[]="Content-Type: application/json";
	//error_log($jdata,0);
	//$options[CURLOPT_POSTFIELDS]=$jdata;
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
		curl_close($hmrc);
	}
}
?>
