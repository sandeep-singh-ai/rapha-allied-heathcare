<?php
declare(strict_types=1);
// Rapha Allied Health - Microsoft 365 OAuth2 SMTP intake endpoint.
// Requires PHP 8.1+, curl, openssl and composer install on this folder.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $code,string $message): never {http_response_code($code);echo json_encode(['ok'=>$code===200,'message'=>$message]);exit;}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {header('Allow: POST');respond(405,'Method not allowed');}
$site = rtrim((string)getenv('RAPHA_SITE_ORIGIN'), '/');
if ($site === '') respond(503,'Service is not configured');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin && !hash_equals($site,$origin)) respond(403,'Origin rejected');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16*1024*1024) respond(413,'Submission too large');
if (!empty($_POST['website'])) respond(200,'Thank you. Your request has been received.'); // honeypot
$type=(string)($_POST['form_type']??'');
$titles=['contact'=>'General enquiry','booking'=>'Appointment request','referral'=>'Patient referral','symptoms'=>'Symptom checker enquiry'];
if (!isset($titles[$type]))respond(422,'Unknown form');
$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));
$summary=trim((string)($_POST['summary']??''));
if ($name==='' || mb_strlen($name)>150 || mb_strlen($summary)>15000 || $summary==='')respond(422,'Invalid submission');
if ($email!=='' && (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254))respond(422,'Invalid email');
if ($type==='contact' && $email==='')respond(422,'Email required');
// Basic per-IP burst limit; supplement with edge rate limiting/CAPTCHA in production.
$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$key=hash('sha256',$ip);$dir=sys_get_temp_dir().'/rapha-mail-limits';
if (!is_dir($dir))@mkdir($dir,0700,true);
$f=$dir.'/'.$key;$fp=@fopen($f,'c+');
if($fp){flock($fp,LOCK_EX);$s=stream_get_contents($fp);$v=json_decode($s?:'{}',true);$now=time();$count=($now-(int)($v['since']??0)<3600)?(int)($v['count']??0):0;$since=$count?(int)$v['since']:$now;
 if($count>=8){flock($fp,LOCK_UN);fclose($fp);respond(429,'Too many requests. Try again later.');}
 ftruncate($fp,0);rewind($fp);fwrite($fp,json_encode(['since'=>$since,'count'=>$count+1]));fflush($fp);flock($fp,LOCK_UN);fclose($fp);}
require __DIR__.'/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use PHPMailer\PHPMailer\Exception;
final class MicrosoftOAuthToken implements OAuthTokenProvider {
 public function __construct(private string $tenant,private string $client,private string $secret,private string $sender) {}
 public function getOauth64():string {
  $url='https://login.microsoftonline.com/'.rawurlencode($this->tenant).'/oauth2/v2.0/token';
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$this->client,'client_secret'=>$this->secret,'scope'=>'https://outlook.office365.com/.default','grant_type'=>'client_credentials']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);
  $body=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  $token=is_string($body)?json_decode($body,true):null;
  if($http!==200||empty($token['access_token']))throw new \RuntimeException('Microsoft OAuth token request failed');
  return base64_encode('user='.$this->sender."\x01auth=Bearer ".$token['access_token']."\x01\x01");
 }
}
function requiredEnv(string $k):string {$v=trim((string)getenv($k));if($v==='')throw new \RuntimeException('Missing server config: '.$k);return $v;}
try{
 $sender=requiredEnv('RAPHA_SENDER');$recipient=requiredEnv('RAPHA_RECIPIENT');
 $oauth=new MicrosoftOAuthToken(requiredEnv('MS365_TENANT_ID'),requiredEnv('MS365_CLIENT_ID'),requiredEnv('MS365_CLIENT_SECRET'),$sender);
 $m=new PHPMailer(true);$m->isSMTP();$m->Host='smtp.office365.com';$m->Port=587;$m->SMTPAuth=true;$m->AuthType='XOAUTH2';$m->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS;$m->setOAuth($oauth);$m->Timeout=20;
 $m->CharSet='UTF-8';$m->setFrom($sender,'Rapha Allied Health Website');$m->addAddress($recipient);
 if($email!=='')$m->addReplyTo($email,preg_replace('/[\r\n]/',' ',$name));
 $m->Subject='[Rapha Website] '.$titles[$type].' - '.preg_replace('/[\r\n]/',' ',mb_substr($name,0,90));
 $m->isHTML(false);$m->Body="Form: {$titles[$type]}\nName: {$name}\nEmail: ".($email?:'Not supplied')."\n\n{$summary}\n\nSubmitted via Rapha website.";
 if($type==='referral' && !empty($_FILES['attachments'])){
  $files=$_FILES['attachments'];$n=is_array($files['name'])?count($files['name']):0;
  if($n>5)respond(422,'Maximum 5 attachments');$total=0;
  $allowed=['application/pdf','image/jpeg','image/png','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
  $extensions=['pdf','jpg','jpeg','png','doc','docx'];
  $finfo=new finfo(FILEINFO_MIME_TYPE);
  for($i=0;$i<$n;$i++){
   if($files['error'][$i]===UPLOAD_ERR_NO_FILE)continue;
   if($files['error'][$i]!==UPLOAD_ERR_OK)respond(422,'Attachment upload failed');
   $size=(int)$files['size'][$i];$total+=$size;
   $path=$files['tmp_name'][$i];$base=basename((string)$files['name'][$i]);$ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));
   if($size>10*1024*1024||$total>15*1024*1024||!in_array($ext,$extensions,true)||!in_array($finfo->file($path),$allowed,true)||!is_uploaded_file($path))respond(422,'Unsupported attachment or file too large');
   $m->addAttachment($path,$base);
  }
 }
 $m->send();respond(200,'Your request has been submitted successfully.');
}catch(\Throwable $e){error_log('Rapha mail send error: '.$e->getMessage());respond(502,'Email service unavailable. Please contact us directly if your enquiry is urgent.');}
