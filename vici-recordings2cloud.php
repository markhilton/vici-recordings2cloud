<?php

/*
 * This script will migrate processed MP3 ViciDial recordings to Google Cloud Storage
 * in order to maintain disk space on main storage drive.
 *
 */

require_once('db.class.php');
require_once('vici-config.php');

list($hostname) = explode('.', gethostname());

define('IP_ADDRESS',  getHostByName(getHostName()));
define('INSTANCE_ID',                    $hostname); // name of this instance to create storage folder



if ( file_exists("/etc/astguiclient.conf") )
{
    $DBCagc = file("/etc/astguiclient.conf");

    foreach ($DBCagc as $DBCline)
    {
        $DBCline = preg_replace("/ |>|\n|\r|\t|\#.*|;.*/", "", $DBCline);

        if (preg_match("/^PATHlogs/", $DBCline))        { $PATHlogs       = $DBCline; $PATHlogs       = preg_replace("/.*=/","",$PATHlogs); }
        if (preg_match("/^PATHweb/", $DBCline))         { $WeBServeRRooT  = $DBCline; $WeBServeRRooT  = preg_replace("/.*=/","",$WeBServeRRooT); }
        if (preg_match("/^VARserver_ip/", $DBCline))    { $WEBserver_ip   = $DBCline; $WEBserver_ip   = preg_replace("/.*=/","",$WEBserver_ip); }
        if (preg_match("/^VARDB_server/", $DBCline))    { $VARDB_server   = $DBCline; $VARDB_server   = preg_replace("/.*=/","",$VARDB_server); }
        if (preg_match("/^VARDB_database/", $DBCline))  { $VARDB_database = $DBCline; $VARDB_database = preg_replace("/.*=/","",$VARDB_database); }
        if (preg_match("/^VARDB_user/", $DBCline))      { $VARDB_user     = $DBCline; $VARDB_user     = preg_replace("/.*=/","",$VARDB_user); }
        if (preg_match("/^VARDB_pass/", $DBCline))      { $VARDB_pass     = $DBCline; $VARDB_pass     = preg_replace("/.*=/","",$VARDB_pass); }
        if (preg_match("/^VARDB_port/", $DBCline))      { $VARDB_port     = $DBCline; $VARDB_port     = preg_replace("/.*=/","",$VARDB_port); }
    }
}

else
{
    # defaults for DB connection
    $VARDB_server   = 'localhost';
    $VARDB_port     = '3306';
    $VARDB_user     = 'cron';
    $VARDB_pass     = '1234';
    $VARDB_database = '1234';
    $WeBServeRRooT  = '/usr/local/apache2/htdocs';
}


$cc    = 1;
$db    = new db($VARDB_server, $VARDB_user, $VARDB_pass, $VARDB_database);
$count = $db->GetOne('SELECT COUNT(*) FROM recording_log WHERE location LIKE "http://'.IP_ADDRESS.'/%.mp3"');

printf("Recordings to process: %s\n\n", number_format($count, 0));

do {
    $recording = $db->GetRow('SELECT * FROM recording_log WHERE location LIKE "http://'.IP_ADDRESS.'/%.mp3" ORDER BY RAND() LIMIT 1');

    if (! isset($recording['filename'])) break;

    $file      = str_replace('http://'.IP_ADDRESS.'/RECORDINGS/MP3/', '', $recording['location']);
    $command   = sprintf('%s -qmo GSUtil:parallel_composite_upload_threshold=10M mv %s/%s ', 
                    GSUTIL_PATH, MP3_PATH, $file);
    $gcs       = sprintf('gs://%s/%s/%s/%s/%s/%s.mp3',
                    GCS_BUCKET, INSTANCE_ID,
                    date('Y', $recording['start_epoch']), // recording year
                    date('M', $recording['start_epoch']), // recording month
                    date('d', $recording['start_epoch']), // recording day
                    $recording['filename']
    );

    // remove all recording lasting less than X seconds
    $length = $recording['end_epoch'] - $recording['start_epoch'];

    // remove DB entry if file not found
    if (! file_exists(MP3_PATH.'/'.$file)) $length = 1;

    // remove special characters from file names
    $src = array('*', '#');
    $dst = array('',   '');

    $orig_file = str_replace($src, $dst, MP3_PATH.'/'.$file);
    $command   = str_replace($src, $dst, $command);
    $gcs       = str_replace($src, $dst, $gcs);
    
    if ($orig_file != MP3_PATH.'/'.$file) {
        $log = sprintf("RENAMING: %s -> %s\n", MP3_PATH.'/'.$file, $orig_file);
        file_put_contents('/var/log/vici2cloud.renamed.log', $log, FILE_APPEND);
        rename(MP3_PATH.'/'.$file, $orig_file);
        echo $log;
    }

    if ($length >= 0 and $length < DELETE_FILE_LESS_THAN)
    {
        $log = sprintf("[%s] %s/%s. REMOVING %s sec file [ %dkb ]: \n\t%s/%s\n",
            date("Y-m-d H:i:s"), $cc++, number_format($count, 0), $length, 
            file_exists($orig_file) ? filesize($orig_file) : 0, MP3_PATH, $file);

        echo $log;

        file_put_contents('/var/log/vici2cloud.deleted.log', $log, FILE_APPEND);

        if (file_exists($orig_file)) unlink($orig_file);

        if ($recording['filename'])
        {
            foreach (glob(ORIG_PATH.'/'.$recording['filename'].'*') as $filename)
            {
                unlink($filename);
                echo "\t".$filename."\n";
                file_put_contents('/var/log/vici2cloud.deleted.log', "\t".$filename."\n", FILE_APPEND);
            }
        }

        $db->execute('DELETE FROM recording_log WHERE recording_id=? LIMIT 1', ( $recording['recording_id'] ));

        continue;
    }

    // move file to GCS
    $log = sprintf("[%s] %s/%s. %s sec [ %dkb ]: MOVING\n\t%s %s : ", 
        date("Y-m-d H:i:s"), $cc++, number_format($count, 0), $length, 
        file_exists($orig_file) ? filesize($orig_file) : 0, $command, $gcs);

    exec($command.$gcs.' 2>&1', $return);

    $return = join("\n", $return); unset($return);

    exec(GSUTIL_PATH . ' acl set public-read ' . $gcs . ' 2>&1', $return);

    // update database with new recording location on successful gsutil mv
    if (strpos(join("\n", $return), 'Operation completed'))
    {
        if ($recording['filename'])
        {
            foreach (glob(ORIG_PATH.'/'.$recording['filename'].'*') as $filename)
            {
                unlink($filename);
            }
        }

        $db->update('recording_log', array( 'location' => str_replace('gs://', 'http://', $gcs) ), array( 'recording_id' => $recording['recording_id'] ));
        
        $log .= "OK\n";

        file_put_contents('/var/log/vici2cloud.moved.log', $log, FILE_APPEND);
    } 

    else {
        $log .= "FAIL\n";

        file_put_contents('/var/log/vici2cloud.failed.log', $log, FILE_APPEND);
    }

    echo $log; unset($return);

    // stop on any DB error
    if ($db->errors)
    {
        $log = "DB ERROR:\n" . print_r($db->errors, true) . "\n";
        
        file_put_contents('/var/log/vici2cloud.errors.log', $log, FILE_APPEND);

        die($log);
    }

} while ($cc < 1000);
