<?php
/* OAuth Login With Facebook
 * Copyright 2012 Manuel Lemos
 * Copyright 2014 Subin Siby
 * Login to Open with Facebook
 * Thank you Manuel Lemos
*/

/* Include the files */
require "$docRoot/inc/oauth/http.php";
require "$docRoot/inc/oauth/oauth_client.php";
require "$docRoot/inc/oauth/database_oauth_client.php";
require "$docRoot/inc/oauth/mysqli_oauth_client.php";


/**
 * We add the session variable containing the URL that should be redirected to after logging in
 */
$_SESSION['continue'] = isset($_SESSION['continue']) ? $_SESSION['continue'] : "";

/* $_GET['c'] have the URL that should be redirected to after oauth logging in */
$_GET['c'] = isset($_GET['c']) ? urldecode($_GET['c']) : "";

if($_GET['c'] != ''){
  /* Or the URL that was sent */
  $hostParts = parse_url($_GET['c']);

  if(!isset($hostParts['host']) || (isset($hostParts['host']) && $hostParts['host'] == CLEAN_HOST)){
    $_SESSION['continue'] = Open::URL($_GET['c']);
  }else{
    $_SESSION['continue'] = Open::URL("home");
  }
}else if($_SESSION['continue'] == ""){
  /**
   * The default Redirect URL open.dev/home
   */
  $_SESSION['continue'] = Open::URL("home");
}
$location = $_SESSION['continue'];

/* We make an array of Database Details */
$databaseDetails = unserialize(DATABASE);

/**
 * The PHP OAuth Library requires some special items in array, so we add that
 */
$databaseDetails["password"] = $databaseDetails["pass"];
$databaseDetails["socket"] = "/var/run/mysqld/mysqld.sock";

$client = new mysqli_oauth_client_class;
$client->database = $databaseDetails;
$client->server = 'Facebook';
$client->offline = true;
$client->debug = false;
$client->debug_http = false;
$client->redirect_uri = Open::URL('/oauth/login_with_facebook');
$client->client_id = $GLOBALS['cfg']['facebook']['app_id'];
$client->client_secret = $GLOBALS['cfg']['facebook']['app_secret'];
$client->scope = 'user_about_me,email,user_birthday,user_location,publish_actions';

if(($success = $client->Initialize())){
   if(($success = $client->Process())){
      if(strlen($client->authorization_error)){
        $client->error = $client->authorization_error;
        $success = false;
      }elseif(strlen($client->access_token)){
        $success = $client->CallAPI('https://graph.facebook.com/me', 'GET', array(), array('FailOnAccessError' => true), $user);

        if($success){
          $email = $user->email;
          $name = $user->name;
          $gender = $user->gender;
          
          if( \Fr\LS::userExists($email) ){
            /**
             * Since user exists, we log him/her in
             */
            $who = \Fr\LS::login($email, "", false, false);
            
            $sql = $OP->dbh->prepare("UPDATE `oauth_session` SET `user` = ? WHERE `server` = ? AND `access_token` = ?");
            $sql->execute(array($who, "Facebook", $client->access_token));
            
            unset($_SESSION['continue']);
            \Fr\LS::login($email, "");
            $OP->redirect($location);
          }else{
            /**
             * Make it DD/MM/YYYY format
             */
            $birthday = date('Y-m-d', strtotime($user->birthday));
            $image = "https://graph.facebook.com/". $user->id ."/picture?width=200&height=200";
            
            /* An array containing user details that will made in to JSON */
            $userArray = array(
              "joined" => date("Y-m-d H:i:s"),
              "gen" => $gender, /* gen = gender (male/female) */
              "birth" => $birthday,
              "img" => $image /* img = image */
            );
            $json = json_encode($userArray);
            \Fr\LS::register($email, "", array(
              "name" => $name,
              "email" => $email,
              "udata" => $json,
              "seen" => ""
            ));
          
            /**
             * Login the user
             */
            $id = \Fr\LS::login($email, "", false, false);
            $client->SetUser($id);
            
            \Fr\LS::login($email, "");
            
            unset($_SESSION['continue']);
            $OP->redirect($location);
          }
        }
      }
   }
   $success = $client->Finalize($success);
}
if($client->exit){
   $OP->ser("Something Happened", "<a href='".$client->redirect_uri."'>Try Again</a>");
}
if(!$success){
?>
 <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
 <html>
  <head>
   <title>Error</title>
  </head>
  <body>
   <h1>OAuth client error</h1>
   <pre>Error: <?php echo HtmlSpecialChars($client->error); ?></pre>
  </body>
 </html>
<?php }?>
