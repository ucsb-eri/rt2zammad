<?php
require "./config.php";

$article=array();
$zid=0;

$connection=mysqli_connect($GLOBALS['config']['rt_mysql_host'],$GLOBALS['config']['rt_mysql_user'],$GLOBALS['config']['rt_mysql_pass'],$GLOBALS['config']['rt_mysql_db']);

if(! $connection){
	print("connection error\n");
	error_log(mysqli_error(),0);
}
mysqli_set_charset($connection,'utf8');

print("Creating rt_zammad table: $sql\n");
$sql = "CREATE TABLE IF NOT EXISTS rt_zammad (rt_tid integer, zm_tid integer);";
$resultc=mysqli_query($connection,$sql);

// want to rework this setup a bit...
// I want to be able to export import by tickets, so lets get a list of tickets to start
// I think I also want to work this into an object...
class rt2zammad {
	function __construct(){

	}
	function original(){
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id left join Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' left join GroupMembers on Groups.id=GroupMembers.GroupId left join Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id in(177292) order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id left join Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' left join GroupMembers on Groups.id=GroupMembers.GroupId left join Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between $tid_beg and $tid_end order by Transactions.id";
		$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id left join Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' left join GroupMembers on Groups.id=GroupMembers.GroupId left join Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id left join Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' left join GroupMembers on Groups.id=GroupMembers.GroupId left join Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.ObjectId in(select Tickets.id from Tickets left join zammad.tickets on concat('43',lpad(Tickets.id,8,'0'))=tickets.number where isnull(tickets.id) and Status<>'deleted' and Status<>'rejected' order by id) and Transactions.id<=463246 order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions left join Users on Transactions.Creator=Users.id left join Tickets on Transactions.ObjectId=Tickets.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between 461841 and 463246 order by Transactions.id";
		print("Fetching SQL: $sql\n");
		$result1=mysqli_query($connection,$sql);
		while($transaction=mysqli_fetch_assoc($result1)){
			print("Fetched One\n");
			$created=$transaction['Created'];
			$ticket_number="43" . str_pad($transaction['TicketId'],8,'0',STR_PAD_LEFT);
			$subject=$transaction['Subject'];
			if($transaction['Queue']==10){
				$queue="Software Development::Change Requests";
			} else {
				$queue="Support::Miscellaneous";
			}
			$from="";
			$to="";
			$cc="";
			$creator="guess:" . $transaction['Requestor'];
			$time=$transaction['TimeTaken'];
			if($transaction['Type']<>"Create"){
				$sql="select zm_tid from rt_zammad where rt_tid={$transaction['TicketId']}";
				//error_log($sql,0);
				$result3=mysqli_query($connection,$sql);
				$row=mysqli_fetch_assoc($result3);
				//error_log($row['zm_tid'],0);
			}
			switch($transaction['Type']){
			    case 'Create':
			    	$action="new_ticket";
			    	break;
			    case 'Status':
			    	$action="change_status";
			    	$new_value=$transaction['NewValue'];
			    	if($new_value=="resolved"){
			    		$new_value="closed";
			    	}
			    	break;
			    case 'Correspond':
			    	$action="reply";
			    	break;
			    case 'Comment':
			    	$action="comment";
			    	break;
			    case 'Set':
			    	$action=$transaction['Field'];//Queue TimeWorked Subject Owner
			    	$new_value=$transaction['NewValue'];
			    	if($new_value=="resolved"){
			    		$new_value="closed";
			    	}
			    	if($transaction['Field']=="Queue"){
			    		if($transaction['NewValue']==10){
			    			$new_value="Software Development::Change Requests";
			    		}else{
			    			$new_value="Support::Miscellaneous";
			    		}
			    	}
			    	break;
			    case 'AddLink':
			    	$action="merge";
			    	$link=explode('/',$transaction['NewValue']);
			    	$new_value="43" . str_pad($link[count($link)-1],8,'0',STR_PAD_LEFT);
			    	break;
			}
			$sql="select * from Attachments where TransactionId={$transaction['id']} order by id";
			//error_log($sql,0);
			$result2=mysqli_query($connection,$sql);
			$content=array();
			$content_type=array();
			$file_name=array();
			$i=0;
			$html=false;
			while($attachment=mysqli_fetch_assoc($result2)){
				//error_log("CT: " . $attachment['ContentType'],0);
				//error_log(substr($attachment['ContentType'],0,9),0);
				switch(substr($attachment['ContentType'],0,9)){
				    case "multipart":
				    	if($attachment['Parent']==0){
				    		$subject=$attachment['Subject'];
				    		//error_log($attachment['Headers'],0);
				    		$lh=explode("\n",$attachment['Headers']);
				    		foreach($lh as $line){
				    			//error_log("HEADER: $line",0);
				    			if(substr($line,0,5)=="From:"){
				    				$from=substr($line,6);
				    				//error_log($from,0);
				    			}
				    			if(substr($line,0,3)=="To:"){
				    				$to=substr($line,4);
				    				//error_log($to,0);
				    			}
				    			if(substr($line,0,3)=="CC:"){
				    				$cc=substr($line,4);
				    				//error_log($cc,0);
				    			}
				    		}
				    	}
				    	break;
				    case "text/plai":
				    case "text/html":
				    	if($attachment['ContentType']=="text/html"){
				    		$html=true;
				    		//error_log('HTML',0);
				    	}
				    	$content[$i]=$attachment['Content'];
				    	$content_type[$i]=$attachment['ContentType'];
				    	break;
				    default:
				    	if(! is_null($attachment['Filename'])){
				    		$file_name[$i]=$attachment['Filename'];
				    		$content[$i]=$attachment['Content'];
				    		$content_type[$i]=$attachment['ContentType'];
				    	}
				}
				$i++;
	       }
			$data=array();
			$url="";
			$jdata="";
			$curl_action="POST";
			switch($action){
				    case "new_ticket":
				    	$url="tickets";
				    	if(is_null($subject) or $subject==""){
				    		$subject="Support Request";
				    	}
				    	error_log("SUBJECT:|$subject|",0);
				    	$data['title']=addslashes($subject);
				    	$data['group']="Users";
				    	$data['customer_id']=$creator;
				    	$data['number']=$ticket_number;
				    	$data['queue']=$queue;
				    	$article=array();
				    	foreach($content_type as $key => $ct){
				    		//error_log($ct,0);
				    		switch(substr($ct,0,9)){
				    		case "multipart":
				    			break;
				    		case "text/plai":
				    		case "text/html":
				    			if(($html and $ct=="text/html") or !$html){
				    				$data['article']['subject']=$subject;
				    				$data['article']['from']=$from;
				    				$data['article']['to']=$to;
				    				$data['article']['cc']=$cc;
				    				$data['article']['content_type']=$ct;
				    				$data['article']['type']="email";
				    				$data['article']['internal']=false;
				    				$data['article']['body']=$content[$key];
				    			}
				    			break;
				    		default:
				    			if(! is_null($file_name[$key])){
				    				if(! isset($data['article']['attachments'])){
				    					$data['article']['attachments']=array();
				    				}
				    				$att=array();
				    				$att['filename']=$file_name[$key];
				    				$att['mime-type']=$ct;
				    				$att['data']=base64_encode($content[$key]);
				    				array_push($data['article']['attachments'],$att);
				    			}
				    			break;
				    		}
				    	}
				    	if(! isset($data['article'])){
				    		$data['article']['subject']=$subject;
				    		$data['article']['type']="note";
				    		$data['article']['internal']=false;
				    		$data['article']['body']=$subject;
				    	}
				    	$jdata=json_encode($data);
				    	//var_dump($data);
				    	break;
				    case "change_status":
				    	$curl_action="PUT";
				    	$url="tickets/{$row['zm_tid']}";
				    	$data['id']=$row['zm_tid'];
				    	$data['state']="$new_value";
				    	$jdata=json_encode($data);
				    	break;
				    case "reply":
				    	$url="ticket_articles";
				    	$article=array();
				    	foreach($content_type as $key => $ct){
				    		switch(substr($ct,0,9)){
				    		case "multipart":
				    			break;
				    		case "text/plai":
				    		case "text/html":
				    			if(($html and $ct=="text/html") or !$html){
				    				$article['ticket_id']=$row['zm_tid'];
				    				$article['subject']=$subject;
				    				$article['from']=$from;
				    				$article['to']=$to;
				    				$article['cc']=$cc;
				    				$article['body']=$content[$key];
				    				$article['content_type']=$ct;
				    				$article['type']="email";
				    				$article['internal']=false;
				    				$article['time_unit']=$transaction['TimeTaken'];
				    			}
				    			break;
				    		default:
				    			if(! is_null($file_name[$key])){
				    				if(! isset($article['attachments'])){
				    					$article['attachments']=array();
				    				}
				    				$att=array();
				    				$att['filename']=$file_name[$key];
				    				$att['mime-type']=$ct;
				    				$att['data']=base64_encode($content[$key]);
				    				array_push($article['attachments'],$att);
				    			}
				    			break;
				    		}
				    	}
				    	$jdata=json_encode($article);
				    	break;
				    case "comment":
				    	$url="ticket_articles";
				    	$article=array();
				    	foreach($content_type as $key => $ct){
				    		switch(substr($ct,0,9)){
				    		case "multipart":
				    			break;
				    		case "text/plai":
				    		case "text/html":
				    			if(($html and $ct=="text/html") or !$html){
				    				$article['ticket_id']=$row['zm_tid'];
				    				$article['subject']=$subject;
				    				$article['body']=$content[$key];
				    				$article['content_type']=$ct;
				    				$article['type']="note";
				    				$article['internal']=true;
				    				$article['time_unit']=$transaction['TimeTaken'];
				    			}
				    			break;
				    		default:
				    			if(! is_null($file_name[$key])){
				    				if(! isset($article['attachments'])){
				    					$article['attachments']=array();
				    				}
				    				if(! isset($article['ticket_id'])){
				    					$article['ticket_id']=$row['zm_tid'];
				    					$article['subject']=$file_name[$key];
				    					$article['body']="";
				    					$article['content_type']=$ct;
				    					$article['type']="note";
				    					$article['internal']=true;
				    					$article['time_unit']=$transaction['TimeTaken'];
				    				}
				    				$att=array();
				    				$att['filename']=$file_name[$key];
				    				$att['mime-type']=$ct;
				    				$att['data']=base64_encode($content[$key]);
				    				array_push($article['attachments'],$att);
				    			}
				    			break;
				    		}
				    	}
				    	$jdata=json_encode($article);
				    	break;
				    case "TimeWorked":
				    	$url="ticket_articles";
				    	$article=array();
				    	$article['ticket_id']=$row['zm_tid'];
				    	$article['subject']="Time Worked";
				    	$article['body']="";
				    	$article['type']="note";
				    	$article['internal']=true;
				    	$article['time_unit']=$transaction['TimeTaken'];
				    	$jdata=json_encode($article);
				    	break;
				    case "Subject":
				    	$curl_action="PUT";
				    	$url="tickets/{$row['zm_tid']}";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['title']=$new_value;
				    	$jdata=json_encode($data);
				    	break;
				    case "Owner":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['owner_id']=$GLOBALS['config']['owner_id'][$new_value];
				    	$jdata=json_encode($data);
				    	break;
				    case "Queue":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['queue']=$new_value;
				    	$jdata=json_encode($data);
				    	break;
				    case "merge":
				    	$curl_action="GET";
				    	$url="ticket_merge/{$row['zm_tid']}/$new_value";
				    	//$data=array();
				    	//$data['id']=$row['zm_tid'];
				    	//$data['number']=$new_value;
				    	//$jdata=json_encode($data);
				    	$jdata="";
				    	break;
				}
			//error_log($jdata,0);
				//execute transaction
				//exec("/usr/bin/timedatectl set-time '$created'");
				//$base_url="http://localhost:9200/api/v1";
			$base_url=$GLOBALS['config']['base_url'];
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]="$base_url/$url";
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
			$headers[]="Content-Type: application/json";
			//error_log($options[CURLOPT_URL],0);
			error_log($jdata,0);
			$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;
			curl_setopt_array($hmrc,$options);
			$data=curl_exec($hmrc);
			if(! $data){
				echo "\nError: " . curl_error($hmrc) . "\n";
				error_log(curl_error($hmrc),0);
			}
			elseif( isset($data['id']) && is_null($data['id'])){
				echo "\nError (id is null)\n";
			}
			else {
				//save IDs
				//echo "\n\n$data\n\n";
				$res=json_decode($data,true);
				error_log("RESULT: $data",0);
				if(! isset($res['error'])){
					if($action=="new_ticket"){
						echo "Old ID: {$transaction['TicketId']}\nNew ID: {$res['id']} ({$res['number']})\n\n";
						$zm_tid=$res['id'];
						$sql="insert into rt_zammad values({$transaction['TicketId']},$zm_tid)";
						$save=mysqli_query($connection,$sql);
						if(! $save){
							error_log("Error saving to rt_zammad",0);
							error_log("$sql",0);
						}
					}

		            /*
					// This section was commented out in original code
					if($url=="ticket_articles"){
						$sql="update ticket_articles set created_at='$created',updated_at='$created' where id={$res['id']}";
						$zm=mysqli_query($zammad,$sql);
					}
					if($url=="tickets"){
						$sql="update tickets set created_at='$created',updated_at='$created' where id={$res['id']}";
						$zm=mysqli_query($zammad,$sql);
						if($new_value=="closed"){
							$sql="update tickets set close_at='$resolved',updated_at='$created' where id={$res['id']}";
							$zm=mysqli_query($zammad,$sql);
						}
					}
					if(substr($url,0,8)=="tickets/"){
						$sql="update tickets set updated_at='$created' where id={$res['id']}";
						$zm=mysqli_query($zammad,$sql);
						if($new_value=="closed"){
							$sql="update tickets set close_at='$resolved',updated_at='$created' where id={$res['id']}";
							$zm=mysqli_query($zammad,$sql);
						}
					}
		            */
				}
				else{
					echo "\n\n{$res['error']}\n\n$jdata\n\n";
				}
			}
			curl_close($hmrc);
		}
	}
}

rt2za = new rt2zammad();
rt2za->original();
//}
?>
