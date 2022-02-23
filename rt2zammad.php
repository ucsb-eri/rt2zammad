<?php
require "./config.php";

$GLOBALS['opt'] = array();
$article=array();
$zid=0;


////////////////////////////////////////////////////////////////////////////////
// want to rework this setup a bit...
// I want to be able to export import by tickets, so lets get a list of tickets to start
// I think I also want to work this into an object...
////////////////////////////////////////////////////////////////////////////////
class rt2zammad {
	////////////////////////////////////////////////////////////////////////////
	function __construct(){
		$this->ticketIdClauses = array();
		$this->connection=mysqli_connect($GLOBALS['config']['rt_mysql_host'],$GLOBALS['config']['rt_mysql_user'],$GLOBALS['config']['rt_mysql_pass'],$GLOBALS['config']['rt_mysql_db']);

		if(! $this->connection){
			print("this->connection error\n");
			error_log(mysqli_error(),0);
		}
		mysqli_set_charset($this->connection,'utf8');

		// $sql = "DROP TABLE IF EXISTS rt_zammad;";
		// print("Dropping rt_zammad table: $sql\n");
		// $resultc=mysqli_query($this->connection,$sql);

        // this could be moved into the create-tickets sub
		$this->create_rt_zammad_table();
		$this->resolveWhichTickets();
	}
	////////////////////////////////////////////////////////////////////////////
	function create_rt_zammad_table(){
		$sql = "CREATE TABLE IF NOT EXISTS rt_zammad (rt_tid INTEGER, zm_tid INTEGER, UNIQUE(rt_tid,zm_tid));";
		print("Creating rt_zammad table: $sql\n");
		$resultc=mysqli_query($this->connection,$sql);
	}
	////////////////////////////////////////////////////////////////////////////
	function resolveWhichTickets(){
		if (isset($GLOBALS['opt']['tickets']) && $GLOBALS['opt']['tickets'] != ''){
			$ticketChunks = explode(",",$GLOBALS['opt']['tickets']);
			foreach($ticketChunks as $ticketChunk){
				dprint("ticketChunk: $ticketChunk");
				if ( strpos($ticketChunk,':') ){
					list($tRangeBeg,$tRangeEnd) = explode(":",$ticketChunk,2);
					$tRangeBegInt = intval($tRangeBeg);
					$tRangeEndInt = intval($tRangeEnd);
					if ( $tRangeBegInt > 0 && $tRangeEndInt > 0){
						$this->ticketIdClauses[]="AND Tickets.Id BETWEEN $tRangeBegInt AND $tRangeEndInt";
					}
					else {
						$errState = "Bad Range Specification ($ticketChunk)";
						dprint("$errState");
					}
				}
				elseif( strpos($ticketChunk,'>') !== False){
					list($blank,$ticketStr) = explode(">",$ticketChunk,2);
					$ticketInt = intval($ticketStr);
					$this->ticketIdClauses[] = "AND Tickets.Id > $ticketInt";
				}
				elseif( strpos($ticketChunk,'<') !== False){
					list($blank,$ticketStr) = explode("<",$ticketChunk,2);
					$ticketInt = intval($ticketStr);
					$this->ticketIdClauses[] = "AND Tickets.Id < $ticketInt";
				}
				else {
					$ticketInt = intval($ticketChunk);
					$this->ticketIdClauses[] = "AND Tickets.Id = $ticketInt";
				}
			}
		}
		else $this->ticketIdClauses[] = "";
	}
	////////////////////////////////////////////////////////////////////////////
	// Want to allow individual tickets (or ticketRanges) to be handled from one specification
	// So comma separated entries, ranges specified with a :, greater than specified with >000
	////////////////////////////////////////////////////////////////////////////
	function createTicketLoop(){
		foreach($this->ticketIdClauses as $ticketIdClause){
			dprint("Running select using the following ticketClause: $ticketIdClause");
			$sql = "SELECT Tickets.Id AS rt_tid,Tickets.Queue AS rt_tqueue,Tickets.Status AS rt_tstatus from Tickets WHERE ( Tickets.Status IN ('new','open','resolved') {$ticketIdClause} ) ORDER by Tickets.Id;";
			dprint("Running: $sql");
			$result=mysqli_query($this->connection,$sql);
			while($ticket=mysqli_fetch_assoc($result)){
				dprint("TicketId: " . $ticket['rt_tid'] . ", TicketStatus: " . $ticket['rt_tstatus'] . ", TicketQueue: " . $ticket['rt_tqueue']);
				$this->createSingleTicket($ticket['rt_tid']);
			}
		}
	}
	////////////////////////////////////////////////////////////////////////////
	// merge tickets, looks like all the required data is contained in the url
	////////////////////////////////////////////////////////////////////////////
	function merge_tickets(){
		$sql="SELECT Transactions.Created,Tickets.id,Tickets.EffectiveId,rt_zammad.zm_tid as source,zmt.zm_tid as destination from Transactions
		  LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id and Transactions.ObjectType='RT::Ticket'
		  LEFT JOIN rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid
		  LEFT JOIN rt_zammad zmt on Tickets.EffectiveId=zmt.rt_tid
		  where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and Tickets.id<>Tickets.EffectiveId order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue,zm_tid from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and ObjectId=23 order by Transactions.id";
		$result1=mysqli_query($this->connection,$sql);
		$cntr = 0;
		while($transaction=mysqli_fetch_assoc($result1)){
			$cntr++;
			$created=$transaction['Created'];
			$source=$transaction['source'];
			$destination="43" . str_pad($transaction['EffectiveId'],8,'0',STR_PAD_LEFT);

			$url="";
			$curl_action="GET";
			$url="ticket_merge/$source/$destination";

            dprint("created: $created, source: $source, destination: $destination, url: $url");
			if ( $source == '' || $destination == ''){
				print("ERROR: source ($source) or destination ($destination) is empty, cannot merge tickets.\n");
			}
            if ( $GLOBALS['opt']['test'] ) continue;

            // so this is an attempt to set the time of the system running this to the ticket creation time
			// would likely work if you are running on the system hosting zammad
			//exec("/usr/bin/timedatectl set-time '$created'");

			// $base_url=$GLOBALS['config']['base_url'];
			// $base_url="http://help.nixe.co.uk/api/v1";
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]= $GLOBALS['config']['base_url'] . "/$url";
			error_log($options[CURLOPT_URL],0);
			//$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			// $options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
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
		dprint("cntr: $cntr");
	}
	////////////////////////////////////////////////////////////////////////////
	// This needs to be run after rt_zammad has been fully populated by create_tickets
	// Also looks like this is expected to be run on a system with access to both RT and zammad databases
	////////////////////////////////////////////////////////////////////////////
	function createSingleTicket($ticketId = 0){
		$sql="SELECT Transactions.*,Users.EmailAddress,Requestor.EmailAddress AS Requestor,Tickets.id AS TicketId,Tickets.Subject,Tickets.Queue FROM Transactions
		  LEFT JOIN Users ON Transactions.Creator=Users.id
		  LEFT JOIN Tickets ON Transactions.ObjectId=Tickets.id
		  LEFT JOIN Groups ON Tickets.id=Groups.Instance AND Groups.Domain='RT::Ticket-Role' AND Groups.Name='Requestor'
		  LEFT JOIN GroupMembers ON Groups.id=GroupMembers.GroupId
		  LEFT JOIN Users Requestor ON GroupMembers.MemberId=Requestor.id
		  WHERE Tickets.Id = $ticketId
		    AND ObjectType='RT::Ticket'
		    AND Tickets.Status IN ('new','open','resolved')
			AND Transactions.Type IN ('Create','Status','Correspond','Comment','Set','AddLink')
		  ORDER BY Transactions.id;";
		// $sql="SELECT Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions
		//   LEFT JOIN Users on Transactions.Creator=Users.id
		//   LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id
		//   LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor'
		//   LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
		//   LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id
		//   where ObjectType='RT::Ticket'
		//     and Tickets.Status in ('new','open','resolved')
		// 	and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink')
		// 	and Transactions.id in(177292)
		//   order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between $tid_beg and $tid_end order by Transactions.id";
		// $sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.ObjectId in(select Tickets.id from Tickets LEFT JOIN zammad.tickets on concat('43',lpad(Tickets.id,8,'0'))=tickets.number where isnull(tickets.id) and Status<>'deleted' and Status<>'rejected' order by id) and Transactions.id<=463246 order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between 461841 and 463246 order by Transactions.id";
		print("############## Fetching transactions related to Ticket: $ticketId ##############\n");
		print("## Fetch SQL: $sql\n");
		$result1=mysqli_query($this->connection,$sql);
		while($transaction=mysqli_fetch_assoc($result1)){
			print("  ############# Transaction ({$transaction['Type']}) related to Ticket $ticketId #############\n");
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

			// If this is NOT a ticket creation, we want to fetch the matching zammad ticket id from the rt_zammad table
			if($transaction['Type']<>"Create"){
				$sql="SELECT zm_tid FROM rt_zammad WHERE rt_tid={$transaction['TicketId']}";
				//error_log($sql,0);
				$result3=mysqli_query($this->connection,$sql);
				$row=mysqli_fetch_assoc($result3);
				//error_log($row['zm_tid'],0);

				// This is an ugly hack to avoid some php warnings during test mode
				if ( $GLOBALS['opt']['test'] ){
					dprint("Setting up fake row data because of test mode");
					$row=array('zm_tid' => 0);
				}
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
			    			$new_value="GRIT";
			    		}else{
			    			$new_value="GRIT";
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
			$result2=mysqli_query($this->connection,$sql);
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
				    	$data['group']="GRIT";
				    	$data['customer_id']=$creator;
				    	$data['number']=$ticket_number;
				    	$data['queue']=$queue;
				    	$article=array();
						$article['created_at']="$created";
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
						$data['created_at']="$created";
				    	$jdata=json_encode($data);
				    	break;
				case "reply":
				    	$url="ticket_articles";
				    	$article=array();
						$article['created_at']="$created";
				    	foreach($content_type as $key => $ct){
				    		switch(substr($ct,0,9)){
				    		case "multipart":
				    			break;
							case "text/plai":
							case "text/plain":
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
						$article['created_at']="$created";
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
						$article['created_at']="$created";
				    	$jdata=json_encode($article);
				    	break;
				case "Subject":
				    	$curl_action="PUT";
				    	$url="tickets/{$row['zm_tid']}";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['title']=$new_value;
						$data['created_at']="$created";
				    	$jdata=json_encode($data);
				    	break;
				case "Owner":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['owner_id']=$GLOBALS['config']['owner_id'][$new_value];
						$data['created_at']="$created";
				    	$jdata=json_encode($data);
				    	break;
				case "Queue":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['queue']=$new_value;
						$data['created_at']="$created";
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
			// $base_url=$GLOBALS['config']['base_url'];
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]=$GLOBALS['config']['base_url'] . "/$url";
			dprint("CURLOPT_URL: " . $options[CURLOPT_URL]);
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
			$headers[]="Content-Type: application/json";
			//error_log($options[CURLOPT_URL],0);
			error_log("JSON DATA: ". $jdata,0);
			$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;

			if ($GLOBALS['opt']['test']){
				print("# TEST MODE: would otherwise run curl commands to zammad api:" . $options[CURLOPT_URL] . "\n");
			}
			else {
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
					error_log("CURL RESULT: $data",0);
					if(! isset($res['error'])){
						if($action=="new_ticket"){
							echo "Old ID: {$transaction['TicketId']}\nNew ID: {$res['id']} ({$res['number']})\n\n";
							$zm_tid=$res['id'];
							$sql="INSERT INTO rt_zammad VALUES({$transaction['TicketId']},$zm_tid)";
							dprint("rt_zammad insert sql: $sql");
							$save=mysqli_query($this->connection,$sql);
							if(! $save){
								error_log("Error saving to rt_zammad",0);
								error_log("$sql",0);
							}
						}

						/* commented code for trying to get date set
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
	function assignCustomerLoop(){
		foreach($this->ticketIdClauses as $ticketIdClause){
			dprint("Running select using the following ticketClause: $ticketIdClause");
			$sql = "SELECT Tickets.Id AS rt_tid,Tickets.Queue AS rt_tqueue,Tickets.Status AS rt_tstatus from Tickets WHERE ( Tickets.Status IN ('new','open','resolved') {$ticketIdClause} ) ORDER by Tickets.Id;";
			dprint("Running: $sql");
			$result=mysqli_query($this->connection,$sql);
			while($ticket=mysqli_fetch_assoc($result)){
				dprint("TicketId: " . $ticket['rt_tid'] . ", TicketStatus: " . $ticket['rt_tstatus'] . ", TicketQueue: " . $ticket['rt_tqueue']);
				$this->assignCustomerToTicket($ticket['rt_tid']);
			}
		}
	}
	////////////////////////////////////////////////////////////////////////////
	// This will go through the assignment of the RT Requestor to the corresponding
	// User in the zammad db
	// This requires having a zammad db with a fully stocked "users" table.
	////////////////////////////////////////////////////////////////////////////
	function assignCustomerToTicket($ticketId){
		// mysqli_set_charset($connection,'utf-8');
		$sql="SELECT Tickets.id,if(isnull(Users.EmailAddress),Users.Name,Users.EmailAddress) as Requestor,zm_tid,users.id as user_id from Tickets
		    LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor'
		    LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
		    LEFT JOIN Users on GroupMembers.MemberId=Users.id
		    LEFT JOIN rt_zammad on Tickets.id=rt_zammad.rt_tid
		    LEFT JOIN zammad.users on Users.EmailAddress=users.email
		  where Status in('resolved','new','open') and Tickets.id = $ticketId group by Tickets.id order by Tickets.id";
		$result1=mysqli_query($this->connection,$sql);
		while($transaction=mysqli_fetch_assoc($result1)){
			if(is_null($transaction['Requestor'])) continue;  // short circuit whole process if Requestor is null

			$data=array();
			$url="";
			$jdata="";
			$zm_tid=$transaction['zm_tid'];
			$user_id=$transaction['user_id'];
			$requestor="guess:" . $transaction['Requestor'];
			$curl_action="PUT";
			$url="tickets/$zm_tid";
			$data['id']=$zm_tid;
			$data['customer_id']=$user_id;
			$jdata=json_encode($data);

			dprint("zm_tid: $zm_tid, user_id: $user_id, ");

			if ($GLOBALS['opt']['test']) continue;
			//exec("/usr/bin/timedatectl set-time '$created'");
			// $base_url="http://zammad.geog.ucsb.edu/api/v1";
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			// $options[CURLOPT_URL]="$base_url/$url";
			$options[CURLOPT_URL]= $GLOBALS['config']['base_url'] . "/$url";
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			// $options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
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
			// }
		}
	}
	////////////////////////////////////////////////////////////////////////////
	function rt_users_new(){
		$cntrFields = array('ALL','UNIQ');
		$uniqRequestors = array();
		$sql="SELECT Tickets.id,Tickets.Status,if(isnull(Users.EmailAddress),Users.Name,Users.EmailAddress) as Requestor,zm_tid,users.id as user_id from Tickets
			LEFT JOIN Groups on Tickets.id=Groups.Instance AND Groups.Domain='RT::Ticket-Role' AND Groups.Name='Requestor'
			LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
			LEFT JOIN Users on GroupMembers.MemberId=Users.id
			LEFT JOIN rt_zammad on Tickets.id=rt_zammad.rt_tid
			-- LEFT JOIN zammad.users on Users.EmailAddress=users.email
		  where Status IN('resolved','new','open') GROUP BY Tickets.id ORDER BY Tickets.id";
		$result1=mysqli_query($this->connection,$sql);
		$cntr=array();
		foreach($cntrFields as $field) $cntr[$field] = 0;
		while($ticket=mysqli_fetch_assoc($result1)){
			$cntr['ALL']++;
			dprint("Ticket ID: {$ticket['id']} ({$ticket['Status']}), Requestor: {$ticket['Requestor']} ({$ticket['user_id']})");
			$uniqRequestors[$ticket['Requestor']] = $ticket['user_id'];
		}

		dprint("Unique Entries Follow");
		foreach($uniqRequestors as $email => $uid){
			print("$uid,$email\n");
		}

		$cntr['UNIQ'] = count($uniqRequestors);
		foreach($cntrFields as $field) dprint("$field count: {$cntr[$field]}");
	}
	////////////////////////////////////////////////////////////////////////////
	function rt_users(){
		$types = array('ALL','NULL','OK','FAIL','FILT','PARTIAL','CTYPE');
		$cntr = array();
		$sql="SELECT id,Name,RealName,NickName,Organization,EmailAddress from Users WHERE Name not like '%@qq.com' AND Name not like '%@lists.jobson.com' AND Name not like '%@sendgrid.net';";
		dprint("RT-Users SQL: $sql");
		$result=mysqli_query($this->connection,$sql);
		foreach($types as $type)  $cntr[$type]=0;
		while($user=mysqli_fetch_assoc($result)){
			$cntr['ALL']++;
			$clean='';
			if (is_null($user['RealName'])){
			    $type="NULL";
			}
			else {
				// three (maybe more?) situations Here: all bad chars, some bad chars, no bad chars
				// the clean setup just replaces bad chars with good, so
				$clean = cleanNonAsciiCharactersInString($user['RealName']);  // this does a substitution
				if     (strlen($clean) == 0)                         $type = 'FAIL';
				elseif (!ctype_print($clean))                        $type = 'CTYPE';
				elseif (strlen($clean) == strlen($user['RealName'])) $type = 'OK';
				else                                                 $type = 'PARTIAL';
				// $type = (ctype_print($user['RealName'])) ? "OK" : "FAIL";
			}
            // do some filtering?
			if ( $type == 'OK'){
				if( preg_match('/\?\?/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/FUCK EXPRESS/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/Constipation/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/webmaster@*ucsb.edu/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/Reverse Mortgage/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/Medigap.com/',$user['RealName'])) $type = 'FILT';
				if( preg_match('/@ucsbcollabsupport.zendesk.com/',$user['Name'])) $type = 'FILT';
				if( preg_match('/XXX/',$user['RealName'])) $type = 'FILT';

			}


            $cntr[$type]++;

			$str = sprintf("%-40s %-40s %-40s %-4s",$user['Name'],$user['RealName'],$clean,$type);
			dprint("$str");
		}
		foreach($types as $type){
			dprint("Type $type found: " . $cntr[$type]);
		}
	}
}
////////////////////////////////////////////////////////////////////////////////
function dprint($str){
	if ($GLOBALS['opt']['debug']) print("# DEBUG: $str\n");
}
////////////////////////////////////////////////////////////////////////////////
function cleanNonAsciiCharactersInString($orig_text) {
	// interesting thread on this topic
    // https://stackoverflow.com/questions/8781911/remove-non-ascii-characters-from-string
    $text = $orig_text;

    // Single letters
    $text = preg_replace("/[∂άαáàâãªä]/u",      "a", $text);
    $text = preg_replace("/[∆лДΛдАÁÀÂÃÄ]/u",     "A", $text);
    $text = preg_replace("/[ЂЪЬБъь]/u",           "b", $text);
    $text = preg_replace("/[βвВ]/u",            "B", $text);
    $text = preg_replace("/[çς©с]/u",            "c", $text);
    $text = preg_replace("/[ÇС]/u",              "C", $text);
    $text = preg_replace("/[δ]/u",             "d", $text);
    $text = preg_replace("/[éèêëέëèεе℮ёєэЭ]/u", "e", $text);
    $text = preg_replace("/[ÉÈÊË€ξЄ€Е∑]/u",     "E", $text);
    $text = preg_replace("/[₣]/u",               "F", $text);
    $text = preg_replace("/[НнЊњ]/u",           "H", $text);
    $text = preg_replace("/[ђћЋ]/u",            "h", $text);
    $text = preg_replace("/[ÍÌÎÏ]/u",           "I", $text);
    $text = preg_replace("/[íìîïιίϊі]/u",       "i", $text);
    $text = preg_replace("/[Јј]/u",             "j", $text);
    $text = preg_replace("/[ΚЌК]/u",            'K', $text);
    $text = preg_replace("/[ќк]/u",             'k', $text);
    $text = preg_replace("/[ℓ∟]/u",             'l', $text);
    $text = preg_replace("/[Мм]/u",             "M", $text);
    $text = preg_replace("/[ñηήηπⁿ]/u",            "n", $text);
    $text = preg_replace("/[Ñ∏пПИЙийΝЛ]/u",       "N", $text);
    $text = preg_replace("/[óòôõºöοФσόо]/u", "o", $text);
    $text = preg_replace("/[ÓÒÔÕÖθΩθОΩ]/u",     "O", $text);
    $text = preg_replace("/[ρφрРф]/u",          "p", $text);
    $text = preg_replace("/[®яЯ]/u",              "R", $text);
    $text = preg_replace("/[ГЃгѓ]/u",              "r", $text);
    $text = preg_replace("/[Ѕ]/u",              "S", $text);
    $text = preg_replace("/[ѕ]/u",              "s", $text);
    $text = preg_replace("/[Тт]/u",              "T", $text);
    $text = preg_replace("/[τ†‡]/u",              "t", $text);
    $text = preg_replace("/[úùûüџμΰµυϋύ]/u",     "u", $text);
    $text = preg_replace("/[√]/u",               "v", $text);
    $text = preg_replace("/[ÚÙÛÜЏЦц]/u",         "U", $text);
    $text = preg_replace("/[Ψψωώẅẃẁщш]/u",      "w", $text);
    $text = preg_replace("/[ẀẄẂШЩ]/u",          "W", $text);
    $text = preg_replace("/[ΧχЖХж]/u",          "x", $text);
    $text = preg_replace("/[ỲΫ¥]/u",           "Y", $text);
    $text = preg_replace("/[ỳγўЎУуч]/u",       "y", $text);
    $text = preg_replace("/[ζ]/u",              "Z", $text);

    // Punctuation
    $text = preg_replace("/[‚‚]/u", ",", $text);
    $text = preg_replace("/[`‛′’‘]/u", "'", $text);
    $text = preg_replace("/[″“”«»„]/u", '"', $text);
    $text = preg_replace("/[—–―−–‾⌐─↔→←]/u", '-', $text);
    $text = preg_replace("/[  ]/u", ' ', $text);

    $text = str_replace("…", "...", $text);
    $text = str_replace("≠", "!=", $text);
    $text = str_replace("≤", "<=", $text);
    $text = str_replace("≥", ">=", $text);
    $text = preg_replace("/[‗≈≡]/u", "=", $text);


    // Exciting combinations
    $text = str_replace("ыЫ", "bl", $text);
    $text = str_replace("℅", "c/o", $text);
    $text = str_replace("₧", "Pts", $text);
    $text = str_replace("™", "tm", $text);
    $text = str_replace("№", "No", $text);
    $text = str_replace("Ч", "4", $text);
    $text = str_replace("‰", "%", $text);
    $text = preg_replace("/[∙•]/u", "*", $text);
    $text = str_replace("‹", "<", $text);
    $text = str_replace("›", ">", $text);
    $text = str_replace("‼", "!!", $text);
    $text = str_replace("⁄", "/", $text);
    $text = str_replace("∕", "/", $text);
    $text = str_replace("⅞", "7/8", $text);
    $text = str_replace("⅝", "5/8", $text);
    $text = str_replace("⅜", "3/8", $text);
    $text = str_replace("⅛", "1/8", $text);
    $text = preg_replace("/[‰]/u", "%", $text);
    $text = preg_replace("/[Љљ]/u", "Ab", $text);
    $text = preg_replace("/[Юю]/u", "IO", $text);
    $text = preg_replace("/[ﬁﬂ]/u", "fi", $text);
    $text = preg_replace("/[зЗ]/u", "3", $text);
    $text = str_replace("£", "(pounds)", $text);
    $text = str_replace("₤", "(lira)", $text);
    $text = preg_replace("/[‰]/u", "%", $text);
    $text = preg_replace("/[↨↕↓↑│]/u", "|", $text);
    $text = preg_replace("/[∞∩∫⌂⌠⌡]/u", "", $text);


    //2) Translation CP1252.
    $trans = get_html_translation_table(HTML_ENTITIES);
    $trans['f'] = '&fnof;';    // Latin Small Letter F With Hook
    $trans['-'] = array(
        '&hellip;',     // Horizontal Ellipsis
        '&tilde;',      // Small Tilde
        '&ndash;'       // Dash
        );
    $trans["+"] = '&dagger;';    // Dagger
    $trans['#'] = '&Dagger;';    // Double Dagger
    $trans['M'] = '&permil;';    // Per Mille Sign
    $trans['S'] = '&Scaron;';    // Latin Capital Letter S With Caron
    $trans['OE'] = '&OElig;';    // Latin Capital Ligature OE
    $trans["'"] = array(
        '&lsquo;',  // Left Single Quotation Mark
        '&rsquo;',  // Right Single Quotation Mark
        '&rsaquo;', // Single Right-Pointing Angle Quotation Mark
        '&sbquo;',  // Single Low-9 Quotation Mark
        '&circ;',   // Modifier Letter Circumflex Accent
        '&lsaquo;'  // Single Left-Pointing Angle Quotation Mark
        );

    $trans['"'] = array(
        '&ldquo;',  // Left Double Quotation Mark
        '&rdquo;',  // Right Double Quotation Mark
        '&bdquo;',  // Double Low-9 Quotation Mark
        );

    $trans['*'] = '&bull;';    // Bullet
    $trans['n'] = '&ndash;';    // En Dash
    $trans['m'] = '&mdash;';    // Em Dash
    $trans['tm'] = '&trade;';    // Trade Mark Sign
    $trans['s'] = '&scaron;';    // Latin Small Letter S With Caron
    $trans['oe'] = '&oelig;';    // Latin Small Ligature OE
    $trans['Y'] = '&Yuml;';    // Latin Capital Letter Y With Diaeresis
    $trans['euro'] = '&euro;';    // euro currency symbol
    ksort($trans);

    foreach ($trans as $k => $v) {
        $text = str_replace($v, $k, $text);
    }

    // 3) remove <p>, <br/> ...
    $text = strip_tags($text);

    // 4) &amp; => & &quot; => '
    $text = html_entity_decode($text);


    // transliterate
    // if (function_exists('iconv')) {
    // $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // }

    // remove non ascii characters
    // $text =  preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $text);

    return $text;
}
////////////////////////////////////////////////////////////////////////////////
// Main
////////////////////////////////////////////////////////////////////////////////
$GLOBALS['opt']['verbose'] = False;
$GLOBALS['opt']['debug'] = False;
$GLOBALS['opt']['test'] = False;
$GLOBALS['opt']['ticket'] = '';

// Command line processing
$exe = array_shift($argv);
// while( $argfull = array_shift($argv)){
while( count($argv) > 0 && substr($argv[0],0,1) == "-" ){
    if ( strpos($argv[0],'=') ) list($arg,$val) = explode("=",$argv[0],2);
	else                        $arg=$argv[0];

    switch($arg){
        case "--verbose" :
	        $GLOBALS['opt']['verbose'] = True;
            // if( isset($val)) $hello = $val;
            break;
        case "--debug" :
	        $GLOBALS['opt']['debug'] = True;
            // $set = array_shift($argv);
            break;
		case "--tickets" :
			if( isset($val)) $GLOBALS['opt']['tickets'] = $val;
			break;
		case "--test" :
			$GLOBALS['opt']['test'] = True;
			break;
        default:
            break;
    }
	array_shift($argv);
}

$subcommand = array_shift($argv);
dprint("exe: $exe, subcommand: $subcommand");
switch($subcommand){
	case "create-tickets" :
	    $rt2za = new rt2zammad();
	    $rt2za->createTicketLoop();
	    break;
	case "merge-tickets" :
		$rt2za = new rt2zammad();
		$rt2za->merge_tickets();
		break;
	case "assign-customer" :
		$rt2za = new rt2zammad();
		$rt2za->assignCustomerLoop();
		break;
	case "rt-users-old" :
		$rt2za = new rt2zammad();
		$rt2za->rt_users();
		break;
	case "rt-users" :
		$rt2za = new rt2zammad();
		$rt2za->rt_users_new();
		break;
    case "fake-sub" :
	    dprint("Fake Subcommand for testing");
	    break;
	default:
	    dprint("Default Case - not sure what I want to do yet :-)");
	    break;
}

/**
Per thread at: https://community.zammad.org/t/importing-tickets-and-articles-using-the-api/5775
  looks like we may be able to set the time using "created_at" field for transactions/articles and ticket creation during import
  Specify 'created_at' in json and set import_mode to true in rails console...
  In rails console we need to execute:  Settings.set('import_mode', true)

  So something like: zammad run rails r 'Setting.set('import_mode',true)'
  Assuming you would put back to false once import was done



Per thread at: https://community.zammad.org/t/reset-database-to-start-from-zero/326
  Looks like we can clear the db with the following commands executed in rails console:
  zammad run rails r 'Ticket.destroy_all'
  zammad run rails r 'OnlineNotification.destroy_all'
  zammad run rails r 'ActivityStream.destroy_all'
  zammad run rails r 'RecentView.destroy_all'
  zammad run rails r 'History.destroy_all'
**/
?>
